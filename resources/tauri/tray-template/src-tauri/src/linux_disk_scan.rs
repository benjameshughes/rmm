//! The Linux agent's own disk-usage scans. It takes no commands, so instead
//! of waiting for the panel to ask it walks every real mounted filesystem on
//! a schedule with the same walker and `rmm.du/1` report as `rmm du`, at the
//! lowest CPU and I/O priority, and hands the reports to the metrics loop to
//! send one per submission. Read-only: it lists directories and stats files.

use crate::config::{
    DEFAULT_DU_DEPTH, DEFAULT_DU_MIN_MB, DEFAULT_DU_TOP_FILES, DISK_SCAN_IGNORED_FILESYSTEMS,
    DISK_SCAN_IGNORED_MOUNTS, DISK_SCAN_THREADS,
};
use crate::disk_usage::{self, DuArgs};
use serde_json::Value;
use std::collections::{HashSet, VecDeque};
use std::path::PathBuf;
use std::sync::{Arc, Mutex};
use std::time::Duration;
use tokio_util::sync::CancellationToken;
use tracing::{info, warn};

#[cfg_attr(not(target_os = "linux"), allow(dead_code))]
const MOUNTINFO: &str = "/proc/self/mountinfo";

/// One line of `/proc/self/mountinfo`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Mount {
    /// `major:minor` of the filesystem; bind mounts of it share this.
    pub device: String,
    /// The folder of the filesystem mounted here (`/` for the whole thing).
    pub root: String,
    pub mount_point: String,
    pub fs_type: String,
}

pub fn parse_mountinfo(text: &str) -> Vec<Mount> {
    text.lines().filter_map(parse_mountinfo_line).collect()
}

/// `36 35 98:0 /mnt1 /mnt2 rw,noatime master:1 - ext3 /dev/root rw`: the
/// optional fields before ` - ` vary in number, the four before them do not.
fn parse_mountinfo_line(line: &str) -> Option<Mount> {
    let (before, after) = line.split_once(" - ")?;
    let fields: Vec<&str> = before.split(' ').collect();

    Some(Mount {
        device: fields.get(2)?.to_string(),
        root: unescape(fields.get(3)?),
        mount_point: unescape(fields.get(4)?),
        fs_type: after.split(' ').next()?.to_string(),
    })
}

/// mountinfo writes space, tab, newline and backslash as `\ooo` octal.
fn unescape(field: &str) -> String {
    let bytes = field.as_bytes();
    let mut out = Vec::with_capacity(bytes.len());
    let mut index = 0;

    while index < bytes.len() {
        let octal = (bytes[index] == b'\\')
            .then(|| bytes.get(index + 1..index + 4))
            .flatten()
            .and_then(|digits| std::str::from_utf8(digits).ok())
            .and_then(|digits| u8::from_str_radix(digits, 8).ok());

        match octal {
            Some(byte) => {
                out.push(byte);
                index += 4;
            }
            None => {
                out.push(bytes[index]);
                index += 1;
            }
        }
    }

    String::from_utf8_lossy(&out).into_owned()
}

/// The mount points worth scanning, once per filesystem. A filesystem
/// mounted in several places (bind mounts) is scanned where it is mounted
/// whole, else at its shortest mount point.
pub fn scan_targets(mounts: &[Mount], ignored_filesystems: &[&str], ignored_mounts: &[&str]) -> Vec<PathBuf> {
    let mut candidates: Vec<&Mount> = mounts
        .iter()
        .filter(|mount| !ignored_filesystems.contains(&mount.fs_type.as_str()))
        .filter(|mount| !ignored_mounts.iter().any(|ignored| is_at_or_below(&mount.mount_point, ignored)))
        .collect();
    candidates.sort_by_key(|mount| (mount.root != "/", mount.mount_point.len(), mount.mount_point.clone()));

    let mut seen = HashSet::new();
    let mut targets: Vec<PathBuf> = candidates
        .into_iter()
        .filter(|mount| seen.insert(mount.device.as_str()))
        .map(|mount| PathBuf::from(&mount.mount_point))
        .collect();
    targets.sort();
    targets
}

fn is_at_or_below(path: &str, folder: &str) -> bool {
    path == folder || path.strip_prefix(folder).is_some_and(|rest| rest.starts_with('/'))
}

/// Finished reports waiting to be sent, at most one per scanned folder.
#[derive(Debug, Default)]
pub struct ScanQueue {
    pending: Mutex<VecDeque<(String, Value)>>,
}

impl ScanQueue {
    /// Queue a report, replacing any unsent one of the same folder.
    pub fn push(&self, root: String, report: Value) {
        let mut pending = self.lock();
        pending.retain(|(queued, _)| queued != &root);
        pending.push_back((root, report));
    }

    pub fn take(&self) -> Option<(String, Value)> {
        self.lock().pop_front()
    }

    /// Return a report that failed to send, unless a newer one replaced it.
    pub fn put_back(&self, root: String, report: Value) {
        let mut pending = self.lock();
        if !pending.iter().any(|(queued, _)| queued == &root) {
            pending.push_front((root, report));
        }
    }

    fn lock(&self) -> std::sync::MutexGuard<'_, VecDeque<(String, Value)>> {
        self.pending.lock().unwrap_or_else(|poisoned| poisoned.into_inner())
    }
}

#[cfg_attr(not(target_os = "linux"), allow(dead_code))]
/// Scan every filesystem, then wait an interval, until the session ends.
pub async fn run_loop(queue: Arc<ScanQueue>, interval: Duration, token: CancellationToken) {
    loop {
        for root in current_targets().await {
            let report = tokio::select! {
                _ = token.cancelled() => return,
                report = scan(root.clone()) => report,
            };
            match report {
                Some(report) => queue.push(root.to_string_lossy().into_owned(), report),
                None => warn!("Disk scan of {} produced no report", root.display()),
            }
        }

        tokio::select! {
            _ = token.cancelled() => return,
            _ = tokio::time::sleep(interval) => {}
        }
    }
}

#[cfg_attr(not(target_os = "linux"), allow(dead_code))]
async fn current_targets() -> Vec<PathBuf> {
    match tokio::fs::read_to_string(MOUNTINFO).await {
        Ok(text) => scan_targets(&parse_mountinfo(&text), DISK_SCAN_IGNORED_FILESYSTEMS, DISK_SCAN_IGNORED_MOUNTS),
        Err(error) => {
            warn!("Cannot read {MOUNTINFO}, no disk scans this round: {error}");
            Vec::new()
        }
    }
}

/// Runs on a thread of its own: the background priority sticks to the
/// thread that sets it, and a pooled tokio thread would keep it afterwards.
async fn scan(root: PathBuf) -> Option<Value> {
    let (sender, receiver) = tokio::sync::oneshot::channel();
    let args = DuArgs {
        path: root,
        depth: DEFAULT_DU_DEPTH,
        min_mb: DEFAULT_DU_MIN_MB,
        top: DEFAULT_DU_TOP_FILES,
        keep: Vec::new(),
        threads: DISK_SCAN_THREADS,
        no_background: false,
        json: true,
    };

    std::thread::Builder::new()
        .name("disk-scan".into())
        .spawn(move || {
            let _ = sender.send(disk_usage::report(&args));
        })
        .ok()?;

    let (json, error_count) = receiver.await.ok()?;
    let report: Value = serde_json::from_str(&json).ok()?;
    info!(
        "Disk scan of {} done in {} ms ({error_count} unreadable paths)",
        report["root"].as_str().unwrap_or("?"),
        report["duration_ms"].as_u64().unwrap_or(0),
    );
    Some(report)
}

#[cfg(test)]
mod tests {
    use super::*;

    /// Trimmed from the mariadb LXC container, 2 Nov 2026.
    const LXC_MOUNTINFO: &str = "\
3656 3612 7:14 / / rw,relatime shared:2888 - ext4 /dev/loop14 rw
3667 3656 0:388 / /dev rw,relatime shared:2889 - tmpfs none rw,size=492k,mode=755,uid=100000,gid=100000,inode64
3668 3656 0:389 / /proc rw,nosuid,nodev,noexec,relatime shared:2903 - proc proc rw
3670 3668 0:389 /sys /proc/sys ro,relatime shared:2904 - proc proc rw
3673 3656 0:390 / /sys ro,nosuid,nodev,noexec,relatime shared:2915 - sysfs sysfs rw
3672 3673 0:37 / /sys/fs/fuse/connections rw,nosuid,nodev,noexec,relatime shared:2917 master:19 - fusectl fusectl rw
3677 3673 0:31 / /sys/fs/cgroup rw,nosuid,nodev,noexec,relatime shared:2918 - cgroup2 none rw
3678 3668 0:45 /proc/cpuinfo /proc/cpuinfo rw,nosuid,nodev,relatime shared:2907 master:54 - fuse.lxcfs lxcfs rw,user_id=0,group_id=0,allow_other
3694 3667 0:5 /full /dev/full rw,nosuid,relatime shared:2892 master:2 - devtmpfs udev rw,size=65811696k,mode=755,inode64
3817 3667 0:392 / /dev/pts rw,nosuid,noexec,relatime shared:2898 - devpts devpts rw,gid=100005,mode=620,ptmxmode=666,max=1026
3614 3667 0:393 / /dev/shm rw,nosuid,nodev shared:2920 - tmpfs tmpfs rw,uid=100000,gid=100000,inode64
3615 3656 0:394 / /run rw,nosuid,nodev shared:2921 - tmpfs tmpfs rw,size=26378888k,mode=755,uid=100000,gid=100000,inode64
3616 3667 0:387 / /dev/mqueue rw,nosuid,nodev,noexec,relatime shared:2922 - mqueue mqueue rw
3617 3656 0:395 / /tmp rw,nosuid,nodev shared:2923 - tmpfs tmpfs rw,size=65947220k,uid=100000,gid=100000,inode64
4174 3615 0:405 / /run/credentials/console-getty.service ro,nosuid,nodev,noexec,relatime,nosymfollow shared:2939 - ramfs ramfs rw,mode=700
603 3615 0:540 / /run/user/0 rw,nosuid,nodev,relatime shared:4379 - tmpfs tmpfs rw,size=13189440k,mode=700,uid=100000,gid=100000,inode64
";

    /// A ZFS host with a dataset for MySQL, a snap, Docker's overlay, an
    /// NFS share, EFI under /boot and a bind mount of part of the root.
    const HOST_MOUNTINFO: &str = "\
22 1 0:21 / / rw,relatime shared:1 - zfs rpool/ROOT/pve-1 rw,xattr,noacl
23 22 0:5 / /dev rw,nosuid,relatime shared:2 - devtmpfs udev rw
24 22 0:22 / /proc rw,nosuid,nodev,noexec,relatime shared:12 - proc proc rw
25 22 0:23 / /sys rw,nosuid,nodev,noexec,relatime shared:7 - sysfs sysfs rw
26 22 0:24 / /run rw,nosuid,nodev,noexec,relatime shared:5 - tmpfs tmpfs rw
30 22 8:2 / /boot/efi rw,relatime shared:30 - vfat /dev/sda2 rw
40 22 0:41 / /rpool rw,noatime shared:40 - zfs rpool rw,xattr,noacl
41 40 0:42 / /rpool/data rw,noatime shared:41 - zfs rpool/data rw,xattr,noacl
42 22 0:43 / /var/lib/mysql rw,noatime shared:42 - zfs rpool/mysql rw,xattr,noacl
43 22 0:21 /srv/shared /home/ben/shared rw,relatime shared:1 - zfs rpool/ROOT/pve-1 rw,xattr,noacl
44 22 7:3 / /snap/core22/1380 ro,nodev,relatime shared:44 - squashfs /dev/loop3 ro
45 22 0:50 / /var/lib/docker/overlay2/abc/merged rw,relatime shared:45 - overlay overlay rw
46 22 0:51 / /mnt/nas rw,relatime shared:46 - nfs4 nas:/export rw
47 22 0:52 / /media/usb\\040stick rw,relatime shared:47 - ext4 /dev/sdc1 rw
";

    fn targets(text: &str) -> Vec<String> {
        scan_targets(&parse_mountinfo(text), DISK_SCAN_IGNORED_FILESYSTEMS, DISK_SCAN_IGNORED_MOUNTS)
            .into_iter()
            .map(|path| path.to_string_lossy().into_owned())
            .collect()
    }

    #[test]
    fn parses_a_mountinfo_line() {
        let mounts = parse_mountinfo("36 35 98:0 /mnt1 /mnt2 rw,noatime master:1 shared:2 - ext3 /dev/root rw,errors=continue\n");

        assert_eq!(
            mounts,
            vec![Mount {
                device: "98:0".into(),
                root: "/mnt1".into(),
                mount_point: "/mnt2".into(),
                fs_type: "ext3".into(),
            }]
        );
    }

    #[test]
    fn unescapes_spaces_and_backslashes_in_paths() {
        let mounts = parse_mountinfo("40 1 8:1 / /mnt/my\\040disk\\134x rw - ext4 /dev/sdb1 rw\n");

        assert_eq!(mounts[0].mount_point, "/mnt/my disk\\x");
    }

    #[test]
    fn skips_lines_it_cannot_read() {
        assert!(parse_mountinfo("garbage\n\n12 1 0:5\n").is_empty());
    }

    #[test]
    fn an_lxc_container_scans_its_loop_root_and_nothing_pseudo() {
        assert_eq!(targets(LXC_MOUNTINFO), vec!["/"]);
    }

    #[test]
    fn a_zfs_host_scans_each_dataset_once_and_skips_bind_duplicates() {
        assert_eq!(
            targets(HOST_MOUNTINFO),
            vec!["/", "/media/usb stick", "/rpool", "/rpool/data", "/var/lib/mysql"]
        );
    }

    #[test]
    fn a_bind_mount_loses_to_the_whole_filesystem_wherever_it_sits() {
        let text = "\
50 1 8:2 /data/www /srv/www rw - ext4 /dev/sda2 rw
51 1 8:2 / /mnt/disk2 rw - ext4 /dev/sda2 rw
";
        assert_eq!(targets(text), vec!["/mnt/disk2"]);
    }

    #[test]
    fn ignored_mounts_match_whole_folder_names() {
        assert!(is_at_or_below("/boot/efi", "/boot"));
        assert!(is_at_or_below("/boot", "/boot"));
        assert!(!is_at_or_below("/bootstrap", "/boot"));
    }

    #[test]
    fn the_queue_keeps_one_report_per_folder_and_retries_failures() {
        let queue = ScanQueue::default();
        queue.push("/".into(), serde_json::json!({"n": 1}));
        queue.push("/var".into(), serde_json::json!({"n": 2}));
        queue.push("/".into(), serde_json::json!({"n": 3}));

        let (root, report) = queue.take().unwrap();
        assert_eq!((root.as_str(), report["n"].as_i64()), ("/var", Some(2)));

        queue.put_back(root, report);
        assert_eq!(queue.take().unwrap().0, "/var");
        assert_eq!(queue.take().unwrap().1["n"], 3);
        assert!(queue.take().is_none());
    }

    #[test]
    fn a_failed_report_does_not_replace_a_newer_one() {
        let queue = ScanQueue::default();
        queue.push("/".into(), serde_json::json!({"n": 2}));

        queue.put_back("/".into(), serde_json::json!({"n": 1}));

        assert_eq!(queue.take().unwrap().1["n"], 2);
        assert!(queue.take().is_none());
    }

    #[tokio::test]
    async fn scans_a_real_folder_into_a_du_report() {
        let dir = tempfile::tempdir().unwrap();
        std::fs::write(dir.path().join("file.bin"), vec![1u8; 4096]).unwrap();

        let report = scan(dir.path().to_path_buf()).await.unwrap();

        assert_eq!(report["schema"], "rmm.du/1");
        assert_eq!(report["root"], dir.path().to_string_lossy().as_ref());
        assert_eq!(report["totals"]["files"], 1);
    }
}
