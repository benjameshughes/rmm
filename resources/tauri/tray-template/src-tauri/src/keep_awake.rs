//! Holds the machine awake while a command runs.
//!
//! Modern Standby PCs wake for a few minutes of maintenance, the agent picks
//! up pending work, then Windows drops back into standby and the script stalls
//! until it times out. A power request (SystemRequired + ExecutionRequired)
//! asks Windows to wait until the work is done; dropping the guard clears it
//! so the machine can sleep again. Windows can still overrule it: power
//! button, lid, low battery.

/// Keeps the machine awake until dropped. On other platforms it does nothing.
#[cfg_attr(not(windows), allow(dead_code))]
pub struct KeepAwake {
    // Only held for its Drop, which clears the request.
    #[cfg(windows)]
    _request: windows::PowerRequest,
}

impl KeepAwake {
    /// Ask Windows to stay awake. `None` when the request could not be made;
    /// the command still runs, it just is not protected from standby.
    pub fn acquire(reason: &str) -> Option<Self> {
        #[cfg(windows)]
        {
            windows::PowerRequest::create(reason).map(|request| Self { _request: request })
        }

        #[cfg(not(windows))]
        {
            let _ = reason;
            None
        }
    }

    /// Whether Windows accepted the request to keep the system running.
    #[cfg(all(windows, test))]
    pub fn is_holding(&self) -> bool {
        self._request.is_holding()
    }
}

#[cfg(windows)]
mod windows {
    use tracing::warn;
    use winapi::um::handleapi::{CloseHandle, INVALID_HANDLE_VALUE};
    use winapi::um::minwinbase::REASON_CONTEXT;
    use winapi::um::winbase::{PowerClearRequest, PowerCreateRequest, PowerSetRequest};
    use winapi::um::winnt::{
        PowerRequestExecutionRequired, PowerRequestSystemRequired, HANDLE,
        POWER_REQUEST_CONTEXT_SIMPLE_STRING, POWER_REQUEST_CONTEXT_VERSION, POWER_REQUEST_TYPE,
    };

    pub struct PowerRequest {
        handle: HANDLE,
        held: Vec<POWER_REQUEST_TYPE>,
        // The reason string must outlive the request that points at it.
        _reason: Vec<u16>,
    }

    // A power request handle is a kernel object handle, usable from any thread;
    // the guard lives across awaits in the command runner.
    unsafe impl Send for PowerRequest {}

    impl PowerRequest {
        pub fn create(reason: &str) -> Option<Self> {
            let mut wide: Vec<u16> = reason.encode_utf16().chain(std::iter::once(0)).collect();

            let handle = unsafe {
                let mut context: REASON_CONTEXT = std::mem::zeroed();
                context.Version = POWER_REQUEST_CONTEXT_VERSION;
                context.Flags = POWER_REQUEST_CONTEXT_SIMPLE_STRING;
                *context.Reason.SimpleReasonString_mut() = wide.as_mut_ptr();
                PowerCreateRequest(&mut context)
            };

            if handle.is_null() || handle == INVALID_HANDLE_VALUE {
                warn!("Could not create a power request; the command may stall if Windows sleeps");
                return None;
            }

            let held: Vec<POWER_REQUEST_TYPE> = [PowerRequestSystemRequired, PowerRequestExecutionRequired]
                .into_iter()
                .filter(|request_type| unsafe { PowerSetRequest(handle, *request_type) } != 0)
                .collect();

            if held.is_empty() {
                warn!("Windows refused the power request; the command may stall if Windows sleeps");
                unsafe { CloseHandle(handle) };
                return None;
            }

            Some(Self { handle, held, _reason: wide })
        }

        #[cfg(test)]
        pub fn is_holding(&self) -> bool {
            !self.held.is_empty()
        }
    }

    impl Drop for PowerRequest {
        fn drop(&mut self) {
            unsafe {
                self.held.iter().for_each(|request_type| {
                    PowerClearRequest(self.handle, *request_type);
                });
                CloseHandle(self.handle);
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[cfg(not(windows))]
    #[test]
    fn does_nothing_off_windows() {
        assert!(KeepAwake::acquire("test").is_none());
    }

    #[cfg(windows)]
    #[test]
    fn holds_and_releases_on_windows() {
        let guard = KeepAwake::acquire("BenJH RMM test").expect("power request");
        assert!(guard.is_holding());
        drop(guard);
    }
}
