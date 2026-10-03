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

// Only Windows feeds the machine power events.
#![cfg_attr(not(windows), allow(dead_code))]

use serde::Serialize;

/// Where the agent stands with the machine's power.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum PowerState {
    /// Normal operation.
    Awake,
    /// Sleep requested while a command is still running: take no new
    /// commands, tell the server once the command finishes.
    GoingToSleep,
    /// Server told; no traffic until the machine wakes.
    Asleep,
    /// The machine is shutting down; the agent is about to stop.
    ShuttingDown,
}

impl PowerState {
    /// Heartbeats and metrics keep flowing while a command drains, so the
    /// device does not look offline mid-command.
    pub fn allows_reporting(self) -> bool {
        matches!(self, PowerState::Awake | PowerState::GoingToSleep)
    }

    /// New commands are only picked up when fully awake.
    pub fn allows_new_commands(self) -> bool {
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

/// The power state machine. One per agent process.
#[derive(Debug, Clone)]
pub struct PowerMachine {
    state: PowerState,
    command_running: bool,
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
        }
    }

    pub fn state(&self) -> PowerState {
        self.state
    }

    /// Apply an OS power event. Returns the notice to send, if any.
    pub fn on_signal(&mut self, signal: PowerSignal) -> Option<PowerNotice> {
        if self.state == PowerState::ShuttingDown {
            return None;
        }

        match signal {
            PowerSignal::Shutdown => {
                self.state = PowerState::ShuttingDown;
                Some(PowerNotice::powering_off(PowerReason::Shutdown))
            }
            PowerSignal::Suspend => self.go_to_sleep(),
            PowerSignal::Resume => self.wake(),
        }
    }

    /// Claim the right to fetch and run a command. False when not fully
    /// awake or a command is already running; the caller must not start one
    /// then.
    pub fn try_begin_command(&mut self) -> bool {
        if !self.state.allows_new_commands() || self.command_running {
            return false;
        }

        self.command_running = true;
        true
    }

    /// A command finished. If sleep was waiting on it, the machine is now
    /// asleep and the server should be told.
    pub fn end_command(&mut self) -> Option<PowerNotice> {
        self.command_running = false;

        match self.state {
            PowerState::GoingToSleep => {
                self.state = PowerState::Asleep;
                Some(PowerNotice::powering_off(PowerReason::Sleep))
            }
            _ => None,
        }
    }

    fn go_to_sleep(&mut self) -> Option<PowerNotice> {
        if self.state != PowerState::Awake {
            return None;
        }

        if self.command_running {
            self.state = PowerState::GoingToSleep;
            return None;
        }

        self.state = PowerState::Asleep;
        Some(PowerNotice::powering_off(PowerReason::Sleep))
    }

    fn wake(&mut self) -> Option<PowerNotice> {
        match self.state {
            PowerState::Asleep => {
                self.state = PowerState::Awake;
                Some(PowerNotice::powering_on(PowerReason::Resume))
            }
            // The server was never told we were going, so there is nothing
            // to take back.
            PowerState::GoingToSleep => {
                self.state = PowerState::Awake;
                None
            }
            PowerState::Awake | PowerState::ShuttingDown => None,
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn starts_awake() {
        assert_eq!(PowerMachine::new().state(), PowerState::Awake);
        assert!(PowerState::Awake.allows_reporting());
        assert!(PowerState::Awake.allows_new_commands());
    }

    #[test]
    fn suspend_with_nothing_running_sleeps_at_once() {
        let mut machine = PowerMachine::new();

        assert_eq!(
            machine.on_signal(PowerSignal::Suspend),
            Some(PowerNotice::powering_off(PowerReason::Sleep))
        );
        assert_eq!(machine.state(), PowerState::Asleep);
        assert!(!machine.state().allows_reporting());
        assert!(!machine.try_begin_command());
    }

    #[test]
    fn resume_wakes_and_announces() {
        let mut machine = PowerMachine::new();
        machine.on_signal(PowerSignal::Suspend);

        assert_eq!(
            machine.on_signal(PowerSignal::Resume),
            Some(PowerNotice::powering_on(PowerReason::Resume))
        );
        assert_eq!(machine.state(), PowerState::Awake);
        assert!(machine.try_begin_command());
    }

    #[test]
    fn second_resume_event_is_ignored() {
        let mut machine = PowerMachine::new();
        machine.on_signal(PowerSignal::Suspend);
        machine.on_signal(PowerSignal::Resume);

        // ResumeAutomatic is followed by ResumeSuspend when a user is present.
        assert_eq!(machine.on_signal(PowerSignal::Resume), None);
        assert_eq!(machine.state(), PowerState::Awake);
    }

    #[test]
    fn resume_while_awake_does_nothing() {
        let mut machine = PowerMachine::new();

        assert_eq!(machine.on_signal(PowerSignal::Resume), None);
        assert_eq!(machine.state(), PowerState::Awake);
    }

    #[test]
    fn going_to_sleep_waits_for_the_running_command() {
        let mut machine = PowerMachine::new();
        assert!(machine.try_begin_command());

        assert_eq!(machine.on_signal(PowerSignal::Suspend), None);
        assert_eq!(machine.state(), PowerState::GoingToSleep);
        assert!(machine.state().allows_reporting());
        assert!(!machine.state().allows_new_commands());

        assert_eq!(
            machine.end_command(),
            Some(PowerNotice::powering_off(PowerReason::Sleep))
        );
        assert_eq!(machine.state(), PowerState::Asleep);
    }

    #[test]
    fn no_new_command_while_draining() {
        let mut machine = PowerMachine::new();
        assert!(machine.try_begin_command());
        machine.on_signal(PowerSignal::Suspend);
        machine.end_command();

        assert!(!machine.try_begin_command());
    }

    #[test]
    fn waking_while_draining_cancels_sleep_silently() {
        let mut machine = PowerMachine::new();
        assert!(machine.try_begin_command());
        machine.on_signal(PowerSignal::Suspend);

        assert_eq!(machine.on_signal(PowerSignal::Resume), None);
        assert_eq!(machine.state(), PowerState::Awake);
        assert_eq!(machine.end_command(), None);
        assert_eq!(machine.state(), PowerState::Awake);
    }

    #[test]
    fn only_one_command_at_a_time() {
        let mut machine = PowerMachine::new();

        assert!(machine.try_begin_command());
        assert!(!machine.try_begin_command());
        assert_eq!(machine.end_command(), None);
        assert!(machine.try_begin_command());
    }

    #[test]
    fn repeated_suspend_is_ignored() {
        let mut machine = PowerMachine::new();
        machine.on_signal(PowerSignal::Suspend);

        assert_eq!(machine.on_signal(PowerSignal::Suspend), None);
        assert_eq!(machine.state(), PowerState::Asleep);
    }

    #[test]
    fn shutdown_announces_from_any_state() {
        let mut awake = PowerMachine::new();
        assert_eq!(
            awake.on_signal(PowerSignal::Shutdown),
            Some(PowerNotice::powering_off(PowerReason::Shutdown))
        );
        assert_eq!(awake.state(), PowerState::ShuttingDown);

        let mut asleep = PowerMachine::new();
        asleep.on_signal(PowerSignal::Suspend);
        assert_eq!(
            asleep.on_signal(PowerSignal::Shutdown),
            Some(PowerNotice::powering_off(PowerReason::Shutdown))
        );
    }

    #[test]
    fn shutdown_does_not_wait_for_a_running_command() {
        let mut machine = PowerMachine::new();
        assert!(machine.try_begin_command());
        machine.on_signal(PowerSignal::Suspend);

        assert_eq!(
            machine.on_signal(PowerSignal::Shutdown),
            Some(PowerNotice::powering_off(PowerReason::Shutdown))
        );
        assert_eq!(machine.end_command(), None);
        assert_eq!(machine.state(), PowerState::ShuttingDown);
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
        assert!(!machine.state().allows_reporting());
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
