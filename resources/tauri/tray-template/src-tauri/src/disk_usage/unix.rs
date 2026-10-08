//! Unix side of `rmm du`: std directory reads, never following symlinks and
//! never leaving the root's filesystem.

use super::{DirListing, DirReader, Entry, EntryKind, Volume};
use std::os::unix::fs::MetadataExt;
use std::path::{Path, PathBuf};

/// `st_blocks` is always counted in 512-byte units.
const BLOCK_BYTES: u64 = 512;

pub struct StdDirReader {
    /// Device of the scan root; folders on any other device are not entered.
    device: u64,
}

impl StdDirReader {
    pub fn new(root: &Path) -> Self {
        Self {
            device: std::fs::symlink_metadata(root).map_or(0, |metadata| metadata.dev()),
        }
    }
}

impl DirReader for StdDirReader {
    fn read_dir(&self, path: &Path) -> DirListing {
        let entries = match std::fs::read_dir(path) {
            Ok(entries) => entries,
            Err(error) => return DirListing::failed(path, error.raw_os_error().unwrap_or(-1)),
        };
        let mut listing = DirListing::default();

        for entry in entries {
            let entry = match entry {
                Ok(entry) => entry,
                Err(error) => {
                    listing
                        .errors
                        .push((path.to_path_buf(), error.raw_os_error().unwrap_or(-1)));
                    continue;
                }
            };
            // DirEntry::metadata is lstat on Unix: symlinks are never followed.
            match entry.metadata() {
                Ok(metadata) => listing
                    .entries
                    .push(self.entry(entry.file_name(), &metadata)),
                Err(error) => listing
                    .errors
                    .push((entry.path(), error.raw_os_error().unwrap_or(-1))),
            }
        }

        listing
    }
}

impl StdDirReader {
    fn entry(&self, name: std::ffi::OsString, metadata: &std::fs::Metadata) -> Entry {
        let is_dir = metadata.file_type().is_dir();
        let kind = match (is_dir, metadata.dev() == self.device) {
            (false, _) => EntryKind::File,
            (true, true) => EntryKind::Dir,
            (true, false) => EntryKind::OtherVolume,
        };
        let is_hard_linked = !is_dir && metadata.nlink() > 1;

        Entry {
            name: name.to_string_lossy().into_owned(),
            kind,
            allocated: metadata.blocks() * BLOCK_BYTES,
            logical: metadata.len(),
            attributes: metadata.mode(),
            modified: metadata.mtime(),
            link_key: is_hard_linked.then(|| (metadata.dev(), metadata.ino())),
            is_cloud: false,
        }
    }
}

pub fn reader(root: &Path) -> StdDirReader {
    StdDirReader::new(root)
}

pub fn normalize_root(path: &Path) -> PathBuf {
    std::path::absolute(path).unwrap_or_else(|_| path.to_path_buf())
}

pub fn deferred_dirs(_root: &Path) -> Vec<PathBuf> {
    Vec::new()
}

pub fn enter_background() {}

pub fn enable_backup_privilege() {}

/// The mounted filesystem holding the root (longest matching mount point).
pub fn volume_info(root: &Path) -> Option<Volume> {
    let disks = sysinfo::Disks::new_with_refreshed_list();
    let disk = disks
        .list()
        .iter()
        .filter(|disk| root.starts_with(disk.mount_point()))
        .max_by_key(|disk| disk.mount_point().as_os_str().len())?;

    Some(Volume {
        total: disk.total_space(),
        free: disk.available_space(),
        fs: disk.file_system().to_string_lossy().into_owned(),
        is_volume_root: false,
    })
}

#[cfg(test)]
mod tests {
    use super::super::walk::{walk, WalkOptions};
    use super::*;

    fn scan(root: &Path) -> super::super::walk::Scan {
        let options = WalkOptions {
            threads: 2,
            top: 10,
            deferred: Vec::new(),
        };
        walk(root, &StdDirReader::new(root), &options)
    }

    #[test]
    fn allocated_comes_from_st_blocks() {
        let dir = tempfile::tempdir().unwrap();
        let file = dir.path().join("data.bin");
        std::fs::write(&file, vec![7u8; 10_000]).unwrap();
        let blocks = std::fs::metadata(&file).unwrap().blocks();

        let scan = scan(dir.path());

        assert_eq!(scan.nodes[0].allocated, blocks * BLOCK_BYTES);
        assert_eq!(scan.nodes[0].logical, 10_000);
        assert!(scan.nodes[0].allocated > 0);
    }

    #[test]
    fn hard_links_are_counted_once() {
        let dir = tempfile::tempdir().unwrap();
        let original = dir.path().join("a.bin");
        std::fs::write(&original, vec![1u8; 50_000]).unwrap();
        std::fs::hard_link(&original, dir.path().join("b.bin")).unwrap();
        let allocated = std::fs::metadata(&original).unwrap().blocks() * BLOCK_BYTES;

        let scan = scan(dir.path());

        assert_eq!(scan.nodes[0].files, 1);
        assert_eq!(scan.nodes[0].allocated, allocated);
        assert_eq!(scan.tally.deduped_bytes, allocated);
    }

    #[test]
    fn symlinked_folders_are_not_followed() {
        let dir = tempfile::tempdir().unwrap();
        let target = tempfile::tempdir().unwrap();
        std::fs::write(target.path().join("big.bin"), vec![1u8; 100_000]).unwrap();
        std::os::unix::fs::symlink(target.path(), dir.path().join("link")).unwrap();

        let scan = scan(dir.path());

        assert_eq!(scan.nodes.len(), 1);
        assert_eq!(scan.nodes[0].files, 1);
        assert!(scan.nodes[0].logical < 100_000);
    }

    #[test]
    fn an_unreadable_root_is_reported_not_fatal() {
        let scan = scan(Path::new("/definitely/not/here"));

        assert_eq!(scan.tally.errors.count, 1);
        assert_eq!(scan.nodes[0].flags, crate::disk_usage::FLAG_INCOMPLETE);
    }
}
