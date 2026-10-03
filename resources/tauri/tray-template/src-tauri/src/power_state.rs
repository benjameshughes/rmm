//! What the machine's power situation means for the agent.
//!
//! Pure decision logic, no Windows calls: Windows events arrive as
//! `PowerSignal`s, the machine moves between states, and each transition says
//! which notice (if any) the server should get. The runtime glue lives in
//! `power.rs`, the Windows event plumbing in `power_events.rs`.
//!
//! Modern Standby (S0 low power idle) PCs never send a suspend event: they
//! enter standby when the display turns off. On those machines display off/on
//! stands in for suspend/resume. On classic S3 machines a display timeout is
//! only a monitor turning off, so it is ignored.

// Only Windows feeds the machine power events.
#![cfg_attr(not(windows), allow(dead_code))]

use serde::Serialize;

/// Why the machine is going quiet.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum SleepKind {
    /// Classic sleep (PBT_APMSUSPEND).
    Sleep,
    /// Modern Standby: the display turned off.
    Standby,
}

/// Where the agent stands with the machine's power.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum PowerState {
    /// Normal operation.
    Awake,
    /// Sleep requested while a command is still running: take no new
    /// commands, tell the server once the command finishes.
    GoingToSleep(SleepKind),
    /// Server told; no traffic until the machine wakes.
    Asleep(SleepKind),
    /// The machine is shutting down; the agent is about to stop.
    ShuttingDown,
}

impl PowerState {
    /// Heartbeats and metrics keep flowing while a command drains, so the
    /// device does not look offline mid-command.
    pub fn allows_reporting(self) -> bool {
        matches!(self, PowerState::Awake | PowerState::GoingToSleep(_))
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
    /// GUID_CONSOLE_DISPLAY_STATE = Off.
    DisplayOff,
    /// GUID_CONSOLE_DISPLAY_STATE = On.
    DisplayOn,
    /// GUID_CONSOLE_DISPLAY_STATE = Dimmed.
    DisplayDimmed,
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
    Standby,
    Shutdown,
    Resume,
    Boot,
}

impl From<SleepKind> for PowerReason {
    fn from(kind: SleepKind) -> Self {
        match kind {
            SleepKind::Sleep => PowerReason::Sleep,
            SleepKind::Standby => PowerReason::Standby,
        }
    }
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
    modern_standby: bool,
    command_running: bool,
}

impl PowerMachine {
    pub fn new(modern_standby: bool) -> Self {
        Self {
            state: PowerState::Awake,
            modern_standby,
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
            PowerSignal::Suspend => self.go_to_sleep(SleepKind::Sleep),
            PowerSignal::DisplayOff if self.modern_standby => self.go_to_sleep(SleepKind::Standby),
            PowerSignal::Resume => self.wake(),
            PowerSignal::DisplayOn if self.modern_standby => self.wake(),
            PowerSignal::DisplayOff | PowerSignal::DisplayOn | PowerSignal::DisplayDimmed => None,
        }
    }

    /// Claim the right to start a new command. False when not fully awake or
    /// a command is already running; the caller must not start one then.
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
            PowerState::GoingToSleep(kind) => {
                self.state = PowerState::Asleep(kind);
                Some(PowerNotice::powering_off(kind.into()))
            }
            _ => None,
        }
    }

    fn go_to_sleep(&mut self, kind: SleepKind) -> Option<PowerNotice> {
        if self.state != PowerState::Awake {
            return None;
        }

        if self.command_running {
            self.state = PowerState::GoingToSleep(kind);
            return None;
        }

        self.state = PowerState::Asleep(kind);
        Some(PowerNotice::powering_off(kind.into()))
    }

    fn wake(&mut self) -> Option<PowerNotice> {
        match self.state {
            PowerState::Asleep(_) => {
                self.state = PowerState::Awake;
                Some(PowerNotice::powering_on(PowerReason::Resume))
            }
            // The server was never told we were going, so there is nothing
            // to take back.
            PowerState::GoingToSleep(_) => {
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

    fn classic() -> PowerMachine {
        PowerMachine::new(false)
    }

    fn modern() -> PowerMachine {
        PowerMachine::new(true)
    }

    #[test]
    fn starts_awake() {
        assert_eq!(classic().state(), PowerState::Awake);
        assert!(PowerState::Awake.allows_reporting());
        assert!(PowerState::Awake.allows_new_commands());
    }

    #[test]
    fn classic_suspend_with_nothing_running_sleeps_at_once() {
        let mut machine = classic();

        assert_eq!(
            machine.on_signal(PowerSignal::Suspend),
            Some(PowerNotice::powering_off(PowerReason::Sleep))
        );
        assert_eq!(machine.state(), PowerState::Asleep(SleepKind::Sleep));
        assert!(!machine.try_begin_command());
    }

    #[test]
    fn classic_resume_wakes_and_announces() {
        let mut machine = classic();
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
        let mut machine = classic();
        machine.on_signal(PowerSignal::Suspend);
        machine.on_signal(PowerSignal::Resume);

        // ResumeAutomatic is followed by ResumeSuspend when a user is present.
        assert_eq!(machine.on_signal(PowerSignal::Resume), None);
        assert_eq!(machine.state(), PowerState::Awake);
    }

    #[test]
    fn display_off_is_ignored_on_classic_machines() {
        let mut machine = classic();

        assert_eq!(machine.on_signal(PowerSignal::DisplayOff), None);
        assert_eq!(machine.state(), PowerState::Awake);
        assert_eq!(machine.on_signal(PowerSignal::DisplayOn), None);
        assert_eq!(machine.state(), PowerState::Awake);
    }

    #[test]
    fn display_on_does_not_wake_a_sleeping_classic_machine() {
        let mut machine = classic();
        machine.on_signal(PowerSignal::Suspend);

        assert_eq!(machine.on_signal(PowerSignal::DisplayOn), None);
        assert_eq!(machine.state(), PowerState::Asleep(SleepKind::Sleep));
    }

    #[test]
    fn modern_standby_display_off_means_standby() {
        let mut machine = modern();

        assert_eq!(
            machine.on_signal(PowerSignal::DisplayOff),
            Some(PowerNotice::powering_off(PowerReason::Standby))
        );
        assert_eq!(machine.state(), PowerState::Asleep(SleepKind::Standby));
        assert!(!machine.state().allows_reporting());
    }

    #[test]
    fn modern_standby_display_on_wakes() {
        let mut machine = modern();
        machine.on_signal(PowerSignal::DisplayOff);

        assert_eq!(
            machine.on_signal(PowerSignal::DisplayOn),
            Some(PowerNotice::powering_on(PowerReason::Resume))
        );
        assert_eq!(machine.state(), PowerState::Awake);
    }

    #[test]
    fn dimmed_display_is_ignored() {
        for mut machine in [classic(), modern()] {
            assert_eq!(machine.on_signal(PowerSignal::DisplayDimmed), None);
            assert_eq!(machine.state(), PowerState::Awake);
        }

        let mut machine = modern();
        machine.on_signal(PowerSignal::DisplayOff);
        assert_eq!(machine.on_signal(PowerSignal::DisplayDimmed), None);
        assert_eq!(machine.state(), PowerState::Asleep(SleepKind::Standby));
    }

    #[test]
    fn going_to_sleep_waits_for_the_running_command() {
        let mut machine = classic();
        assert!(machine.try_begin_command());

        assert_eq!(machine.on_signal(PowerSignal::Suspend), None);
        assert_eq!(machine.state(), PowerState::GoingToSleep(SleepKind::Sleep));
        assert!(machine.state().allows_reporting());
        assert!(!machine.state().allows_new_commands());

        assert_eq!(
            machine.end_command(),
            Some(PowerNotice::powering_off(PowerReason::Sleep))
        );
        assert_eq!(machine.state(), PowerState::Asleep(SleepKind::Sleep));
    }

    #[test]
    fn standby_waits_for_the_running_command_too() {
        let mut machine = modern();
        assert!(machine.try_begin_command());

        assert_eq!(machine.on_signal(PowerSignal::DisplayOff), None);
        assert_eq!(
            machine.state(),
            PowerState::GoingToSleep(SleepKind::Standby)
        );
        assert_eq!(
            machine.end_command(),
            Some(PowerNotice::powering_off(PowerReason::Standby))
        );
    }

    #[test]
    fn no_new_command_while_draining() {
        let mut machine = classic();
        assert!(machine.try_begin_command());
        machine.on_signal(PowerSignal::Suspend);
        machine.end_command();

        assert!(!machine.try_begin_command());
    }

    #[test]
    fn waking_while_draining_cancels_sleep_silently() {
        let mut machine = classic();
        assert!(machine.try_begin_command());
        machine.on_signal(PowerSignal::Suspend);

        assert_eq!(machine.on_signal(PowerSignal::Resume), None);
        assert_eq!(machine.state(), PowerState::Awake);
        assert_eq!(machine.end_command(), None);
        assert_eq!(machine.state(), PowerState::Awake);
    }

    #[test]
    fn only_one_command_at_a_time() {
        let mut machine = classic();

        assert!(machine.try_begin_command());
        assert!(!machine.try_begin_command());
        assert_eq!(machine.end_command(), None);
        assert!(machine.try_begin_command());
    }

    #[test]
    fn repeated_suspend_is_ignored() {
        let mut machine = classic();
        machine.on_signal(PowerSignal::Suspend);

        assert_eq!(machine.on_signal(PowerSignal::Suspend), None);
        assert_eq!(machine.state(), PowerState::Asleep(SleepKind::Sleep));
    }

    #[test]
    fn shutdown_announces_from_any_state() {
        let mut awake = classic();
        assert_eq!(
            awake.on_signal(PowerSignal::Shutdown),
            Some(PowerNotice::powering_off(PowerReason::Shutdown))
        );
        assert_eq!(awake.state(), PowerState::ShuttingDown);

        let mut asleep = modern();
        asleep.on_signal(PowerSignal::DisplayOff);
        assert_eq!(
            asleep.on_signal(PowerSignal::Shutdown),
            Some(PowerNotice::powering_off(PowerReason::Shutdown))
        );
    }

    #[test]
    fn shutdown_does_not_wait_for_a_running_command() {
        let mut machine = classic();
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
        let mut machine = modern();
        machine.on_signal(PowerSignal::Shutdown);

        for signal in [
            PowerSignal::Shutdown,
            PowerSignal::Resume,
            PowerSignal::DisplayOn,
            PowerSignal::Suspend,
            PowerSignal::DisplayOff,
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
                PowerNotice::powering_off(PowerReason::Standby),
                "powering_off",
                "standby",
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
