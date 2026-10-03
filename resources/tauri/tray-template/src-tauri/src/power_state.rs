//! What the machine's power situation means for the agent.
//!
//! Pure decision logic, no Windows calls: Windows events arrive as
//! `PowerSignal`s, the machine moves between states, and each transition says
//! which notice (if any) the server should get. The runtime glue lives in
//! `power.rs`, the Windows event mapping in `power_events.rs`.
//!
//! Only classic sleep (PBT_APMSUSPEND) pauses the agent. Modern Standby PCs
//! never send it and are treated as awake throughout: Wake-on-LAN wakes them
//! "dark" (display off) and commands must still run then.
//!
//! Windows does not wait for services on suspend, so the sleep notice goes
//! out at once; a running command simply freezes with the machine and carries
//! on after resume.

// Only Windows feeds the machine power events.
#![cfg_attr(not(windows), allow(dead_code))]

use serde::Serialize;
use std::time::Duration;

/// Consecutive watchdog ticks seen while "asleep" that prove the resume
/// event was missed: a sleeping machine runs no code, so the agent cannot
/// keep ticking.
pub const MISSED_RESUME_TICKS: u32 = 2;

/// Where the agent stands with the machine's power.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum PowerState {
    /// Normal operation.
    Awake,
    /// Server told; no traffic and no new commands until the machine wakes.
    Asleep,
    /// The machine is shutting down; the agent is about to stop.
    ShuttingDown,
}

impl PowerState {
    /// Heartbeats, metrics and command polling only run while awake.
    pub fn is_awake(self) -> bool {
        self == PowerState::Awake
    }
}

/// A power event from the operating system.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum PowerSignal {
    /// PBT_APMSUSPEND.
    Suspend,
    /// PBT_APMRESUMEAUTOMATIC / PBT_APMRESUMESUSPEND / PBT_APMRESUMECRITICAL.
    Resume,
    /// SERVICE_CONTROL_PRESHUTDOWN / SERVICE_CONTROL_SHUTDOWN.
    Shutdown,
}

/// The `event` field of POST /api/power.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum PowerEvent {
    PoweringOff,
    PoweringOn,
}

/// The `reason` field of POST /api/power.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum PowerReason {
    Sleep,
    Shutdown,
    Resume,
    Boot,
}

/// Body of POST /api/power.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize)]
pub struct PowerNotice {
    pub event: PowerEvent,
    pub reason: PowerReason,
}

impl PowerNotice {
    pub fn powering_off(reason: PowerReason) -> Self {
        Self {
            event: PowerEvent::PoweringOff,
            reason,
        }
    }

    pub fn powering_on(reason: PowerReason) -> Self {
        Self {
            event: PowerEvent::PoweringOn,
            reason,
        }
    }

    pub fn is_shutdown(&self) -> bool {
        self.reason == PowerReason::Shutdown
    }
}

/// Whether a starting agent should tell the server the machine booted: only
/// when the machine itself started recently, not on every service restart
/// (self-update, manual restart) of an always-on PC.
pub fn is_fresh_boot(uptime: Duration, max_uptime: Duration) -> bool {
    uptime < max_uptime
}

/// The power state machine. One per agent process.
#[derive(Debug, Clone)]
pub struct PowerMachine {
    state: PowerState,
    command_running: bool,
    asleep_ticks: u32,
}

impl Default for PowerMachine {
    fn default() -> Self {
        Self::new()
    }
}

impl PowerMachine {
    pub fn new() -> Self {
        Self {
            state: PowerState::Awake,
            command_running: false,
            asleep_ticks: 0,
        }
    }

    pub fn state(&self) -> PowerState {
        self.state
    }

    /// Apply an OS power event. Returns the notice to send, if any.
    pub fn on_signal(&mut self, signal: PowerSignal) -> Option<PowerNotice> {
        match (self.state, signal) {
            (PowerState::ShuttingDown, _) => None,
            (_, PowerSignal::Shutdown) => {
                self.state = PowerState::ShuttingDown;
                Some(PowerNotice::powering_off(PowerReason::Shutdown))
            }
            (PowerState::Awake, PowerSignal::Suspend) => {
                self.state = PowerState::Asleep;
                self.asleep_ticks = 0;
                Some(PowerNotice::powering_off(PowerReason::Sleep))
            }
            (PowerState::Asleep, PowerSignal::Resume) => self.wake(),
            (PowerState::Asleep, PowerSignal::Suspend)
            | (PowerState::Awake, PowerSignal::Resume) => None,
        }
    }

    /// The asleep watchdog ticked. While asleep, `MISSED_RESUME_TICKS` ticks
    /// in a row mean the agent is plainly running, so the resume event was
    /// missed: wake as if it had arrived.
    pub fn on_watchdog_tick(&mut self) -> Option<PowerNotice> {
        if self.state != PowerState::Asleep {
            self.asleep_ticks = 0;
            return None;
        }

        self.asleep_ticks += 1;
        if self.asleep_ticks < MISSED_RESUME_TICKS {
            return None;
        }

        self.wake()
    }

    /// Claim the right to fetch and run a command. False when not awake or a
    /// command is already running; the caller must not start one then.
    pub fn try_begin_command(&mut self) -> bool {
        if !self.state.is_awake() || self.command_running {
            return false;
        }

        self.command_running = true;
        true
    }

    /// A command finished (possibly after the machine slept and woke).
    pub fn end_command(&mut self) {
        self.command_running = false;
    }

    fn wake(&mut self) -> Option<PowerNotice> {
        self.state = PowerState::Awake;
        self.asleep_ticks = 0;
        Some(PowerNotice::powering_on(PowerReason::Resume))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn asleep() -> PowerMachine {
        let mut machine = PowerMachine::new();
        machine.on_signal(PowerSignal::Suspend);
        machine
    }

    #[test]
    fn starts_awake() {
        let machine = PowerMachine::new();
        assert_eq!(machine.state(), PowerState::Awake);
        assert!(machine.state().is_awake());
    }

    #[test]
    fn suspend_announces_at_once_and_stops_new_commands() {
        let mut machine = PowerMachine::new();

        assert_eq!(
            machine.on_signal(PowerSignal::Suspend),
            Some(PowerNotice::powering_off(PowerReason::Sleep))
        );
        assert_eq!(machine.state(), PowerState::Asleep);
        assert!(!machine.state().is_awake());
        assert!(!machine.try_begin_command());
    }

    #[test]
    fn suspend_mid_command_still_announces_at_once() {
        let mut machine = PowerMachine::new();
        assert!(machine.try_begin_command());

        assert_eq!(
            machine.on_signal(PowerSignal::Suspend),
            Some(PowerNotice::powering_off(PowerReason::Sleep))
        );
        assert_eq!(machine.state(), PowerState::Asleep);

        // The frozen command finishing later sends nothing more.
        machine.end_command();
        assert_eq!(machine.state(), PowerState::Asleep);
        assert!(!machine.try_begin_command());
    }

    #[test]
    fn command_running_across_sleep_finishes_after_resume() {
        let mut machine = PowerMachine::new();
        assert!(machine.try_begin_command());
        machine.on_signal(PowerSignal::Suspend);

        assert_eq!(
            machine.on_signal(PowerSignal::Resume),
            Some(PowerNotice::powering_on(PowerReason::Resume))
        );
        // Still the same command; no second one until it ends.
        assert!(!machine.try_begin_command());
        machine.end_command();
        assert!(machine.try_begin_command());
    }

    #[test]
    fn resume_wakes_and_announces() {
        let mut machine = asleep();

        assert_eq!(
            machine.on_signal(PowerSignal::Resume),
            Some(PowerNotice::powering_on(PowerReason::Resume))
        );
        assert_eq!(machine.state(), PowerState::Awake);
        assert!(machine.try_begin_command());
    }

    #[test]
    fn second_resume_event_is_ignored() {
        let mut machine = asleep();
        machine.on_signal(PowerSignal::Resume);

        // ResumeAutomatic is followed by ResumeSuspend when a user is present.
        assert_eq!(machine.on_signal(PowerSignal::Resume), None);
        assert_eq!(machine.state(), PowerState::Awake);
    }

    #[test]
    fn repeated_suspend_is_ignored() {
        let mut machine = asleep();

        assert_eq!(machine.on_signal(PowerSignal::Suspend), None);
        assert_eq!(machine.state(), PowerState::Asleep);
    }

    #[test]
    fn only_one_command_at_a_time() {
        let mut machine = PowerMachine::new();

        assert!(machine.try_begin_command());
        assert!(!machine.try_begin_command());
        machine.end_command();
        assert!(machine.try_begin_command());
    }

    #[test]
    fn watchdog_wakes_after_two_ticks_asleep() {
        let mut machine = asleep();

        assert_eq!(machine.on_watchdog_tick(), None);
        assert_eq!(machine.state(), PowerState::Asleep);
        assert_eq!(
            machine.on_watchdog_tick(),
            Some(PowerNotice::powering_on(PowerReason::Resume))
        );
        assert_eq!(machine.state(), PowerState::Awake);
    }

    #[test]
    fn watchdog_does_nothing_while_awake() {
        let mut machine = PowerMachine::new();

        for _ in 0..5 {
            assert_eq!(machine.on_watchdog_tick(), None);
        }
        assert_eq!(machine.state(), PowerState::Awake);
    }

    #[test]
    fn ticks_from_before_sleeping_do_not_count() {
        let mut machine = PowerMachine::new();
        machine.on_watchdog_tick();
        machine.on_signal(PowerSignal::Suspend);

        assert_eq!(machine.on_watchdog_tick(), None);
        assert_eq!(machine.state(), PowerState::Asleep);
    }

    #[test]
    fn a_real_resume_resets_the_watchdog() {
        let mut machine = asleep();
        machine.on_watchdog_tick();
        machine.on_signal(PowerSignal::Resume);
        machine.on_signal(PowerSignal::Suspend);

        // One tick into the new sleep is not enough.
        assert_eq!(machine.on_watchdog_tick(), None);
        assert_eq!(machine.state(), PowerState::Asleep);
    }

    #[test]
    fn watchdog_never_wakes_a_shutting_down_agent() {
        let mut machine = asleep();
        machine.on_signal(PowerSignal::Shutdown);

        for _ in 0..5 {
            assert_eq!(machine.on_watchdog_tick(), None);
        }
        assert_eq!(machine.state(), PowerState::ShuttingDown);
    }

    #[test]
    fn shutdown_announces_from_any_state() {
        let mut awake = PowerMachine::new();
        assert_eq!(
            awake.on_signal(PowerSignal::Shutdown),
            Some(PowerNotice::powering_off(PowerReason::Shutdown))
        );
        assert_eq!(awake.state(), PowerState::ShuttingDown);

        let mut sleeping = asleep();
        assert_eq!(
            sleeping.on_signal(PowerSignal::Shutdown),
            Some(PowerNotice::powering_off(PowerReason::Shutdown))
        );
    }

    #[test]
    fn nothing_happens_after_shutdown() {
        let mut machine = PowerMachine::new();
        machine.on_signal(PowerSignal::Shutdown);

        for signal in [
            PowerSignal::Shutdown,
            PowerSignal::Resume,
            PowerSignal::Suspend,
        ] {
            assert_eq!(machine.on_signal(signal), None, "{signal:?}");
            assert_eq!(machine.state(), PowerState::ShuttingDown);
        }
        assert!(!machine.try_begin_command());
    }

    #[test]
    fn boot_is_announced_only_shortly_after_the_machine_started() {
        let max = Duration::from_secs(300);

        assert!(is_fresh_boot(Duration::from_secs(45), max));
        assert!(is_fresh_boot(Duration::from_secs(299), max));
        assert!(!is_fresh_boot(Duration::from_secs(300), max));
        // An always-on PC whose agent restarted after a self-update.
        assert!(!is_fresh_boot(Duration::from_secs(9 * 24 * 3600), max));
    }

    #[test]
    fn serialises_the_payload_the_server_expects() {
        let cases = [
            (
                PowerNotice::powering_off(PowerReason::Sleep),
                "powering_off",
                "sleep",
            ),
            (
                PowerNotice::powering_off(PowerReason::Shutdown),
                "powering_off",
                "shutdown",
            ),
            (
                PowerNotice::powering_on(PowerReason::Resume),
                "powering_on",
                "resume",
            ),
            (
                PowerNotice::powering_on(PowerReason::Boot),
                "powering_on",
                "boot",
            ),
        ];

        for (notice, event, reason) in cases {
            assert_eq!(
                serde_json::to_value(notice).unwrap(),
                serde_json::json!({ "event": event, "reason": reason })
            );
        }
    }

    #[test]
    fn only_shutdown_notices_are_shutdowns() {
        assert!(PowerNotice::powering_off(PowerReason::Shutdown).is_shutdown());
        assert!(!PowerNotice::powering_off(PowerReason::Sleep).is_shutdown());
        assert!(!PowerNotice::powering_on(PowerReason::Boot).is_shutdown());
    }
}
