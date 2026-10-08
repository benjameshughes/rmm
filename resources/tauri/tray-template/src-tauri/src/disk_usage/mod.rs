//! `rmm du`: a synchronous disk-usage scan that prints one line of JSON
//! (schema `rmm.du/1`) for the server to store and draw.
//!
//! The walk and the report are platform-neutral and read directories through
//! [`DirReader`]; `windows.rs` and `unix.rs` supply the readers and the
//! process/volume details.

mod keep_pattern;
mod report;
mod tally;
mod walk;

#[cfg(test)]
mod tests;
#[cfg(not(windows))]
mod unix;
#[cfg(windows)]
mod windows;
#[cfg(windows)]
mod windows_system;
#[cfg(all(test, windows))]
mod windows_tests;

#[cfg(not(windows))]
use unix as platform;
#[cfg(windows)]
use windows_system as platform;

use crate::config::{
    DEFAULT_DU_DEPTH, DEFAULT_DU_MIN_MB, DEFAULT_DU_THREADS, DEFAULT_DU_TOP_FILES,
};
use std::path::{Path, PathBuf};
use std::time::Instant;

/// Node flag: siblings below the size/depth cut, rolled into one "*" node.
pub const FLAG_OTHER: u32 = 1;
/// Node flag: a link, junction, mount point or other filesystem not followed.
pub const FLAG_NOT_FOLLOWED: u32 = 2;
/// Node flag: the folder could not be fully read (usually access denied).
pub const FLAG_INCOMPLETE: u32 = 4;
/// Node flag: a cloud placeholder folder, not opened so nothing is downloaded.
pub const FLAG_CLOUD: u32 = 8;
/// Node flag: kept because it matches a `--keep` pattern.
pub const FLAG_KEPT: u32 = 16;

/// Arguments of `rmm du`.
#[derive(clap::Args, Debug)]
pub struct DuArgs {
    /// Folder or drive to scan, e.g. C:\
    pub path: PathBuf,
    /// Deepest folder level listed (the root is level 0)
    #[arg(long, default_value_t = DEFAULT_DU_DEPTH)]
    pub depth: u32,
    /// Smallest folder listed on its own, in MB
    #[arg(long = "min-mb", default_value_t = DEFAULT_DU_MIN_MB)]
    pub min_mb: u64,
    /// How many of the largest files to list
    #[arg(long, default_value_t = DEFAULT_DU_TOP_FILES)]
    pub top: usize,
    /// Always list folders matching this pattern, relative to the root
    /// (`*` and `?` within one folder name), e.g. Users\*\AppData\Local\Temp
    #[arg(long = "keep", value_name = "GLOB")]
    pub keep: Vec<String>,
    /// Directory reader threads (capped at the CPU count)
    #[arg(long, default_value_t = DEFAULT_DU_THREADS)]
    pub threads: usize,
    /// Run at normal priority instead of background I/O priority
    #[arg(long)]
    pub no_background: bool,
    /// Output is always JSON; accepted so callers can be explicit
    #[arg(long)]
    pub json: bool,
}

/// What a directory entry is, as far as the walk cares. Each platform's
/// reader produces only some of these.
#[allow(dead_code)]
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum EntryKind {
    File,
    Dir,
    /// Junction, symlink or mount point: listed, never entered.
    LinkNotFollowed,
    /// Folder on another filesystem: listed, never entered.
    OtherVolume,
    /// Cloud placeholder folder that would download when opened.
    CloudDir,
}

/// One directory entry as a reader reports it.
#[derive(Debug, Clone)]
pub struct Entry {
    pub name: String,
    pub kind: EntryKind,
    pub allocated: u64,
    pub logical: u64,
    pub attributes: u32,
    /// Last write time, Unix seconds.
    pub modified: i64,
    /// (volume, file id) for files that may be hard links; first sighting wins.
    pub link_key: Option<(u64, u64)>,
    /// Content lives in the cloud (not stored locally).
    pub is_cloud: bool,
}

/// Everything read from one directory, including what failed.
#[derive(Debug, Default, Clone)]
pub struct DirListing {
    pub entries: Vec<Entry>,
    /// (path, OS error code)
    pub errors: Vec<(PathBuf, i32)>,
}

impl DirListing {
    pub fn failed(path: &Path, code: i32) -> Self {
        Self {
            entries: Vec::new(),
            errors: vec![(path.to_path_buf(), code)],
        }
    }
}

/// Reads directories for the walk. Implementations must never follow links.
pub trait DirReader: Sync {
    fn read_dir(&self, path: &Path) -> DirListing;

    /// Re-read one file's (allocated, logical) size straight from the file,
    /// for readers whose directory listing can be stale.
    fn refresh_size(&self, _path: &Path) -> Option<(u64, u64)> {
        None
    }
}

/// Size and filesystem of the volume holding the root.
#[derive(Debug, Clone)]
pub struct Volume {
    pub total: u64,
    pub free: u64,
    pub fs: String,
    /// The root is the volume's own root, so used space can be reconciled.
    pub is_volume_root: bool,
}

/// Run the scan and print the report. Returns the process exit code:
/// 0 when everything was read, 1 when some paths failed.
pub fn run(args: &DuArgs) -> i32 {
    let started_at = chrono::Utc::now();
    let clock = Instant::now();

    if !args.no_background {
        platform::enter_background();
    }
    platform::enable_backup_privilege();

    let root = platform::normalize_root(&args.path);
    let reader = platform::reader(&root);
    let walk_options = walk::WalkOptions {
        threads: thread_count(args.threads),
        top: args.top,
        deferred: platform::deferred_dirs(&root),
    };
    let scan = walk::walk(&root, &reader, &walk_options);

    let report_options = report::ReportOptions {
        root: root.to_string_lossy().into_owned(),
        max_depth: args.depth,
        min_bytes: args.min_mb.saturating_mul(1024 * 1024),
        keep: args
            .keep
            .iter()
            .map(|glob| keep_pattern::KeepPattern::parse(glob))
            .collect(),
        volume: platform::volume_info(&root),
        started_at: report::iso8601(started_at.timestamp()),
        duration_ms: clock.elapsed().as_millis() as u64,
    };
    println!("{}", report::render(&scan, &report_options));

    if scan.tally.errors.count > 0 {
        1
    } else {
        0
    }
}

fn thread_count(requested: usize) -> usize {
    let available = std::thread::available_parallelism().map_or(1, |count| count.get());
    requested.clamp(1, available)
}
