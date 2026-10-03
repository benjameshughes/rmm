//! Asks the RMM server for admin-queued commands, runs them, and reports back.
//!
//! The agent only ever calls out to its own enrolled server with its device
//! key; nothing listens on the machine. Every request outcome feeds the shared
//! `KeyHealth`, so a revoked key ends the session like heartbeats and metrics.

use crate::command_runner::{self, ExecutionResult, RunLimits, ScriptType};
use crate::config::Config;
use crate::keep_awake::KeepAwake;
use crate::metrics::{KeyHealth, RequestOutcome};
use crate::power::{GatePolicy, PowerController};
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

/// The server's command queue as the drain loop sees it, so the drain logic
/// can be tested without a server or Windows.
trait CommandQueue {
    type Command;
    type Awake;

    /// The next queued command; None when the queue is empty or the server
    /// could not be asked.
    async fn next(&mut self) -> Option<Self::Command>;

    /// Ask the machine to stay awake until the returned guard is dropped.
    fn keep_awake(&mut self) -> Self::Awake;

    /// Run one command and report it. False when the drain should stop (the
    /// server refused to start it).
    async fn run(&mut self, command: Self::Command) -> bool;
}

/// Run queued commands back to back until the queue is empty.
///
/// One keep-awake guard covers the whole drain: it is taken as soon as the
/// first command arrives and only released once the server has nothing more
/// (or sleep, shutdown or cancellation stops the drain). Releasing it between
/// commands let Windows drop back into standby before the next one was
/// fetched. Each round holds the power command slot, so a sleep request waits
/// for the running command and no further one starts. Returns how many
/// commands ran.
async fn drain_queue<Q: CommandQueue>(queue: &mut Q, power: &Arc<PowerController>) -> usize {
    let mut awake: Option<Q::Awake> = None;
    let mut ran = 0;

    loop {
        let Some(_slot) = power.try_begin_command() else {
            break;
        };

        let Some(command) = queue.next().await else {
            break;
        };

        // Sleep started while asking: leave the command queued on the server.
        if !power.state().allows_new_commands() {
            info!("Not starting a queued command: the machine is going to sleep");
            break;
        }

        if awake.is_none() {
            awake = Some(queue.keep_awake());
        }

        if !queue.run(command).await {
            break;
        }
        ran += 1;
    }

    drop(awake);
    ran
}

/// The real queue: this device's pending commands on the server.
struct ServerQueue<'a> {
    client: &'a CommandClient,
    api_key: &'a str,
    key_health: &'a KeyHealth,
}

impl CommandQueue for ServerQueue<'_> {
    type Command = PendingCommand;
    type Awake = Option<KeepAwake>;

    async fn next(&mut self) -> Option<PendingCommand> {
        self.client.fetch_pending(self.api_key, self.key_health).await
    }

    fn keep_awake(&mut self) -> Option<KeepAwake> {
        KeepAwake::acquire("BenJH RMM is running queued commands")
    }

    async fn run(&mut self, command: PendingCommand) -> bool {
        self.client
            .run_command(self.api_key, command, self.key_health)
            .await
    }
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

    /// Poll for commands until the session token is cancelled. Each poll
    /// drains the whole queue. Stops taking new commands as soon as the
    /// machine starts going to sleep and polls at once when it wakes.
    pub async fn start_command_loop(
        &self,
        api_key: String,
        cancellation_token: CancellationToken,
        key_health: Arc<KeyHealth>,
        power: Arc<PowerController>,
    ) {
        info!(
            "Starting command loop (interval: {}s)",
            self.config.command_poll_interval
        );

        let mut power_gate = power.gate(GatePolicy::Commands);
        let interval = Duration::from_secs(self.config.command_poll_interval);

        while power_gate.tick(interval, &cancellation_token).await {
            tokio::select! {
                _ = cancellation_token.cancelled() => break,
                _ = self.poll_once(&api_key, &key_health, &power) => {}
            }
        }

        info!("Command loop stopped");
    }

    async fn poll_once(&self, api_key: &str, key_health: &KeyHealth, power: &Arc<PowerController>) {
        let mut queue = ServerQueue {
            client: self,
            api_key,
            key_health,
        };

        let ran = drain_queue(&mut queue, power).await;
        if ran > 1 {
            info!("Drained {} queued commands", ran);
        }
    }

    /// Report the command started, run it and report the result. False when
    /// the server refused to start it.
    async fn run_command(&self, api_key: &str, command: PendingCommand, key_health: &KeyHealth) -> bool {
        let script_type = ScriptType::parse(&command.script_type);
        info!("Received command {} ({})", command.id, script_type.name());

        if !self.report_started(api_key, command.id, key_health).await {
            return false;
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
        true
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
    use crate::power_state::{PowerNotice, PowerReason, PowerSignal, PowerState};
    use std::collections::VecDeque;
    use std::sync::Mutex;

    #[derive(Debug, Clone, PartialEq, Eq)]
    enum Step {
        Awake,
        Ran(u64),
        Refused(u64),
        Released,
    }

    type Log = Arc<Mutex<Vec<Step>>>;

    struct FakeAwake(Log);

    impl Drop for FakeAwake {
        fn drop(&mut self) {
            self.0.lock().unwrap().push(Step::Released);
        }
    }

    /// A queue of command ids that can raise a power signal mid-command.
    struct FakeQueue {
        queued: VecDeque<u64>,
        log: Log,
        power: Arc<PowerController>,
        signal_while_running: Option<(u64, PowerSignal)>,
        signal_while_fetching: Option<PowerSignal>,
        refuse: Option<u64>,
    }

    impl FakeQueue {
        fn new(power: &Arc<PowerController>, queued: &[u64]) -> Self {
            Self {
                queued: queued.iter().copied().collect(),
                log: Arc::new(Mutex::new(Vec::new())),
                power: power.clone(),
                signal_while_running: None,
                signal_while_fetching: None,
                refuse: None,
            }
        }

        fn log(&self) -> Vec<Step> {
            self.log.lock().unwrap().clone()
        }
    }

    impl CommandQueue for FakeQueue {
        type Command = u64;
        type Awake = FakeAwake;

        async fn next(&mut self) -> Option<u64> {
            if let Some(signal) = self.signal_while_fetching.take() {
                self.power.signal(signal);
            }
            self.queued.pop_front()
        }

        fn keep_awake(&mut self) -> FakeAwake {
            self.log.lock().unwrap().push(Step::Awake);
            FakeAwake(self.log.clone())
        }

        async fn run(&mut self, id: u64) -> bool {
            if self.refuse == Some(id) {
                self.log.lock().unwrap().push(Step::Refused(id));
                return false;
            }
            if let Some((when, signal)) = self.signal_while_running {
                if when == id {
                    self.power.signal(signal);
                }
            }
            self.log.lock().unwrap().push(Step::Ran(id));
            true
        }
    }

    #[tokio::test]
    async fn holds_one_guard_across_consecutive_commands() {
        let (power, _notices) = PowerController::new();
        let mut queue = FakeQueue::new(&power, &[32, 33, 34]);

        assert_eq!(drain_queue(&mut queue, &power).await, 3);

        assert_eq!(
            queue.log(),
            vec![
                Step::Awake,
                Step::Ran(32),
                Step::Ran(33),
                Step::Ran(34),
                Step::Released
            ]
        );
    }

    #[tokio::test]
    async fn takes_no_guard_when_nothing_is_queued() {
        let (power, _notices) = PowerController::new();
        let mut queue = FakeQueue::new(&power, &[]);

        assert_eq!(drain_queue(&mut queue, &power).await, 0);
        assert!(queue.log().is_empty());
    }

    #[tokio::test]
    async fn releases_the_guard_once_the_queue_is_empty() {
        let (power, _notices) = PowerController::new();
        let mut queue = FakeQueue::new(&power, &[7]);

        drain_queue(&mut queue, &power).await;

        assert_eq!(queue.log(), vec![Step::Awake, Step::Ran(7), Step::Released]);
        // The slot is free again for the next poll.
        assert!(power.try_begin_command().is_some());
    }

    #[tokio::test]
    async fn suspend_finishes_the_running_command_then_stops() {
        let (power, mut notices) = PowerController::new();
        let mut queue = FakeQueue::new(&power, &[32, 34]);
        queue.signal_while_running = Some((32, PowerSignal::Suspend));

        assert_eq!(drain_queue(&mut queue, &power).await, 1);

        assert_eq!(queue.log(), vec![Step::Awake, Step::Ran(32), Step::Released]);
        assert_eq!(queue.queued, VecDeque::from([34]));
        assert_eq!(power.state(), PowerState::Asleep);
        assert_eq!(
            notices.try_recv().unwrap(),
            PowerNotice::powering_off(PowerReason::Sleep)
        );
    }

    #[tokio::test]
    async fn shutdown_stops_the_drain() {
        let (power, mut notices) = PowerController::new();
        let mut queue = FakeQueue::new(&power, &[32, 34]);
        queue.signal_while_running = Some((32, PowerSignal::Shutdown));

        assert_eq!(drain_queue(&mut queue, &power).await, 1);

        assert_eq!(queue.log(), vec![Step::Awake, Step::Ran(32), Step::Released]);
        assert_eq!(queue.queued, VecDeque::from([34]));
        assert_eq!(
            notices.try_recv().unwrap(),
            PowerNotice::powering_off(PowerReason::Shutdown)
        );
    }

    #[tokio::test]
    async fn suspend_while_fetching_leaves_the_command_queued() {
        let (power, mut notices) = PowerController::new();
        let mut queue = FakeQueue::new(&power, &[32]);
        queue.signal_while_fetching = Some(PowerSignal::Suspend);

        assert_eq!(drain_queue(&mut queue, &power).await, 0);

        assert!(queue.log().is_empty());
        assert_eq!(power.state(), PowerState::Asleep);
        assert_eq!(
            notices.try_recv().unwrap(),
            PowerNotice::powering_off(PowerReason::Sleep)
        );
    }

    #[tokio::test]
    async fn does_not_drain_while_asleep() {
        let (power, _notices) = PowerController::new();
        power.signal(PowerSignal::Suspend);
        let mut queue = FakeQueue::new(&power, &[32]);

        assert_eq!(drain_queue(&mut queue, &power).await, 0);
        assert_eq!(queue.queued, VecDeque::from([32]));
    }

    #[tokio::test]
    async fn a_refused_start_ends_the_drain() {
        let (power, _notices) = PowerController::new();
        let mut queue = FakeQueue::new(&power, &[32, 33]);
        queue.refuse = Some(32);

        assert_eq!(drain_queue(&mut queue, &power).await, 0);

        assert_eq!(
            queue.log(),
            vec![Step::Awake, Step::Refused(32), Step::Released]
        );
    }

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
