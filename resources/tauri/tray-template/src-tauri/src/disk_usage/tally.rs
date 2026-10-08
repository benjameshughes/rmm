//! Per-worker counters gathered during the walk and merged at the end, so
//! workers never contend over them.

use super::Entry;
use crate::config::DU_ERROR_SAMPLE;
use std::cmp::Reverse;
use std::collections::{BinaryHeap, HashMap};
use std::path::Path;

/// A file worth reporting. Field order sets the heap order: size first.
#[derive(Debug, Clone, PartialEq, Eq, PartialOrd, Ord)]
pub struct FileRecord {
    pub allocated: u64,
    pub logical: u64,
    pub name: String,
    /// Index of the folder holding it.
    pub node: usize,
    pub modified: i64,
    pub attributes: u32,
}

/// Failed paths: all counted, the first few kept.
#[derive(Debug, Default)]
pub struct ErrorLog {
    pub count: u64,
    pub sample: Vec<(String, i32)>,
}

impl ErrorLog {
    pub fn record(&mut self, path: &Path, code: i32) {
        self.count += 1;
        if self.sample.len() < DU_ERROR_SAMPLE {
            self.sample
                .push((path.to_string_lossy().into_owned(), code));
        }
    }

    fn merge(&mut self, other: ErrorLog) {
        self.count += other.count;
        let room = DU_ERROR_SAMPLE.saturating_sub(self.sample.len());
        self.sample.extend(other.sample.into_iter().take(room));
    }
}

#[derive(Debug, Default)]
pub struct Tally {
    limit: usize,
    /// Smallest of the current top files on top, so it is the one evicted.
    largest: BinaryHeap<Reverse<FileRecord>>,
    pub root_files: Vec<FileRecord>,
    /// extension -> (allocated, count)
    pub extensions: HashMap<String, (u64, u64)>,
    pub errors: ErrorLog,
    pub deduped_bytes: u64,
    pub cloud_logical: u64,
    pub reparse_skipped: u64,
}

impl Tally {
    pub fn new(limit: usize) -> Self {
        Self {
            limit,
            ..Self::default()
        }
    }

    pub fn count_file(&mut self, node: usize, is_in_root: bool, entry: &Entry) {
        if entry.is_cloud {
            self.cloud_logical += entry.logical;
        }
        if let Some(extension) = extension_of(&entry.name) {
            let slot = self.extensions.entry(extension).or_default();
            slot.0 += entry.allocated;
            slot.1 += 1;
        }

        let wanted = self.wants(entry.allocated);
        if !wanted && !is_in_root {
            return;
        }
        let record = FileRecord {
            allocated: entry.allocated,
            logical: entry.logical,
            name: entry.name.clone(),
            node,
            modified: entry.modified,
            attributes: entry.attributes,
        };
        if is_in_root {
            self.root_files.push(record.clone());
        }
        if wanted {
            self.offer(record);
        }
    }

    fn wants(&self, allocated: u64) -> bool {
        if self.largest.len() < self.limit {
            return true;
        }
        self.largest
            .peek()
            .is_some_and(|smallest| allocated > smallest.0.allocated)
    }

    fn offer(&mut self, record: FileRecord) {
        if !self.wants(record.allocated) {
            return;
        }
        if self.largest.len() >= self.limit {
            self.largest.pop();
        }
        self.largest.push(Reverse(record));
    }

    pub fn merge(&mut self, other: Tally) {
        for Reverse(record) in other.largest {
            self.offer(record);
        }
        self.root_files.extend(other.root_files);
        for (extension, (allocated, count)) in other.extensions {
            let slot = self.extensions.entry(extension).or_default();
            slot.0 += allocated;
            slot.1 += count;
        }
        self.errors.merge(other.errors);
        self.deduped_bytes += other.deduped_bytes;
        self.cloud_logical += other.cloud_logical;
        self.reparse_skipped += other.reparse_skipped;
    }

    /// The largest files, then any root-level file not already among them.
    pub fn files_of_note(&self) -> Vec<FileRecord> {
        let mut files: Vec<FileRecord> = self.largest.iter().map(|r| r.0.clone()).collect();
        for file in &self.root_files {
            let listed = files
                .iter()
                .any(|f| f.node == file.node && f.name == file.name);
            if !listed {
                files.push(file.clone());
            }
        }
        files
    }
}

/// Lower-cased extension with its dot, e.g. ".ost"; none for dotfiles.
fn extension_of(name: &str) -> Option<String> {
    let (stem, extension) = name.rsplit_once('.')?;
    if stem.is_empty() || extension.is_empty() {
        return None;
    }
    Some(format!(".{}", extension.to_lowercase()))
}
