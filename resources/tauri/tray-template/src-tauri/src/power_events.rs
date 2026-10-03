//! Maps Windows service power events to power signals.
//!
//! Only classic sleep/resume is acted on. Modern Standby PCs never send
//! PBT_APMSUSPEND and are treated as awake; display on/off is ignored because
//! Wake-on-LAN wakes them "dark" and commands must still run then.

use crate::power_state::PowerSignal;
use windows_service::service::PowerEventParam;

/// Map a SERVICE_CONTROL_POWEREVENT to a power signal. None for events the
/// agent does not act on (battery, AC/DC, power settings, query suspend, ...).
pub fn signal_from_power_event(param: &PowerEventParam) -> Option<PowerSignal> {
    match param {
        PowerEventParam::Suspend => Some(PowerSignal::Suspend),
        PowerEventParam::ResumeAutomatic
        | PowerEventParam::ResumeSuspend
        | PowerEventParam::ResumeCritical => Some(PowerSignal::Resume),
        _ => None,
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use windows_service::service::{DisplayState, PowerBroadcastSetting};

    #[test]
    fn maps_sleep_and_resume_events() {
        assert_eq!(
            signal_from_power_event(&PowerEventParam::Suspend),
            Some(PowerSignal::Suspend)
        );
        for resume in [
            PowerEventParam::ResumeAutomatic,
            PowerEventParam::ResumeSuspend,
            PowerEventParam::ResumeCritical,
        ] {
            assert_eq!(signal_from_power_event(&resume), Some(PowerSignal::Resume));
        }
    }

    #[test]
    fn ignores_display_state_and_other_events() {
        for param in [
            PowerEventParam::PowerStatusChange,
            PowerEventParam::BatteryLow,
            PowerEventParam::QuerySuspend,
            PowerEventParam::QuerySuspendFailed,
            PowerEventParam::OemEvent,
            PowerEventParam::PowerSettingChange(PowerBroadcastSetting::ConsoleDisplayState(
                DisplayState::Off,
            )),
            PowerEventParam::PowerSettingChange(PowerBroadcastSetting::ConsoleDisplayState(
                DisplayState::On,
            )),
        ] {
            assert_eq!(signal_from_power_event(&param), None, "{param:?}");
        }
    }
}
