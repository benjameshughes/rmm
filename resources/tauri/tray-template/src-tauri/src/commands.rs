//! Asks the RMM server for admin-queued commands, runs them, and reports back.
//!
//! The agent only ever calls out to its own enrolled server with its device
//! key; nothing listens on the machine. Every request outcome feeds the shared
//! `KeyHealth`, so a revoked key ends the session like heartbeats and metrics.

use crate::command_runner::{self, ExecutionResult, RunLimits, ScriptType};
use crate::config::Config;
use crate::metrics::{KeyHealth, RequestOutcome};
use anyhow::{Context, Result};
use serde::{Deserialize, Serialize};
use std::collections::HashMap;
use std::sync::Arc;
use std::time::Duration;
use tokio_util::sync::CancellationToken;
use tracing::{debug, info, warn};

#[derive(Debug, Deserialize)]
struct PendingResponse {
    command: Option<PendingCommand>,
}

#[derive(Debug, Deserialize)]
struct PendingCommand {
    id: u64,
    script_content: String,
    script_type: String,
    timeout_seconds: Option<u64>,
    /// Servers older than script parameters leave this out entirely.
    #[serde(default)]
    parameters: HashMap<String, String>,
}

#[derive(Debug, Serialize)]
struct ResultPayload<'a> {
    exit_code: i32,
    output: &'a str,
    error_message: Option<&'a str>,
    timed_out: bool,
}

pub struct CommandClient {
    config: Config,
    client: reqwest::Client,
}

impl CommandClient {
    pub fn new(config: Config) -> Result<Self> {
        let client = reqwest::Client::builder()
            .timeout(Duration::from_secs(config.command_request_timeout))
            .build()
            .context("Failed to create HTTP client for commands")?;

        Ok(Self { config, client })
    }

    /// Poll for commands until the session token is cancelled.
    pub async fn start_command_loop(
        &self,
        api_key: String,
        cancellation_token: CancellationToken,
        key_health: Arc<KeyHealth>,
    ) {
        info!(
            "Starting command loop (interval: {}s)",
            self.config.command_poll_interval
        );

        loop {
            tokio::select! {
                _ = cancellation_token.cancelled() => break,
                _ = tokio::time::sleep(Duration::from_secs(self.config.command_poll_interval)) => {
                    tokio::select! {
                        _ = cancellation_token.cancelled() => break,
                        _ = self.poll_once(&api_key, &key_health) => {}
                    }
                }
            }
        }

        info!("Command loop stopped");
    }

    async fn poll_once(&self, api_key: &str, key_health: &KeyHealth) {
        let Some(command) = self.fetch_pending(api_key, key_health).await else {
            return;
        };

        let script_type = ScriptType::parse(&command.script_type);
        info!("Received command {} ({})", command.id, script_type.name());

        if !self.report_started(api_key, command.id, key_health).await {
            return;
        }

        let result = command_runner::run_script(
            &script_type,
            &command.script_content,
            &command.parameters,
            command.id,
            &self.run_limits(command.timeout_seconds),
        )
        .await;

        info!(
            "Command {} finished: exit {}, timed out {}, {} ms, {} chars of output",
            command.id,
            result.exit_code,
            result.timed_out,
            result.duration.as_millis(),
            result.output.chars().count()
        );

        self.report_result(api_key, command.id, &result, key_health)
            .await;
    }

    fn run_limits(&self, requested_timeout: Option<u64>) -> RunLimits {
        RunLimits {
            timeout: command_runner::clamp_timeout(
                requested_timeout.unwrap_or(self.config.command_max_timeout),
                self.config.command_min_timeout,
                self.config.command_max_timeout,
            ),
            output_limit: self.config.command_output_limit,
            drain_grace: Duration::from_secs(self.config.command_drain_grace),
            work_dir: self.config.data_dir.join("work"),
        }
    }

    async fn fetch_pending(&self, api_key: &str, key_health: &KeyHealth) -> Option<PendingCommand> {
        let url = format!("{}/api/commands/pending", self.config.base_url);
        let response = self
            .client
            .get(&url)
            .header("X-Agent-Key", api_key)
            .header("Accept", "application/json")
            .send()
            .await;

        let response = match response {
            Ok(response) => response,
            Err(e) => {
                key_health.record(RequestOutcome::Inconclusive);
                debug!("Command poll network error: {}", e);
                return None;
            }
        };

        let outcome = RequestOutcome::from_status(response.status());
        key_health.record(outcome);

        if outcome != RequestOutcome::Success {
            warn!("Command poll failed ({})", response.status().as_u16());
            return None;
        }

        match response.json::<PendingResponse>().await {
            Ok(body) => body.command,
            Err(e) => {
                warn!("Command poll returned an unreadable body: {}", e);
                None
            }
        }
    }

    /// Returns false when the server refuses (cancelled, finished, not ours),
    /// in which case the command must not run.
    async fn report_started(&self, api_key: &str, command_id: u64, key_health: &KeyHealth) -> bool {
        let url = format!(
            "{}/api/commands/{}/started",
            self.config.base_url, command_id
        );
        let response = self
            .client
            .post(&url)
            .header("X-Agent-Key", api_key)
            .header("Accept", "application/json")
            .send()
            .await;

        let status = match response {
            Ok(response) => response.status(),
            Err(e) => {
                key_health.record(RequestOutcome::Inconclusive);
                warn!("Could not confirm start of command {}: {}", command_id, e);
                return false;
            }
        };

        key_health.record(RequestOutcome::from_status(status));

        if !status.is_success() {
            warn!(
                "Server refused to start command {} ({}), skipping it",
                command_id,
                status.as_u16()
            );
        }

        status.is_success()
    }

    async fn report_result(
        &self,
        api_key: &str,
        command_id: u64,
        result: &ExecutionResult,
        key_health: &KeyHealth,
    ) {
        let url = format!(
            "{}/api/commands/{}/result",
            self.config.base_url, command_id
        );
        let payload = ResultPayload {
            exit_code: result.exit_code,
            output: &result.output,
            error_message: result.error_message.as_deref(),
            timed_out: result.timed_out,
        };

        let response = self
            .client
            .post(&url)
            .header("X-Agent-Key", api_key)
            .header("Accept", "application/json")
            .json(&payload)
            .send()
            .await;

        let status = match response {
            Ok(response) => response.status(),
            Err(e) => {
                key_health.record(RequestOutcome::Inconclusive);
                warn!("Could not report result of command {}: {}", command_id, e);
                return;
            }
        };

        key_health.record(RequestOutcome::from_status(status));

        if !status.is_success() {
            warn!(
                "Server rejected the result of command {} ({})",
                command_id,
                status.as_u16()
            );
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parses_an_empty_poll() {
        let body: PendingResponse = serde_json::from_str(r#"{"command":null}"#).unwrap();
        assert!(body.command.is_none());
    }

    #[test]
    fn parses_a_pending_command() {
        let body: PendingResponse = serde_json::from_str(
            r#"{"command":{"id":42,"script_content":"Get-Date","script_type":"powershell","timeout_seconds":300}}"#,
        )
        .unwrap();

        let command = body.command.unwrap();
        assert_eq!(command.id, 42);
        assert_eq!(command.script_type, "powershell");
        assert_eq!(command.timeout_seconds, Some(300));
        assert!(command.parameters.is_empty());
    }

    #[test]
    fn parses_pending_command_parameters() {
        let body: PendingResponse = serde_json::from_str(
            r#"{"command":{"id":43,"script_content":"winget","script_type":"powershell","timeout_seconds":1800,"parameters":{"PackageId":"Mozilla.Firefox"}}}"#,
        )
        .unwrap();

        let command = body.command.unwrap();
        assert_eq!(
            command.parameters.get("PackageId").map(String::as_str),
            Some("Mozilla.Firefox")
        );
    }

    #[test]
    fn parses_an_empty_parameters_object() {
        let body: PendingResponse = serde_json::from_str(
            r#"{"command":{"id":44,"script_content":"Get-Date","script_type":"powershell","timeout_seconds":60,"parameters":{}}}"#,
        )
        .unwrap();

        assert!(body.command.unwrap().parameters.is_empty());
    }

    #[test]
    fn serialises_the_result_payload_the_server_expects() {
        let payload = ResultPayload {
            exit_code: -1,
            output: "partial",
            error_message: Some("Command timed out after 10 seconds"),
            timed_out: true,
        };

        let json = serde_json::to_value(&payload).unwrap();
        assert_eq!(json["exit_code"], -1);
        assert_eq!(json["output"], "partial");
        assert_eq!(json["error_message"], "Command timed out after 10 seconds");
        assert_eq!(json["timed_out"], true);
    }

    #[test]
    fn clamps_the_requested_timeout_with_config_limits() {
        let client = CommandClient::new(Config::default()).unwrap();

        assert_eq!(client.run_limits(Some(1)).timeout, Duration::from_secs(10));
        assert_eq!(
            client.run_limits(Some(300)).timeout,
            Duration::from_secs(300)
        );
        assert_eq!(client.run_limits(None).timeout, Duration::from_secs(7200));
        assert!(client.run_limits(None).work_dir.ends_with("work"));
    }
}
