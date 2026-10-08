//! Windows print spooler access: a listener thread that wakes on any printer
//! or job change, and the raw snapshot read (EnumPrinters + EnumJobs).
//!
//! All calls here block, so the listener owns a dedicated std thread and the
//! snapshot runs on tokio's blocking pool.

use std::ptr::null_mut;
use std::thread::JoinHandle;
use std::time::{Duration, Instant};
use tokio::sync::mpsc;
use tokio_util::sync::CancellationToken;
use tracing::{debug, warn};
use winapi::shared::minwindef::{BOOL, DWORD, FALSE, LPBYTE};
use winapi::shared::winerror::{ERROR_INSUFFICIENT_BUFFER, WAIT_TIMEOUT};
use winapi::um::errhandlingapi::GetLastError;
use winapi::um::handleapi::INVALID_HANDLE_VALUE;
use winapi::um::synchapi::WaitForSingleObject;
use winapi::um::winbase::WAIT_OBJECT_0;
use winapi::um::winnt::{HANDLE, LPWSTR};
use winapi::um::winspool::{
    ClosePrinter, EnumJobsW, EnumPrintersW, FindClosePrinterChangeNotification,
    FindFirstPrinterChangeNotification, FindNextPrinterChangeNotification, OpenPrinterW,
    JOB_INFO_2W, PRINTER_CHANGE_JOB, PRINTER_CHANGE_PRINTER, PRINTER_ENUM_CONNECTIONS,
    PRINTER_ENUM_LOCAL, PRINTER_INFO_2W,
};

use crate::config::Config;
use crate::printers::{systemtime_to_iso, Debouncer, PrintJob, Printer, SpoolerSnapshot};

/// Printer handle from OpenPrinterW, closed on drop.
struct PrinterHandle(HANDLE);

impl PrinterHandle {
    /// `None` opens the local print server.
    fn open(name: Option<&mut [u16]>) -> Result<Self, DWORD> {
        let name_ptr = name.map_or(null_mut(), |name| name.as_mut_ptr());
        let mut handle: HANDLE = null_mut();
        if unsafe { OpenPrinterW(name_ptr, &mut handle, null_mut()) } == FALSE {
            return Err(unsafe { GetLastError() });
        }

        Ok(Self(handle))
    }
}

impl Drop for PrinterHandle {
    fn drop(&mut self) {
        unsafe { ClosePrinter(self.0) };
    }
}

/// Change notification from FindFirstPrinterChangeNotification, closed on drop.
struct ChangeNotification(HANDLE);

impl ChangeNotification {
    fn subscribe(server: &PrinterHandle) -> Result<Self, DWORD> {
        let filter = PRINTER_CHANGE_PRINTER | PRINTER_CHANGE_JOB;
        let handle = unsafe { FindFirstPrinterChangeNotification(server.0, filter, 0, null_mut()) };
        if handle.is_null() || handle == INVALID_HANDLE_VALUE {
            return Err(unsafe { GetLastError() });
        }

        Ok(Self(handle))
    }
}

impl Drop for ChangeNotification {
    fn drop(&mut self) {
        unsafe { FindClosePrinterChangeNotification(self.0) };
    }
}

/// A live subscription. Fields drop in order: the notification closes
/// before the server handle it was opened on.
struct Subscription {
    notification: ChangeNotification,
    _server: PrinterHandle,
}

impl Subscription {
    fn open() -> Result<Self, String> {
        let server = PrinterHandle::open(None)
            .map_err(|code| format!("OpenPrinter(local server) failed: error {code}"))?;
        let notification = ChangeNotification::subscribe(&server)
            .map_err(|code| format!("FindFirstPrinterChangeNotification failed: error {code}"))?;

        Ok(Self {
            notification,
            _server: server,
        })
    }
}

/// Why a subscription ended.
enum ListenEnd {
    Cancelled,
    Lost(String),
    Resubscribe,
}

/// Start the listener thread. The receiver gets one message per debounced
/// burst of spooler changes (extra messages are dropped while one waits).
pub fn spawn_listener(
    config: &Config,
    cancellation_token: CancellationToken,
) -> std::io::Result<(mpsc::Receiver<()>, JoinHandle<()>)> {
    let (sender, receiver) = mpsc::channel(1);
    let config = config.clone();
    let handle = std::thread::Builder::new()
        .name("printer-listener".into())
        .spawn(move || run_listener(&config, &sender, &cancellation_token))?;

    Ok((receiver, handle))
}

/// Subscribe, listen, and re-subscribe with backoff whenever the spooler is
/// lost (stopped, restarted, handle gone) until cancelled.
fn run_listener(
    config: &Config,
    sender: &mpsc::Sender<()>,
    cancellation_token: &CancellationToken,
) {
    let retry_min = Duration::from_secs(config.printer_retry_min);
    let retry_max = Duration::from_secs(config.printer_retry_max);
    let mut retry = retry_min;

    while !cancellation_token.is_cancelled() {
        let ended = match Subscription::open() {
            Ok(subscription) => {
                debug!("Listening for printer and job changes");
                retry = retry_min;
                listen(
                    config,
                    &subscription.notification,
                    sender,
                    cancellation_token,
                )
            }
            Err(reason) => ListenEnd::Lost(reason),
        };

        match ended {
            ListenEnd::Cancelled => break,
            ListenEnd::Resubscribe => debug!("Re-subscribing to spooler changes"),
            ListenEnd::Lost(reason) => {
                if retry == retry_min {
                    warn!("Printer listener lost the spooler ({reason}); retrying");
                    // Report straight away: a change may have been missed.
                    let _ = sender.try_send(());
                } else {
                    debug!("Spooler still unavailable ({reason}); retrying");
                }
                sleep_unless_cancelled(retry, config, cancellation_token);
                retry = (retry * 2).min(retry_max);
            }
        }
    }

    debug!("Printer listener stopped");
}

/// Wait on one subscription, signalling the debounced bursts.
fn listen(
    config: &Config,
    notification: &ChangeNotification,
    sender: &mpsc::Sender<()>,
    cancellation_token: &CancellationToken,
) -> ListenEnd {
    let slice = Duration::from_millis(config.printer_wait_slice_ms);
    let resubscribe_at = Instant::now() + Duration::from_secs(config.printer_resubscribe);
    let mut debouncer = Debouncer::new(
        Duration::from_millis(config.printer_quiet_ms),
        Duration::from_millis(config.printer_max_delay_ms),
    );

    while !cancellation_token.is_cancelled() {
        let wait = debouncer.next_wait(Instant::now(), slice);
        let waited = unsafe { WaitForSingleObject(notification.0, wait.as_millis() as DWORD) };

        match waited {
            WAIT_OBJECT_0 => {
                let mut change: DWORD = 0;
                let ok = unsafe {
                    FindNextPrinterChangeNotification(
                        notification.0,
                        &mut change,
                        null_mut(),
                        null_mut(),
                    )
                };
                if ok == FALSE {
                    return ListenEnd::Lost(format!(
                        "FindNextPrinterChangeNotification failed: error {}",
                        unsafe { GetLastError() }
                    ));
                }
                debouncer.signal(Instant::now());
            }
            WAIT_TIMEOUT => {}
            other => {
                return ListenEnd::Lost(format!(
                    "WaitForSingleObject returned {other:#x}, error {}",
                    unsafe { GetLastError() }
                ));
            }
        }

        if debouncer.take_due(Instant::now()) {
            let _ = sender.try_send(());
        }
        if Instant::now() >= resubscribe_at && !debouncer.is_pending() {
            return ListenEnd::Resubscribe;
        }
    }

    ListenEnd::Cancelled
}

fn sleep_unless_cancelled(
    total: Duration,
    config: &Config,
    cancellation_token: &CancellationToken,
) {
    let slice = Duration::from_millis(config.printer_wait_slice_ms);
    let until = Instant::now() + total;
    while !cancellation_token.is_cancelled() && Instant::now() < until {
        std::thread::sleep(slice.min(until.saturating_duration_since(Instant::now())));
    }
}

/// Read every local and connected printer with its full queue. A spooler
/// that cannot be enumerated is reported as unavailable.
pub fn collect_snapshot() -> SpoolerSnapshot {
    let flags = PRINTER_ENUM_LOCAL | PRINTER_ENUM_CONNECTIONS;
    let enumerated = enumerate(|buffer, size, needed, returned| unsafe {
        EnumPrintersW(flags, null_mut(), 2, buffer, size, needed, returned)
    });

    let (buffer, count) = match enumerated {
        Ok(result) => result,
        Err(code) => {
            debug!("EnumPrinters failed (error {code}); spooler unavailable");
            return SpoolerSnapshot::unavailable();
        }
    };

    let infos =
        unsafe { std::slice::from_raw_parts(buffer.as_ptr() as *const PRINTER_INFO_2W, count) };

    SpoolerSnapshot {
        spooler_available: true,
        printers: infos.iter().map(read_printer).collect(),
    }
}

fn read_printer(info: &PRINTER_INFO_2W) -> Printer {
    let name = unsafe { wide_to_string(info.pPrinterName) }.unwrap_or_default();

    Printer {
        jobs: read_jobs(&name),
        name,
        port_name: unsafe { wide_to_string(info.pPortName) }.unwrap_or_default(),
        driver_name: unsafe { wide_to_string(info.pDriverName) }.unwrap_or_default(),
        status: info.Status,
        attributes: info.Attributes,
        jobs_count: info.cJobs,
    }
}

/// Every job on one printer. A printer that cannot be opened (e.g. an
/// unreachable network connection) reports no jobs; its raw status still goes.
fn read_jobs(printer_name: &str) -> Vec<PrintJob> {
    let mut wide_name: Vec<u16> = printer_name.encode_utf16().chain(Some(0)).collect();
    let printer = match PrinterHandle::open(Some(wide_name.as_mut_slice())) {
        Ok(printer) => printer,
        Err(code) => {
            debug!("OpenPrinter({printer_name}) failed: error {code}");
            return Vec::new();
        }
    };

    let enumerated = enumerate(|buffer, size, needed, returned| unsafe {
        EnumJobsW(printer.0, 0, DWORD::MAX, 2, buffer, size, needed, returned)
    });
    let (buffer, count) = match enumerated {
        Ok(result) => result,
        Err(code) => {
            debug!("EnumJobs({printer_name}) failed: error {code}");
            return Vec::new();
        }
    };

    let jobs = unsafe { std::slice::from_raw_parts(buffer.as_ptr() as *const JOB_INFO_2W, count) };
    jobs.iter().map(read_job).collect()
}

fn read_job(job: &JOB_INFO_2W) -> PrintJob {
    let submitted = job.Submitted;

    PrintJob {
        id: job.JobId,
        document: unsafe { wide_to_string(job.pDocument) }.unwrap_or_default(),
        user_name: unsafe { wide_to_string(job.pUserName) }.unwrap_or_default(),
        status: job.Status,
        status_text: unsafe { wide_to_string(job.pStatus) },
        submitted: systemtime_to_iso(
            submitted.wYear,
            submitted.wMonth,
            submitted.wDay,
            submitted.wHour,
            submitted.wMinute,
            submitted.wSecond,
            submitted.wMilliseconds,
        ),
        total_pages: job.TotalPages,
        pages_printed: job.PagesPrinted,
        size: job.Size,
        position: job.Position,
        priority: job.Priority,
    }
}

/// Run an Enum* call with the size-then-fill dance, retrying if the list grew
/// in between. The buffer is u64-backed so the info structs are aligned.
fn enumerate<F>(mut call: F) -> Result<(Vec<u64>, usize), DWORD>
where
    F: FnMut(LPBYTE, DWORD, &mut DWORD, &mut DWORD) -> BOOL,
{
    let mut buffer: Vec<u64> = Vec::new();

    for _ in 0..3 {
        let size = (buffer.len() * std::mem::size_of::<u64>()) as DWORD;
        let mut needed: DWORD = 0;
        let mut returned: DWORD = 0;
        let pointer = if buffer.is_empty() {
            null_mut()
        } else {
            buffer.as_mut_ptr() as LPBYTE
        };

        if call(pointer, size, &mut needed, &mut returned) != FALSE {
            return Ok((buffer, returned as usize));
        }

        let code = unsafe { GetLastError() };
        if code != ERROR_INSUFFICIENT_BUFFER {
            return Err(code);
        }
        buffer = vec![0u64; (needed as usize).div_ceil(std::mem::size_of::<u64>())];
    }

    Err(ERROR_INSUFFICIENT_BUFFER)
}

/// Null-terminated UTF-16 to String; None for a null pointer.
///
/// # Safety
/// `pointer` must be null or point to a null-terminated UTF-16 string.
unsafe fn wide_to_string(pointer: LPWSTR) -> Option<String> {
    if pointer.is_null() {
        return None;
    }

    let mut length = 0;
    while *pointer.add(length) != 0 {
        length += 1;
    }

    Some(String::from_utf16_lossy(std::slice::from_raw_parts(
        pointer, length,
    )))
}
