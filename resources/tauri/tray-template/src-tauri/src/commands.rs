//! Asks the RMM server for admin-queued commands, runs them, and reports back.
//!
//! The agent only ever calls out to its own enrolled server with its device
//! key; nothing listens on the machine. Every request outcome feeds the shared
//! `KeyHealth`, so a revoked key ends the session like heartbeats and metrics.
//!
//! Windows only: other builds never start the command loop (see
//! `Agent::spawn_command_loop`), and the loop itself refuses to run there.

// Only Windows builds (and tests) start the command loop.
#![cfg_attr(not(any(windows, test)), allow(dead_code))]

use crate::command_progress::{self, Posted, ProgressSink};
use crate::command_runner::{self, ExecutionResult, RunLimits, ScriptType};
use crate::config::{Config, PROGRESS_POST_INTERVAL};
use crate::keep_awake::KeepAwake;
use crate::metrics::{KeyHealth, RequestOutcome};
use crate::power::PowerController;
use crate::startup_grace::{Milestone, StartupProgress};
use anyhow::{Context, Result};
use serde::{Deserialize, Serialize};
use serde_json::Value;
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

#[derive(Debug, Serialize)]
struct ProgressPayload<'a> {
    progress: &'a Value,
    at: String,
}

/// The server's command queue as the drain loop sees it, so the drain logic
/// can be tested without a server or Windows.
trait CommandQueue {
    type Command;
    type Awake;

    /// The next queued command.
    async fn next(&mut self) -> Fetched<Self::Command>;

    /// Ask the machine to stay awake until the returned guard is dropped.
    fn keep_awake(&mut self) -> Self::Awake;

    /// Run one command and report it. False when the drain should stop (the
    /// server refused to start it).
    async fn run(&mut self, command: Self::Command) -> bool;
}

/// What asking the server for the next command gave.
#[derive(Debug)]
enum Fetched<C> {
    Command(C),
    /// The server has nothing queued.
    Empty,
    /// The server could not be asked or gave an unusable answer.
    Failed,
}

/// How a drain ended.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
struct Drained {
    /// Commands run.
    ran: usize,
    /// The server said the queue is empty.
    emptied: bool,
}

/// Run queued commands back to back until the queue is empty.
///
/// One keep-awake guard covers the whole drain: it is taken as soon as the
/// first command arrives and only released once the server has nothing more
/// (or sleep, shutdown or cancellation stops the drain). Releasing it between
/// commands let Windows drop back into standby before the next one was
/// fetched. Each round holds the power command slot; once sleep is announced
/// the running command freezes with the machine and no further one starts.
async fn drain_queue<Q: CommandQueue>(queue: &mut Q, power: &Arc<PowerController>) -> Drained {
    let mut awake: Option<Q::Awake> = None;
    let mut ran = 0;
    let mut emptied = false;

    loop {
        let Some(_slot) = power.try_begin_command() else {
            break;
        };

        let command = match queue.next().await {
            Fetched::Command(command) => command,
            Fetched::Empty => {
                emptied = true;
                break;
            }
            Fetched::Failed => break,
        };

        // Sleep started while asking: leave the command queued on the server.
        if !power.state().is_awake() {
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
    Drained { ran, emptied }
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

    async fn next(&mut self) -> Fetched<PendingCommand> {
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

/// Progress posts for one running command.
struct ServerProgress<'a> {
    client: &'a CommandClient,
    api_key: &'a str,
    command_id: u64,
    key_health: &'a KeyHealth,
}

impl ProgressSink for ServerProgress<'_> {
    async fn post(&mut self, progress: &Value) -> Posted {
        self.client
            .send_progress(self.api_key, self.command_id, progress, self.key_health)
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
    /// machine announces sleep and polls at once when it wakes.
    pub async fn start_command_loop(
        &self,
        api_key: String,
        cancellation_token: CancellationToken,
        key_health: Arc<KeyHealth>,
        power: Arc<PowerController>,
        startup: StartupProgress,
    ) {
        if !command_runner::COMMANDS_ENABLED {
            warn!("Monitor-only agent: refusing to poll for commands");
            return;
        }

        info!(
            "Starting command loop (interval: {}s)",
            self.config.command_poll_interval
        );

        let mut power_gate = power.gate();
        let interval = Duration::from_secs(self.config.command_poll_interval);

        // Poll straight away at start, then on the interval.
        let mut ready = power_gate.wait_until_allowed(&cancellation_token).await;
        while ready {
            tokio::select! {
                _ = cancellation_token.cancelled() => break,
                drained = self.poll_once(&api_key, &key_health, &power) => {
                    if drained.emptied {
                        startup.mark(Milestone::CommandsDrained);
                    }
                }
            }
            ready = power_gate.tick(interval, &cancellation_token).await;
        }

        info!("Command loop stopped");
    }

    async fn poll_once(
        &self,
        api_key: &str,
        key_health: &KeyHealth,
        power: &Arc<PowerController>,
    ) -> Drained {
        let mut queue = ServerQueue {
            client: self,
            api_key,
            key_health,
        };

        let drained = drain_queue(&mut queue, power).await;
        if drained.ran > 1 {
            info!("Drained {} queued commands", drained.ran);
        }
        drained
    }

    /// Report the command started, run it and report the result. False when
    /// the server refused to start it.
    async fn run_command(&self, api_key: &str, command: PendingCommand, key_health: &KeyHealth) -> bool {
        let script_type = ScriptType::parse(&command.script_type);
        info!("Received command {} ({})", command.id, script_type.name());

        if !self.report_started(api_key, command.id, key_health).await {
            return false;
        }

        let (progress, updates) = command_progress::channel();
        let sink = ServerProgress {
            client: self,
            api_key,
            command_id: command.id,
            key_health,
        };

        let result = command_progress::while_running(
            command_runner::run_script(
                &script_type,
                &command.script_content,
                &command.parameters,
                command.id,
                &self.run_limits(command.timeout_seconds),
                progress,
            ),
            command_progress::post_progress(updates, PROGRESS_POST_INTERVAL, sink, command.id),
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

    async fn fetch_pending(&self, api_key: &str, key_health: &KeyHealth) -> Fetched<PendingCommand> {
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
                return Fetched::Failed;
            }
        };

        let outcome = RequestOutcome::from_status(response.status());
        key_health.record(outcome);

        if outcome != RequestOutcome::Success {
            warn!("Command poll failed ({})", response.status().as_u16());
            return Fetched::Failed;
        }

        match response.json::<PendingResponse>().await {
            Ok(PendingResponse {
                command: Some(command),
            }) => Fetched::Command(command),
            Ok(PendingResponse { command: None }) => Fetched::Empty,
            Err(e) => {
                warn!("Command poll returned an unreadable body: {}", e);
                Fetched::Failed
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

    /// Best effort: the caller warns once and never retries.
    async fn send_progress(&self, api_key: &str, command_id: u64, progress: &Value, key_health: &KeyHealth) -> Posted {
        let url = format!(
            "{}/api/commands/{}/progress",
            self.config.base_url, command_id
        );
        let payload = ProgressPayload {
            progress,
            at: chrono::Utc::now().to_rfc3339(),
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
                return Posted::Failed(e.to_string());
            }
        };

        key_health.record(RequestOutcome::from_status(status));
        progress_outcome(status)
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

/// 404/409/410/422 mean the command already finished or was cancelled.
fn progress_outcome(status: reqwest::StatusCode) -> Posted {
    match status.as_u16() {
        _ if status.is_success() => Posted::Accepted,
        404 | 409 | 410 | 422 => Posted::Gone,
        code => Posted::Failed(format!("server answered {}", code)),
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
        fail_fetch: bool,
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
                fail_fetch: false,
            }
        }

        fn log(&self) -> Vec<Step> {
            self.log.lock().unwrap().clone()
        }
    }

    impl CommandQueue for FakeQueue {
        type Command = u64;
        type Awake = FakeAwake;

        async fn next(&mut self) -> Fetched<u64> {
            if let Some(signal) = self.signal_while_fetching.take() {
                self.power.signal(signal);
            }
            if self.fail_fetch {
                return Fetched::Failed;
            }
            match self.queued.pop_front() {
                Some(id) => Fetched::Command(id),
                None => Fetched::Empty,
            }
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

        assert_eq!(
            drain_queue(&mut queue, &power).await,
            Drained { ran: 3, emptied: true }
        );

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

        assert_eq!(
            drain_queue(&mut queue, &power).await,
            Drained { ran: 0, emptied: true }
        );
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

        assert_eq!(
            drain_queue(&mut queue, &power).await,
            Drained { ran: 1, emptied: false }
        );

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

        assert_eq!(
            drain_queue(&mut queue, &power).await,
            Drained { ran: 1, emptied: false }
        );

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

        assert_eq!(
            drain_queue(&mut queue, &power).await,
            Drained { ran: 0, emptied: false }
        );

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

        assert_eq!(
            drain_queue(&mut queue, &power).await,
            Drained { ran: 0, emptied: false }
        );
        assert_eq!(queue.queued, VecDeque::from([32]));
    }

    #[tokio::test]
    async fn a_failed_fetch_does_not_count_as_drained() {
        let (power, _notices) = PowerController::new();
        let mut queue = FakeQueue::new(&power, &[32]);
        queue.fail_fetch = true;

        assert_eq!(
            drain_queue(&mut queue, &power).await,
            Drained {
                ran: 0,
                emptied: false
            }
        );
        assert!(queue.log().is_empty());
    }

    #[tokio::test]
    async fn a_refused_start_ends_the_drain() {
        let (power, _notices) = PowerController::new();
        let mut queue = FakeQueue::new(&power, &[32, 33]);
        queue.refuse = Some(32);

        assert_eq!(
            drain_queue(&mut queue, &power).await,
            Drained { ran: 0, emptied: false }
        );

        assert_eq!(
            queue.log(),
            vec![Step::Awake, Step::Refused(32), Step::Released]
        );
    }

    #[cfg(not(windows))]
    #[tokio::test(start_paused = true)]
    async fn the_command_loop_refuses_to_start_on_monitor_only_builds() {
        // Nothing listens here: any request would fail, but none must be made.
        let client = CommandClient::new(Config::new("http://127.0.0.1:9".to_string())).unwrap();
        let (power, _notices) = PowerController::new();
        let token = CancellationToken::new();

        let started = tokio::time::Instant::now();
        client
            .start_command_loop(
                "key".to_string(),
                token,
                Arc::new(KeyHealth::new(CancellationToken::new())),
                power,
                StartupProgress::default(),
            )
            .await;

        // Returned at once instead of polling until cancelled.
        assert_eq!(started.elapsed(), Duration::ZERO);
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
    fn serialises_the_progress_payload_the_server_expects() {
        let progress = serde_json::json!({"percent": 40, "stage": "Downloading"});
        let payload = ProgressPayload {
            progress: &progress,
            at: "2026-10-09T08:00:00+00:00".to_string(),
        };

        assert_eq!(
            serde_json::to_value(&payload).unwrap(),
            serde_json::json!({
                "progress": {"percent": 40, "stage": "Downloading"},
                "at": "2026-10-09T08:00:00+00:00"
            })
        );
    }

    #[test]
    fn treats_finished_or_cancelled_commands_as_gone() {
        use reqwest::StatusCode;

        assert_eq!(progress_outcome(StatusCode::OK), Posted::Accepted);
        assert_eq!(progress_outcome(StatusCode::NO_CONTENT), Posted::Accepted);
        for status in [404, 409, 410, 422] {
            assert_eq!(progress_outcome(StatusCode::from_u16(status).unwrap()), Posted::Gone);
        }
        assert_eq!(
            progress_outcome(StatusCode::INTERNAL_SERVER_ERROR),
            Posted::Failed("server answered 500".to_string())
        );
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
