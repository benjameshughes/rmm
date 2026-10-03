//! Runtime glue around the power state machine.
//!
//! `PowerController` is shared by the service control handler (which feeds it
//! OS power events and must never block) and the agent's loops. Every call is
//! a short mutex hold plus non-blocking channel sends: no I/O. Notices for the
//! server are queued and delivered by the agent's power task.
//!
//! Loops wait on a `PowerGate`: it pauses them while the machine sleeps and
//! releases them at once when it wakes, so a resumed machine heartbeats and
//! polls for commands without waiting out the interval.
//!
//! A watchdog ticks while the agent believes the machine is asleep. A
//! sleeping machine runs no code, so repeated ticks mean the resume event was
//! missed; the watchdog then wakes the agent as a resume would.

use crate::config::Config;
use crate::metrics::RequestOutcome;
use crate::power_state::{PowerMachine, PowerNotice, PowerSignal, PowerState};
use anyhow::{Context, Result};
use std::sync::{Arc, Mutex, MutexGuard};
use std::time::Duration;
use tokio::sync::{mpsc, watch};
use tokio::time::MissedTickBehavior;
use tokio_util::sync::CancellationToken;
use tracing::{debug, info, warn};

pub struct PowerController {
    machine: Mutex<PowerMachine>,
    state: watch::Sender<PowerState>,
    notices: mpsc::UnboundedSender<PowerNotice>,
}

impl PowerController {
    /// The receiver yields the notices to send to the server, in order.
    pub fn new() -> (Arc<Self>, mpsc::UnboundedReceiver<PowerNotice>) {
        let (notices, notice_rx) = mpsc::unbounded_channel();
        let (state, _) = watch::channel(PowerState::Awake);

        let controller = Arc::new(Self {
            machine: Mutex::new(PowerMachine::new()),
            state,
            notices,
        });

        (controller, notice_rx)
    }

    pub fn state(&self) -> PowerState {
        *self.state.borrow()
    }

    /// Feed an OS power event. Safe to call from the service control handler.
    #[cfg_attr(not(windows), allow(dead_code))]
    pub fn signal(&self, signal: PowerSignal) {
        let mut machine = self.lock();
        let before = machine.state();
        let notice = machine.on_signal(signal);
        let after = machine.state();

        if before != after {
            info!("Power: {:?} -> {:?} ({:?})", before, after, signal);
        }

        self.publish(&machine, notice);
    }

    /// Queue a notice that does not change state (the boot announcement).
    pub fn announce(&self, notice: PowerNotice) {
        let _ = self.notices.send(notice);
    }

    /// The asleep watchdog ticked; wakes the agent if the resume event was
    /// evidently missed.
    pub fn watchdog_tick(&self) {
        let mut machine = self.lock();
        let notice = machine.on_watchdog_tick();

        if notice.is_some() {
            warn!("Still running while marked asleep: the resume event was missed, waking up");
        }

        self.publish(&machine, notice);
    }

    /// Claim the command slot. None while the machine is asleep or shutting
    /// down; no new command may start then. Dropping the slot releases it.
    pub fn try_begin_command(self: &Arc<Self>) -> Option<CommandSlot> {
        self.lock().try_begin_command().then(|| CommandSlot {
            controller: self.clone(),
        })
    }

    pub fn gate(&self) -> PowerGate {
        PowerGate {
            state: self.state.subscribe(),
        }
    }

    fn end_command(&self) {
        self.lock().end_command();
    }

    /// Runs with the machine locked, so states and notices go out in the
    /// order the transitions happened.
    fn publish(&self, machine: &MutexGuard<'_, PowerMachine>, notice: Option<PowerNotice>) {
        let current = machine.state();
        self.state.send_if_modified(|state| {
            let changed = *state != current;
            *state = current;
            changed
        });

        if let Some(notice) = notice {
            let _ = self.notices.send(notice);
        }
    }

    fn lock(&self) -> MutexGuard<'_, PowerMachine> {
        self.machine
            .lock()
            .unwrap_or_else(|poisoned| poisoned.into_inner())
    }
}

/// Held while a command runs.
pub struct CommandSlot {
    controller: Arc<PowerController>,
}

impl Drop for CommandSlot {
    fn drop(&mut self) {
        self.controller.end_command();
    }
}

/// Tick the controller's asleep watchdog every `interval` until cancelled.
/// Late ticks after a real sleep are delayed, never bunched, so a single
/// resume can't count twice.
pub async fn run_asleep_watchdog(
    controller: Arc<PowerController>,
    interval: Duration,
    cancellation_token: CancellationToken,
) {
    let mut ticker = tokio::time::interval_at(tokio::time::Instant::now() + interval, interval);
    ticker.set_missed_tick_behavior(MissedTickBehavior::Delay);

    loop {
        tokio::select! {
            _ = cancellation_token.cancelled() => break,
            _ = ticker.tick() => controller.watchdog_tick(),
        }
    }
}

pub struct PowerGate {
    state: watch::Receiver<PowerState>,
}

impl PowerGate {
    /// Wait for the next round of work: the interval elapsing, or any power
    /// change (which, on waking, means work happens straight away). Never
    /// returns while the machine is asleep. False once cancelled.
    pub async fn tick(
        &mut self,
        interval: Duration,
        cancellation_token: &CancellationToken,
    ) -> bool {
        tokio::select! {
            _ = cancellation_token.cancelled() => return false,
            _ = tokio::time::sleep(interval) => {}
            _ = Self::changed(&mut self.state) => {}
        }

        self.wait_until_allowed(cancellation_token).await
    }

    /// Return at once when the work is allowed, otherwise wait for the
    /// machine to wake. False once cancelled.
    pub async fn wait_until_allowed(&mut self, cancellation_token: &CancellationToken) -> bool {
        if !self.state.borrow().is_awake() {
            debug!("Loop paused while the machine sleeps");
        }

        tokio::select! {
            _ = cancellation_token.cancelled() => false,
            // Err only if the controller is gone; carry on rather than stall.
            _ = self.state.wait_for(|state| state.is_awake()) => true,
        }
    }

    async fn changed(state: &mut watch::Receiver<PowerState>) {
        if state.changed().await.is_err() {
            std::future::pending::<()>().await;
        }
    }
}

/// What happened to a power notice.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum PowerDelivery {
    Delivered,
    /// 404: the server predates /api/power. Not an error.
    ServerTooOld,
    Failed(RequestOutcome),
}

impl PowerDelivery {
    pub fn from_status(status: reqwest::StatusCode) -> Self {
        if status == reqwest::StatusCode::NOT_FOUND {
            PowerDelivery::ServerTooOld
        } else {
            match RequestOutcome::from_status(status) {
                RequestOutcome::Success => PowerDelivery::Delivered,
                outcome => PowerDelivery::Failed(outcome),
            }
        }
    }

    /// What this says about the device key, for `KeyHealth`.
    pub fn key_outcome(self) -> RequestOutcome {
        match self {
            PowerDelivery::Delivered => RequestOutcome::Success,
            PowerDelivery::ServerTooOld => RequestOutcome::Inconclusive,
            PowerDelivery::Failed(outcome) => outcome,
        }
    }
}

/// Sends power notices: POST {server}/api/power with the device key.
pub struct PowerNotifier {
    config: Config,
    client: reqwest::Client,
}

impl PowerNotifier {
    pub fn new(config: Config) -> Result<Self> {
        let client = reqwest::Client::builder()
            .timeout(Duration::from_secs(config.power_request_timeout))
            .build()
            .context("Failed to create HTTP client for power notices")?;

        Ok(Self { config, client })
    }

    /// Shutdown notices get the short shutdown timeout so they never hold
    /// up the machine.
    pub fn timeout_for(&self, notice: &PowerNotice) -> Duration {
        if notice.is_shutdown() {
            Duration::from_secs(self.config.power_shutdown_timeout)
        } else {
            Duration::from_secs(self.config.power_request_timeout)
        }
    }

    /// One attempt, no retries. Failures are logged and swallowed.
    pub async fn send(&self, api_key: &str, notice: PowerNotice) -> PowerDelivery {
        let url = format!("{}/api/power", self.config.base_url);

        let response = self
            .client
            .post(&url)
            .header("X-Agent-Key", api_key)
            .header("Accept", "application/json")
            .timeout(self.timeout_for(&notice))
            .json(&notice)
            .send()
            .await;

        let status = match response {
            Ok(response) => response.status(),
            Err(e) => {
                warn!("Could not send power notice {:?}: {}", notice, e);
                return PowerDelivery::Failed(RequestOutcome::Inconclusive);
            }
        };

        let delivery = PowerDelivery::from_status(status);

        match delivery {
            PowerDelivery::Delivered => info!("Power notice sent: {:?}", notice),
            PowerDelivery::ServerTooOld => {
                debug!("Server has no power endpoint (404); skipping power notices")
            }
            PowerDelivery::Failed(_) => {
                warn!("Power notice {:?} rejected ({})", notice, status.as_u16())
            }
        }

        delivery
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::power_state::PowerReason;
    use reqwest::StatusCode;
    use tokio::io::{AsyncReadExt, AsyncWriteExt};
    use tokio::net::TcpListener;

    #[test]
    fn signals_queue_notices_in_order() {
        let (controller, mut notices) = PowerController::new();

        controller.signal(PowerSignal::Suspend);
        controller.signal(PowerSignal::Resume);
        controller.signal(PowerSignal::Shutdown);

        assert_eq!(
            notices.try_recv().unwrap(),
            PowerNotice::powering_off(PowerReason::Sleep)
        );
        assert_eq!(
            notices.try_recv().unwrap(),
            PowerNotice::powering_on(PowerReason::Resume)
        );
        assert_eq!(
            notices.try_recv().unwrap(),
            PowerNotice::powering_off(PowerReason::Shutdown)
        );
        assert!(notices.try_recv().is_err());
        assert_eq!(controller.state(), PowerState::ShuttingDown);
    }

    #[test]
    fn suspend_mid_command_announces_at_once() {
        let (controller, mut notices) = PowerController::new();

        let slot = controller.try_begin_command().expect("awake");
        controller.signal(PowerSignal::Suspend);
        assert_eq!(controller.state(), PowerState::Asleep);
        assert_eq!(
            notices.try_recv().unwrap(),
            PowerNotice::powering_off(PowerReason::Sleep)
        );
        assert!(controller.try_begin_command().is_none());

        drop(slot);

        assert_eq!(controller.state(), PowerState::Asleep);
        assert!(notices.try_recv().is_err());
    }

    #[test]
    fn boot_announcement_leaves_state_alone() {
        let (controller, mut notices) = PowerController::new();

        controller.announce(PowerNotice::powering_on(PowerReason::Boot));

        assert_eq!(
            notices.try_recv().unwrap(),
            PowerNotice::powering_on(PowerReason::Boot)
        );
        assert_eq!(controller.state(), PowerState::Awake);
    }

    #[tokio::test(start_paused = true)]
    async fn gate_pauses_while_asleep_and_wakes_immediately() {
        let (controller, _notices) = PowerController::new();
        let mut gate = controller.gate();
        let token = CancellationToken::new();

        let ticker = tokio::spawn(async move {
            let started = tokio::time::Instant::now();
            assert!(gate.tick(Duration::from_secs(30), &token).await);
            started.elapsed()
        });

        tokio::time::sleep(Duration::from_secs(5)).await;
        controller.signal(PowerSignal::Suspend);
        // Asleep for well over the interval: no tick.
        tokio::time::sleep(Duration::from_secs(600)).await;
        assert!(!ticker.is_finished());

        controller.signal(PowerSignal::Resume);
        let waited = ticker.await.unwrap();

        // Released on resume, not at the next interval boundary.
        assert_eq!(waited, Duration::from_secs(605));
    }

    #[tokio::test(start_paused = true)]
    async fn gate_ticks_on_the_interval_while_awake() {
        let (controller, _notices) = PowerController::new();
        let mut gate = controller.gate();
        let token = CancellationToken::new();
        let started = tokio::time::Instant::now();

        assert!(gate.tick(Duration::from_secs(30), &token).await);
        assert_eq!(started.elapsed(), Duration::from_secs(30));
    }

    #[tokio::test(start_paused = true)]
    async fn watchdog_wakes_after_a_missed_resume() {
        let (controller, mut notices) = PowerController::new();
        let token = CancellationToken::new();
        let interval = Duration::from_secs(60);
        tokio::spawn(run_asleep_watchdog(
            controller.clone(),
            interval,
            token.clone(),
        ));

        let mut gate = controller.gate();
        let started = tokio::time::Instant::now();
        let ticker = tokio::spawn(async move {
            assert!(
                gate.tick(Duration::from_secs(30), &CancellationToken::new())
                    .await
            );
            started.elapsed()
        });

        tokio::time::sleep(Duration::from_secs(5)).await;
        controller.signal(PowerSignal::Suspend);
        assert_eq!(
            notices.try_recv().unwrap(),
            PowerNotice::powering_off(PowerReason::Sleep)
        );

        // First tick (60s) while asleep is not enough.
        tokio::time::sleep(Duration::from_secs(100)).await;
        assert_eq!(controller.state(), PowerState::Asleep);
        assert!(!ticker.is_finished());

        // Second tick (120s): missed resume, wake and poll at once.
        let waited = ticker.await.unwrap();
        assert_eq!(waited, Duration::from_secs(120));
        assert_eq!(controller.state(), PowerState::Awake);
        assert_eq!(
            notices.try_recv().unwrap(),
            PowerNotice::powering_on(PowerReason::Resume)
        );
        token.cancel();
    }

    #[tokio::test(start_paused = true)]
    async fn watchdog_leaves_a_real_resume_alone() {
        let (controller, mut notices) = PowerController::new();
        let token = CancellationToken::new();
        tokio::spawn(run_asleep_watchdog(
            controller.clone(),
            Duration::from_secs(60),
            token.clone(),
        ));

        controller.signal(PowerSignal::Suspend);
        tokio::time::sleep(Duration::from_secs(70)).await;
        controller.signal(PowerSignal::Resume);
        tokio::time::sleep(Duration::from_secs(600)).await;

        assert_eq!(controller.state(), PowerState::Awake);
        assert_eq!(
            notices.try_recv().unwrap(),
            PowerNotice::powering_off(PowerReason::Sleep)
        );
        assert_eq!(
            notices.try_recv().unwrap(),
            PowerNotice::powering_on(PowerReason::Resume)
        );
        assert!(notices.try_recv().is_err());
        token.cancel();
    }

    #[tokio::test(start_paused = true)]
    async fn gate_stops_on_cancellation_while_asleep() {
        let (controller, _notices) = PowerController::new();
        let mut gate = controller.gate();
        let token = CancellationToken::new();
        controller.signal(PowerSignal::Suspend);

        let cancel = token.clone();
        tokio::spawn(async move {
            tokio::time::sleep(Duration::from_secs(10)).await;
            cancel.cancel();
        });

        assert!(!gate.tick(Duration::from_secs(30), &token).await);
    }

    #[test]
    fn classifies_power_responses() {
        assert_eq!(
            PowerDelivery::from_status(StatusCode::OK),
            PowerDelivery::Delivered
        );
        assert_eq!(
            PowerDelivery::from_status(StatusCode::NO_CONTENT),
            PowerDelivery::Delivered
        );
        assert_eq!(
            PowerDelivery::from_status(StatusCode::NOT_FOUND),
            PowerDelivery::ServerTooOld
        );
        assert_eq!(
            PowerDelivery::from_status(StatusCode::UNAUTHORIZED),
            PowerDelivery::Failed(RequestOutcome::Unauthorized)
        );
        assert_eq!(
            PowerDelivery::from_status(StatusCode::INTERNAL_SERVER_ERROR),
            PowerDelivery::Failed(RequestOutcome::Inconclusive)
        );

        assert_eq!(
            PowerDelivery::ServerTooOld.key_outcome(),
            RequestOutcome::Inconclusive
        );
        assert_eq!(
            PowerDelivery::Delivered.key_outcome(),
            RequestOutcome::Success
        );
    }

    #[test]
    fn shutdown_notices_use_the_short_timeout() {
        let notifier = PowerNotifier::new(Config::default()).unwrap();

        assert_eq!(
            notifier.timeout_for(&PowerNotice::powering_off(PowerReason::Shutdown)),
            Duration::from_secs(crate::config::DEFAULT_POWER_SHUTDOWN_TIMEOUT_SECS)
        );
        assert_eq!(
            notifier.timeout_for(&PowerNotice::powering_off(PowerReason::Sleep)),
            Duration::from_secs(crate::config::DEFAULT_POWER_REQUEST_TIMEOUT_SECS)
        );
    }

    /// Serves one canned response and hands back the raw request.
    async fn one_shot_server(response: &'static str) -> (String, tokio::task::JoinHandle<String>) {
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let address = format!("http://{}", listener.local_addr().unwrap());

        let handle = tokio::spawn(async move {
            let (mut socket, _) = listener.accept().await.unwrap();
            let mut request = Vec::new();
            let mut buffer = [0u8; 4096];
            loop {
                let read = socket.read(&mut buffer).await.unwrap();
                request.extend_from_slice(&buffer[..read]);
                let text = String::from_utf8_lossy(&request);
                if let Some(header_end) = text.find("\r\n\r\n") {
                    let length = text[..header_end]
                        .lines()
                        .find_map(|line| {
                            line.to_ascii_lowercase()
                                .strip_prefix("content-length:")
                                .map(|value| value.trim().parse::<usize>().unwrap())
                        })
                        .unwrap_or(0);
                    if request.len() >= header_end + 4 + length || read == 0 {
                        break;
                    }
                }
            }
            socket.write_all(response.as_bytes()).await.unwrap();
            String::from_utf8_lossy(&request).into_owned()
        });

        (address, handle)
    }

    fn notifier_for(base_url: String) -> PowerNotifier {
        PowerNotifier::new(Config::new(base_url)).unwrap()
    }

    #[tokio::test]
    async fn posts_the_notice_with_the_device_key() {
        let (address, server) =
            one_shot_server("HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}")
                .await;

        let delivery = notifier_for(address)
            .send(
                "device-key-123",
                PowerNotice::powering_off(PowerReason::Sleep),
            )
            .await;
        let request = server.await.unwrap();

        assert_eq!(delivery, PowerDelivery::Delivered);
        assert!(request.starts_with("POST /api/power HTTP/1.1"));
        assert!(request
            .to_ascii_lowercase()
            .contains("x-agent-key: device-key-123"));
        assert!(request.ends_with(r#"{"event":"powering_off","reason":"sleep"}"#));
    }

    #[tokio::test]
    async fn tolerates_a_server_without_the_power_endpoint() {
        let (address, server) = one_shot_server(
            "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
        )
        .await;

        let delivery = notifier_for(address)
            .send(
                "device-key-123",
                PowerNotice::powering_on(PowerReason::Boot),
            )
            .await;
        server.await.unwrap();

        assert_eq!(delivery, PowerDelivery::ServerTooOld);
    }

    #[tokio::test]
    async fn swallows_network_errors() {
        // Nothing listens on this port once the listener is dropped.
        let listener = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
        let address = format!("http://{}", listener.local_addr().unwrap());
        drop(listener);

        let delivery = notifier_for(address)
            .send(
                "device-key-123",
                PowerNotice::powering_off(PowerReason::Shutdown),
            )
            .await;

        assert_eq!(
            delivery,
            PowerDelivery::Failed(RequestOutcome::Inconclusive)
        );
    }
}
