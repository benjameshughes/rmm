//! Windows power notifications for the service.
//!
//! Classic S3 machines send SERVICE_CONTROL_POWEREVENT with PBT_APMSUSPEND /
//! PBT_APMRESUME*. Modern Standby machines never send PBT_APMSUSPEND: they
//! enter standby when the display turns off, so on those the service also
//! registers for GUID_CONSOLE_DISPLAY_STATE changes. Everything here only
//! translates; decisions live in `power_state.rs`.

#[cfg(windows)]
pub use self::windows::{signal_from_power_event, DisplayStateRegistration};

/// True on Modern Standby (S0 low power idle, "AoAc") machines. Always false
/// off Windows.
pub fn modern_standby_supported() -> bool {
    #[cfg(windows)]
    {
        windows::modern_standby_supported()
    }

    #[cfg(not(windows))]
    {
        false
    }
}

#[cfg(windows)]
mod windows {
    use crate::power_state::PowerSignal;
    use std::os::windows::io::RawHandle;
    use tracing::warn;
    use winapi::um::powerbase::CallNtPowerInformation;
    use winapi::um::winnt::{
        SystemPowerCapabilities, GUID_CONSOLE_DISPLAY_STATE, HANDLE, SYSTEM_POWER_CAPABILITIES,
    };
    use winapi::um::winuser::{
        RegisterPowerSettingNotification, UnregisterPowerSettingNotification,
        DEVICE_NOTIFY_SERVICE_HANDLE, HPOWERNOTIFY,
    };
    use windows_service::service::{DisplayState, PowerBroadcastSetting, PowerEventParam};

    pub fn modern_standby_supported() -> bool {
        let mut capabilities: SYSTEM_POWER_CAPABILITIES = unsafe { std::mem::zeroed() };

        let status = unsafe {
            CallNtPowerInformation(
                SystemPowerCapabilities,
                std::ptr::null_mut(),
                0,
                &mut capabilities as *mut SYSTEM_POWER_CAPABILITIES as *mut _,
                std::mem::size_of::<SYSTEM_POWER_CAPABILITIES>() as _,
            )
        };

        // STATUS_SUCCESS
        if status != 0 {
            warn!(
                "Could not read power capabilities (NTSTATUS {:#x}); assuming classic sleep",
                status
            );
            return false;
        }

        capabilities.AoAc != 0
    }

    /// Map a SERVICE_CONTROL_POWEREVENT to a power signal. None for events
    /// the agent does not act on (battery, AC/DC, query suspend, ...).
    pub fn signal_from_power_event(param: &PowerEventParam) -> Option<PowerSignal> {
        match param {
            PowerEventParam::Suspend => Some(PowerSignal::Suspend),
            PowerEventParam::ResumeAutomatic
            | PowerEventParam::ResumeSuspend
            | PowerEventParam::ResumeCritical => Some(PowerSignal::Resume),
            PowerEventParam::PowerSettingChange(PowerBroadcastSetting::ConsoleDisplayState(
                state,
            )) => Some(match state {
                DisplayState::Off => PowerSignal::DisplayOff,
                DisplayState::On => PowerSignal::DisplayOn,
                DisplayState::Dimmed => PowerSignal::DisplayDimmed,
            }),
            _ => None,
        }
    }

    /// GUID_CONSOLE_DISPLAY_STATE notifications for the service; unregistered
    /// on drop.
    pub struct DisplayStateRegistration(HPOWERNOTIFY);

    // HPOWERNOTIFY is an opaque registration handle, usable from any thread;
    // it is dropped from the service control handler on stop.
    unsafe impl Send for DisplayStateRegistration {}

    impl DisplayStateRegistration {
        /// `status_handle` is the SERVICE_STATUS_HANDLE from registering the
        /// service control handler.
        pub fn register(status_handle: RawHandle) -> Option<Self> {
            let registration = unsafe {
                RegisterPowerSettingNotification(
                    status_handle as HANDLE,
                    &GUID_CONSOLE_DISPLAY_STATE,
                    DEVICE_NOTIFY_SERVICE_HANDLE,
                )
            };

            if registration.is_null() {
                warn!(
                    "Could not register for display state changes: {}; standby will not be detected",
                    std::io::Error::last_os_error()
                );
                return None;
            }

            Some(Self(registration))
        }
    }

    impl Drop for DisplayStateRegistration {
        fn drop(&mut self) {
            unsafe {
                UnregisterPowerSettingNotification(self.0);
            }
        }
    }

    #[cfg(test)]
    mod tests {
        use super::*;

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
        fn maps_console_display_state() {
            let display = |state| {
                signal_from_power_event(&PowerEventParam::PowerSettingChange(
                    PowerBroadcastSetting::ConsoleDisplayState(state),
                ))
            };

            assert_eq!(display(DisplayState::Off), Some(PowerSignal::DisplayOff));
            assert_eq!(display(DisplayState::On), Some(PowerSignal::DisplayOn));
            assert_eq!(
                display(DisplayState::Dimmed),
                Some(PowerSignal::DisplayDimmed)
            );
        }

        #[test]
        fn ignores_other_power_events() {
            for param in [
                PowerEventParam::PowerStatusChange,
                PowerEventParam::BatteryLow,
                PowerEventParam::QuerySuspend,
                PowerEventParam::QuerySuspendFailed,
                PowerEventParam::OemEvent,
                PowerEventParam::PowerSettingChange(
                    PowerBroadcastSetting::BatteryPercentageRemaining(50),
                ),
            ] {
                assert_eq!(signal_from_power_event(&param), None, "{param:?}");
            }
        }

        #[test]
        fn reads_power_capabilities() {
            // Either answer is fine; the call itself must succeed and not crash.
            let _ = modern_standby_supported();
        }
    }
}
