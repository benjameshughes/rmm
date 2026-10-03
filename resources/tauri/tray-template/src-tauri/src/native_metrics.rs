//! Native metrics for monitor-only (non-Windows) agents.
//!
//! Linux servers and containers run no Netdata: the agent reads CPU, memory,
//! load, uptime, filesystems and network counters itself with `sysinfo` and
//! posts them in the server's "standard" metrics format, flagged
//! `monitor_only` so the server knows this device never takes commands.
//!
//! Rates (disk and network throughput, disk busy %) and per-app CPU are
//! measured between two submissions, so the first report after start has no
//! rates. Everything here only reads /proc, /sys and statvfs; the health
//! checks that run query commands live in `linux_health.rs`.

// Only non-Windows builds (and tests) collect native metrics.
#![cfg_attr(windows, allow(dead_code))]

use crate::linux_health::LinuxHealth;
use crate::linux_stats::{self, AppUsage, DiskCounters};
use chrono::Utc;
use serde::Serialize;
use std::collections::{HashMap, HashSet};
use std::path::Path;
use std::time::Instant;
use sysinfo::{
    Disks, Networks, ProcessRefreshKind, System, ThreadKind, MINIMUM_CPU_UPDATE_INTERVAL,
};

const BYTES_PER_MIB: f64 = 1024.0 * 1024.0;
const BYTES_PER_GB: f64 = 1024.0 * 1024.0 * 1024.0;

/// Filesystem types that are never real storage: kernel interfaces, memory
/// backed, container layers and read-only images.
const PSEUDO_FILESYSTEMS: &[&str] = &[
    "autofs",
    "binfmt_misc",
    "bpf",
    "cgroup",
    "cgroup2",
    "configfs",
    "debugfs",
    "devpts",
    "devtmpfs",
    "efivarfs",
    "fusectl",
    "fuse.lxcfs",
    "fuse.gvfsd-fuse",
    "fuse.portal",
    "hugetlbfs",
    "iso9660",
    "mqueue",
    "nsfs",
    "overlay",
    "proc",
    "pstore",
    "ramfs",
    "rpc_pipefs",
    "securityfs",
    "selinuxfs",
    "squashfs",
    "sysfs",
    "tmpfs",
    "tracefs",
];

/// Mount point prefixes that only ever hold kernel or runtime filesystems.
const PSEUDO_MOUNT_PREFIXES: &[&str] = &["/proc", "/sys", "/dev", "/run", "/snap"];

/// Whether a mount is real storage worth reporting.
pub fn is_real_filesystem(filesystem: &str, mount_point: &str, total_bytes: u64) -> bool {
    if total_bytes == 0 {
        return false;
    }

    let filesystem = filesystem.to_ascii_lowercase();
    if PSEUDO_FILESYSTEMS.contains(&filesystem.as_str()) {
        return false;
    }

    !PSEUDO_MOUNT_PREFIXES
        .iter()
        .any(|prefix| mount_point == *prefix || mount_point.starts_with(&format!("{prefix}/")))
}

/// Standard-format metrics payload (see the server's MetricsRequest).
/// Deliberately has no `netdata_*` keys: those switch the server to the
/// Netdata parser.
#[derive(Debug, Clone, Serialize)]
pub struct StandardMetricsPayload {
    pub hostname: String,
    pub timestamp: String,
    pub agent_version: String,
    /// This agent never runs commands.
    pub monitor_only: bool,
    #[serde(skip_serializing_if = "Vec::is_empty")]
    pub mac_addresses: Vec<String>,
    pub cpu: CpuMetrics,
    pub memory: MemoryMetrics,
    pub load: LoadMetrics,
    pub uptime: UptimeMetrics,
    pub disks: Vec<DiskMetrics>,
    pub network: Vec<NetworkMetrics>,
    pub system_info: SystemInfoMetrics,
    /// Omitted when the machine has no swap.
    #[serde(skip_serializing_if = "Option::is_none")]
    pub swap: Option<SwapMetrics>,
    pub processes: ProcessMetrics,
    #[serde(skip_serializing_if = "Vec::is_empty")]
    pub apps: Vec<AppUsage>,
    /// Linux only.
    #[serde(skip_serializing_if = "Option::is_none")]
    pub linux_health: Option<LinuxHealth>,
}

#[derive(Debug, Clone, Serialize)]
pub struct SwapMetrics {
    pub used_mib: f64,
    pub total_mib: f64,
}

#[derive(Debug, Clone, Serialize)]
pub struct ProcessMetrics {
    #[serde(skip_serializing_if = "Option::is_none")]
    pub running: Option<u64>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub blocked: Option<u64>,
    pub total: u64,
}

#[derive(Debug, Clone, Serialize)]
pub struct CpuMetrics {
    pub usage_percent: f64,
}

#[derive(Debug, Clone, Serialize)]
pub struct MemoryMetrics {
    pub usage_percent: f64,
    pub used_mib: f64,
    pub free_mib: f64,
    pub available_mib: f64,
    pub total_mib: f64,
}

#[derive(Debug, Clone, Serialize)]
pub struct LoadMetrics {
    pub load1: f64,
    pub load5: f64,
    pub load15: f64,
}

#[derive(Debug, Clone, Serialize)]
pub struct UptimeMetrics {
    pub seconds: u64,
}

#[derive(Debug, Clone, Serialize)]
pub struct DiskMetrics {
    pub mount_point: String,
    pub filesystem: String,
    pub used_gb: f64,
    pub available_gb: f64,
    pub total_gb: f64,
    pub usage_percent: f64,
    /// KiB/s read since the previous report (omitted when the mount's block
    /// device is not visible, e.g. in an LXC, or on the first report).
    #[serde(skip_serializing_if = "Option::is_none")]
    pub read_kbps: Option<f64>,
    /// KiB/s written since the previous report.
    #[serde(skip_serializing_if = "Option::is_none")]
    pub write_kbps: Option<f64>,
    /// Share of the time the block device was busy.
    #[serde(skip_serializing_if = "Option::is_none")]
    pub utilization_percent: Option<f64>,
    /// Omitted when the filesystem has no inode table (btrfs).
    #[serde(skip_serializing_if = "Option::is_none")]
    pub inode_usage_percent: Option<f64>,
}

#[derive(Debug, Clone, Serialize)]
pub struct NetworkMetrics {
    pub interface: String,
    pub received_bytes: u64,
    pub sent_bytes: u64,
    /// Kilobits/s since the previous report (omitted on the first).
    #[serde(skip_serializing_if = "Option::is_none")]
    pub received_kbps: Option<f64>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub sent_kbps: Option<f64>,
    /// Cumulative counters from /sys/class/net/<if>/statistics.
    #[serde(skip_serializing_if = "Option::is_none")]
    pub received_errors: Option<u64>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub sent_errors: Option<u64>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub received_drops: Option<u64>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub sent_drops: Option<u64>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub link_speed_mbps: Option<u64>,
}

#[derive(Debug, Clone, Serialize)]
pub struct SystemInfoMetrics {
    pub os_name: Option<String>,
    pub os_version: Option<String>,
    pub kernel_name: String,
    pub kernel_version: Option<String>,
    pub architecture: String,
}

/// Percentage rounded to two decimals and kept within 0..=100.
fn percent(part: f64, whole: f64) -> f64 {
    if whole <= 0.0 {
        return 0.0;
    }
    round2((part / whole * 100.0).clamp(0.0, 100.0))
}

fn round2(value: f64) -> f64 {
    (value * 100.0).round() / 100.0
}

/// One filesystem as the agent reports it (`None` for pseudo filesystems).
pub fn disk_metrics(
    mount_point: &str,
    filesystem: &str,
    total_bytes: u64,
    available_bytes: u64,
) -> Option<DiskMetrics> {
    if !is_real_filesystem(filesystem, mount_point, total_bytes) {
        return None;
    }

    let available_bytes = available_bytes.min(total_bytes);
    let used_bytes = total_bytes - available_bytes;

    Some(DiskMetrics {
        mount_point: mount_point.to_string(),
        filesystem: filesystem.to_string(),
        used_gb: round2(used_bytes as f64 / BYTES_PER_GB),
        available_gb: round2(available_bytes as f64 / BYTES_PER_GB),
        total_gb: round2(total_bytes as f64 / BYTES_PER_GB),
        usage_percent: percent(used_bytes as f64, total_bytes as f64),
        read_kbps: None,
        write_kbps: None,
        utilization_percent: None,
        inode_usage_percent: None,
    })
}

/// Swap figures; None when there is no swap.
pub fn swap_metrics(total: u64, used: u64) -> Option<SwapMetrics> {
    (total > 0).then(|| SwapMetrics {
        used_mib: round2(used.min(total) as f64 / BYTES_PER_MIB),
        total_mib: round2(total as f64 / BYTES_PER_MIB),
    })
}

fn read_text(path: impl AsRef<Path>) -> Option<String> {
    std::fs::read_to_string(path).ok()
}

/// (total inodes, free inodes) for the filesystem at `path`.
#[cfg(unix)]
fn statvfs_inodes(path: &Path) -> Option<(u64, u64)> {
    use std::os::unix::ffi::OsStrExt;

    let path = std::ffi::CString::new(path.as_os_str().as_bytes()).ok()?;
    let mut stat: libc::statvfs = unsafe { std::mem::zeroed() };
    if unsafe { libc::statvfs(path.as_ptr(), &mut stat) } != 0 {
        return None;
    }
    #[allow(clippy::unnecessary_cast)]
    Some((stat.f_files as u64, stat.f_ffree as u64))
}

#[cfg(not(unix))]
fn statvfs_inodes(_path: &Path) -> Option<(u64, u64)> {
    None
}

/// Network counters for one interface from /sys/class/net/<if>.
fn interface_counters(interface: &str) -> [Option<u64>; 5] {
    let base = Path::new("/sys/class/net").join(interface);
    let statistic = |name: &str| {
        read_text(base.join("statistics").join(name))
            .and_then(|text| linux_stats::parse_counter(&text))
    };

    [
        statistic("rx_errors"),
        statistic("tx_errors"),
        statistic("rx_dropped"),
        statistic("tx_dropped"),
        read_text(base.join("speed")).and_then(|text| linux_stats::parse_link_speed(&text)),
    ]
}

/// Memory figures from byte counts.
pub fn memory_metrics(total: u64, used: u64, free: u64, available: u64) -> MemoryMetrics {
    MemoryMetrics {
        usage_percent: percent(used as f64, total as f64),
        used_mib: round2(used as f64 / BYTES_PER_MIB),
        free_mib: round2(free as f64 / BYTES_PER_MIB),
        available_mib: round2(available as f64 / BYTES_PER_MIB),
        total_mib: round2(total as f64 / BYTES_PER_MIB),
    }
}

/// Keeps one `System` alive so CPU usage is measured across the whole
/// interval between submissions rather than a fresh 200ms sample.
pub struct NativeCollector {
    system: System,
    last_cpu_refresh: Instant,
    previous_disks: Option<(Instant, HashMap<String, DiskCounters>)>,
    previous_network: Option<(Instant, HashMap<String, (u64, u64)>)>,
}

impl Default for NativeCollector {
    fn default() -> Self {
        Self::new()
    }
}

impl NativeCollector {
    pub fn new() -> Self {
        // Don't keep a /proc/<pid>/stat file open per process between
        // refreshes (sysinfo's default on Linux); the agent re-reads them.
        sysinfo::set_open_files_limit(0);

        let mut system = System::new();
        system.refresh_cpu_usage();
        system.refresh_processes_specifics(Self::process_refresh());

        Self {
            system,
            last_cpu_refresh: Instant::now(),
            previous_disks: None,
            previous_network: None,
        }
    }

    fn process_refresh() -> ProcessRefreshKind {
        ProcessRefreshKind::new().with_cpu().with_memory()
    }

    /// How long to wait before CPU usage can be measured (sysinfo needs two
    /// refreshes at least `MINIMUM_CPU_UPDATE_INTERVAL` apart).
    pub fn cpu_settle_time(&self) -> std::time::Duration {
        MINIMUM_CPU_UPDATE_INTERVAL.saturating_sub(self.last_cpu_refresh.elapsed())
    }

    /// Read everything. Blocking (statvfs, /proc): call off the async runtime.
    /// `apps_limit` is how many apps to keep by CPU and by memory.
    pub fn collect(
        &mut self,
        hostname: &str,
        mac_addresses: &[String],
        apps_limit: usize,
    ) -> StandardMetricsPayload {
        self.system.refresh_cpu_usage();
        self.last_cpu_refresh = Instant::now();
        self.system.refresh_memory();
        self.system
            .refresh_processes_specifics(Self::process_refresh());

        let load = System::load_average();
        let memory = memory_metrics(
            self.system.total_memory(),
            self.system.used_memory(),
            self.system.free_memory(),
            self.system.available_memory(),
        );

        let disks = self.collect_disks();
        let network = self.collect_network();

        let threads_excluded = self
            .system
            .processes()
            .values()
            .filter(|process| process.thread_kind() != Some(ThreadKind::Userland));
        let process_count = threads_excluded.clone().count() as u64;
        let apps = linux_stats::top_apps(
            linux_stats::group_apps(
                threads_excluded
                    .map(|process| (process.name(), process.cpu_usage(), process.memory())),
                self.system.cpus().len(),
            ),
            apps_limit,
        );
        let proc_stat = read_text("/proc/stat")
            .map(|text| linux_stats::parse_proc_stat(&text))
            .unwrap_or_default();

        StandardMetricsPayload {
            hostname: hostname.to_string(),
            timestamp: Utc::now().to_rfc3339(),
            agent_version: env!("CARGO_PKG_VERSION").to_string(),
            monitor_only: true,
            mac_addresses: mac_addresses.to_vec(),
            cpu: CpuMetrics {
                usage_percent: round2(
                    (self.system.global_cpu_info().cpu_usage() as f64).clamp(0.0, 100.0),
                ),
            },
            memory,
            load: LoadMetrics {
                load1: round2(load.one.max(0.0)),
                load5: round2(load.five.max(0.0)),
                load15: round2(load.fifteen.max(0.0)),
            },
            uptime: UptimeMetrics {
                seconds: System::uptime(),
            },
            disks,
            network,
            system_info: SystemInfoMetrics {
                os_name: System::name(),
                os_version: System::os_version(),
                kernel_name: kernel_name(std::env::consts::OS),
                kernel_version: System::kernel_version(),
                architecture: std::env::consts::ARCH.to_string(),
            },
            swap: swap_metrics(self.system.total_swap(), self.system.used_swap()),
            processes: ProcessMetrics {
                running: proc_stat.procs_running,
                blocked: proc_stat.procs_blocked,
                total: process_count,
            },
            apps,
            linux_health: None,
        }
    }

    /// Real filesystems with usage, inode usage and (when the mount's block
    /// device appears in /proc/diskstats) I/O rates since the last report.
    fn collect_disks(&mut self) -> Vec<DiskMetrics> {
        let now = Instant::now();
        let counters = read_text("/proc/diskstats")
            .map(|text| linux_stats::parse_diskstats(&text))
            .unwrap_or_default();
        let previous = self.previous_disks.take();

        let mut seen_mounts = HashSet::new();
        let disks = Disks::new_with_refreshed_list()
            .iter()
            .filter_map(|disk| {
                let mut metrics = disk_metrics(
                    &disk.mount_point().to_string_lossy(),
                    &disk.file_system().to_string_lossy(),
                    disk.total_space(),
                    disk.available_space(),
                )?;
                if !seen_mounts.insert(metrics.mount_point.clone()) {
                    return None;
                }

                metrics.inode_usage_percent = statvfs_inodes(disk.mount_point())
                    .and_then(|(total, free)| linux_stats::inode_usage_percent(total, free));

                let source = disk.name().to_string_lossy().to_string();
                let resolved = std::fs::canonicalize(&source).ok();
                let device = linux_stats::block_device_name(&source, resolved.as_deref());
                if let (Some(device), Some((then, before))) = (device, previous.as_ref()) {
                    if let (Some(before), Some(after)) =
                        (before.get(&device), counters.get(&device))
                    {
                        let rates =
                            linux_stats::disk_rates(before, after, now.duration_since(*then));
                        metrics.read_kbps = rates.read_kbps;
                        metrics.write_kbps = rates.write_kbps;
                        metrics.utilization_percent = rates.utilization_percent;
                    }
                }

                Some(metrics)
            })
            .collect();

        self.previous_disks = Some((now, counters));
        disks
    }

    /// Interfaces other than `lo`, with throughput since the last report and
    /// the kernel's error/drop counters and link speed.
    fn collect_network(&mut self) -> Vec<NetworkMetrics> {
        let now = Instant::now();
        let previous = self.previous_network.take();
        let mut totals = HashMap::new();

        let mut network: Vec<NetworkMetrics> = Networks::new_with_refreshed_list()
            .iter()
            .filter(|(name, _)| name.as_str() != "lo")
            .map(|(name, data)| {
                let received = data.total_received();
                let sent = data.total_transmitted();
                totals.insert(name.clone(), (received, sent));

                let rates = previous.as_ref().and_then(|(then, before)| {
                    let (received_before, sent_before) = before.get(name)?;
                    let elapsed = now.duration_since(*then);
                    Some((
                        linux_stats::kilobits_per_second(*received_before, received, elapsed),
                        linux_stats::kilobits_per_second(*sent_before, sent, elapsed),
                    ))
                });
                let [received_errors, sent_errors, received_drops, sent_drops, link_speed_mbps] =
                    interface_counters(name);

                NetworkMetrics {
                    interface: name.clone(),
                    received_bytes: received,
                    sent_bytes: sent,
                    received_kbps: rates.and_then(|(received, _)| received),
                    sent_kbps: rates.and_then(|(_, sent)| sent),
                    received_errors,
                    sent_errors,
                    received_drops,
                    sent_drops,
                    link_speed_mbps,
                }
            })
            .collect();
        network.sort_by(|a, b| a.interface.cmp(&b.interface));

        self.previous_network = Some((now, totals));
        network
    }
}

/// Kernel name as `uname -s` would print it.
fn kernel_name(os: &str) -> String {
    match os {
        "linux" => "Linux".to_string(),
        "macos" => "Darwin".to_string(),
        "freebsd" => "FreeBSD".to_string(),
        other => other.to_string(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    const GB: u64 = 1024 * 1024 * 1024;

    #[test]
    fn keeps_real_filesystems() {
        for (filesystem, mount) in [
            ("ext4", "/"),
            ("xfs", "/var/lib/docker"),
            ("btrfs", "/home"),
            ("zfs", "/"),
            ("vfat", "/boot/efi"),
            ("ext4", "/mnt/backup"),
            ("ext4", "/devices"),
        ] {
            assert!(
                is_real_filesystem(filesystem, mount, 10 * GB),
                "{filesystem} {mount}"
            );
        }
    }

    #[test]
    fn skips_pseudo_and_virtual_filesystems() {
        for (filesystem, mount) in [
            ("tmpfs", "/tmp"),
            ("devtmpfs", "/dev"),
            ("overlay", "/var/lib/docker/overlay2/abc/merged"),
            ("squashfs", "/snap/core/123"),
            ("proc", "/proc"),
            ("sysfs", "/sys"),
            ("cgroup2", "/sys/fs/cgroup"),
            ("fuse.lxcfs", "/proc/cpuinfo"),
            ("TMPFS", "/run/user/0"),
            ("ext4", "/run/something"),
            ("ext4", "/dev/shm"),
        ] {
            assert!(
                !is_real_filesystem(filesystem, mount, 10 * GB),
                "{filesystem} {mount}"
            );
        }
    }

    #[test]
    fn skips_empty_filesystems() {
        assert!(!is_real_filesystem("ext4", "/mnt/empty", 0));
    }

    #[test]
    fn computes_disk_usage() {
        let disk = disk_metrics("/", "ext4", 100 * GB, 25 * GB).unwrap();

        assert_eq!(disk.total_gb, 100.0);
        assert_eq!(disk.available_gb, 25.0);
        assert_eq!(disk.used_gb, 75.0);
        assert_eq!(disk.usage_percent, 75.0);
        assert!(disk_metrics("/tmp", "tmpfs", 100 * GB, 25 * GB).is_none());
    }

    #[test]
    fn a_full_disk_reads_one_hundred_percent() {
        let disk = disk_metrics("/", "ext4", 100 * GB, 0).unwrap();
        assert_eq!(disk.usage_percent, 100.0);

        // Available larger than total (odd quotas) never goes negative.
        let disk = disk_metrics("/", "ext4", 10 * GB, 20 * GB).unwrap();
        assert_eq!(disk.used_gb, 0.0);
        assert_eq!(disk.usage_percent, 0.0);
    }

    #[test]
    fn computes_memory_usage() {
        let mib = 1024 * 1024;
        let memory = memory_metrics(8192 * mib, 2048 * mib, 1024 * mib, 6144 * mib);

        assert_eq!(memory.total_mib, 8192.0);
        assert_eq!(memory.used_mib, 2048.0);
        assert_eq!(memory.free_mib, 1024.0);
        assert_eq!(memory.available_mib, 6144.0);
        assert_eq!(memory.usage_percent, 25.0);
        assert_eq!(memory_metrics(0, 0, 0, 0).usage_percent, 0.0);
    }

    fn sample_payload() -> StandardMetricsPayload {
        StandardMetricsPayload {
            hostname: "pve-lxc-101".to_string(),
            timestamp: "2026-10-03T10:00:00+00:00".to_string(),
            agent_version: "0.7.0".to_string(),
            monitor_only: true,
            mac_addresses: vec!["bc:24:11:8d:62:14".to_string()],
            cpu: CpuMetrics { usage_percent: 3.5 },
            memory: memory_metrics(
                2048 * 1024 * 1024,
                512 * 1024 * 1024,
                1024 * 1024 * 1024,
                1536 * 1024 * 1024,
            ),
            load: LoadMetrics {
                load1: 0.1,
                load5: 0.2,
                load15: 0.3,
            },
            uptime: UptimeMetrics { seconds: 86_400 },
            disks: vec![disk_metrics("/", "ext4", 100 * GB, 25 * GB).unwrap()],
            network: vec![NetworkMetrics {
                interface: "eth0".to_string(),
                received_bytes: 123,
                sent_bytes: 456,
                received_kbps: None,
                sent_kbps: None,
                received_errors: None,
                sent_errors: None,
                received_drops: None,
                sent_drops: None,
                link_speed_mbps: None,
            }],
            system_info: SystemInfoMetrics {
                os_name: Some("Debian GNU/Linux".to_string()),
                os_version: Some("12".to_string()),
                kernel_name: "Linux".to_string(),
                kernel_version: Some("6.8.12-4-pve".to_string()),
                architecture: "x86_64".to_string(),
            },
            swap: None,
            processes: ProcessMetrics {
                running: None,
                blocked: None,
                total: 42,
            },
            apps: vec![],
            linux_health: None,
        }
    }

    /// The same machine one interval later, with every optional field known.
    fn full_payload() -> StandardMetricsPayload {
        let mut payload = sample_payload();
        payload.agent_version = "0.7.1".to_string();
        let disk = &mut payload.disks[0];
        disk.read_kbps = Some(1024.0);
        disk.write_kbps = Some(512.0);
        disk.utilization_percent = Some(25.0);
        disk.inode_usage_percent = Some(12.5);
        let interface = &mut payload.network[0];
        interface.received_kbps = Some(1000.0);
        interface.sent_kbps = Some(250.0);
        interface.received_errors = Some(0);
        interface.sent_errors = Some(1);
        interface.received_drops = Some(2);
        interface.sent_drops = Some(0);
        interface.link_speed_mbps = Some(10000);
        payload.swap = swap_metrics(1024 * 1024 * 1024, 64 * 1024 * 1024);
        payload.processes = ProcessMetrics {
            running: Some(2),
            blocked: Some(0),
            total: 87,
        };
        payload.apps = vec![AppUsage {
            name: "php-fpm8.4".to_string(),
            cpu_percent: 12.5,
            memory_mib: 310.25,
        }];
        payload.linux_health = Some(LinuxHealth {
            failed_units: Some(vec!["smartd.service".to_string()]),
            reboot_required: true,
            pending_updates: Some(3),
            pending_security_updates: Some(1),
            checked_updates_at: Some("2026-10-03T09:00:00+00:00".to_string()),
        });
        payload
    }

    #[test]
    fn serialises_every_new_field() {
        let json = serde_json::to_value(full_payload()).unwrap();

        let disk = &json["disks"][0];
        assert_eq!(disk["read_kbps"], 1024.0);
        assert_eq!(disk["write_kbps"], 512.0);
        assert_eq!(disk["utilization_percent"], 25.0);
        assert_eq!(disk["inode_usage_percent"], 12.5);
        let interface = &json["network"][0];
        assert_eq!(interface["received_kbps"], 1000.0);
        assert_eq!(interface["sent_kbps"], 250.0);
        assert_eq!(interface["received_errors"], 0);
        assert_eq!(interface["sent_errors"], 1);
        assert_eq!(interface["received_drops"], 2);
        assert_eq!(interface["sent_drops"], 0);
        assert_eq!(interface["link_speed_mbps"], 10000);
        assert_eq!(
            json["swap"],
            serde_json::json!({"used_mib": 64.0, "total_mib": 1024.0})
        );
        assert_eq!(
            json["processes"],
            serde_json::json!({"running": 2, "blocked": 0, "total": 87})
        );
        assert_eq!(
            json["apps"],
            serde_json::json!([{"name": "php-fpm8.4", "cpu_percent": 12.5, "memory_mib": 310.25}])
        );
        assert_eq!(json["linux_health"]["failed_units"][0], "smartd.service");
        assert_eq!(json["linux_health"]["reboot_required"], true);
        assert_eq!(json["linux_health"]["pending_updates"], 3);
        assert_eq!(json["linux_health"]["pending_security_updates"], 1);
        assert_eq!(
            json["linux_health"]["checked_updates_at"],
            "2026-10-03T09:00:00+00:00"
        );
    }

    #[test]
    fn omits_unknown_optional_fields() {
        let json = serde_json::to_value(sample_payload()).unwrap();
        let object = json.as_object().unwrap();

        for key in ["swap", "apps", "linux_health"] {
            assert!(!object.contains_key(key), "{key}");
        }
        assert_eq!(json["processes"], serde_json::json!({"total": 42}));
        let disk = json["disks"][0].as_object().unwrap();
        for key in [
            "read_kbps",
            "write_kbps",
            "utilization_percent",
            "inode_usage_percent",
        ] {
            assert!(!disk.contains_key(key), "{key}");
        }
        let interface = json["network"][0].as_object().unwrap();
        for key in [
            "received_kbps",
            "sent_kbps",
            "received_errors",
            "sent_errors",
            "received_drops",
            "sent_drops",
            "link_speed_mbps",
        ] {
            assert!(!interface.contains_key(key), "{key}");
        }
    }

    #[test]
    fn no_swap_means_no_swap_field() {
        assert!(swap_metrics(0, 0).is_none());
        let swap = swap_metrics(2048 * 1024 * 1024, 512 * 1024 * 1024).unwrap();
        assert_eq!(swap.total_mib, 2048.0);
        assert_eq!(swap.used_mib, 512.0);
    }

    #[test]
    fn serialises_the_standard_format_the_server_expects() {
        let json = serde_json::to_value(sample_payload()).unwrap();

        assert_eq!(json["monitor_only"], true);
        assert_eq!(json["agent_version"], "0.7.0");
        assert_eq!(json["cpu"]["usage_percent"], 3.5);
        assert_eq!(json["memory"]["usage_percent"], 25.0);
        assert_eq!(json["memory"]["used_mib"], 512.0);
        assert_eq!(json["memory"]["total_mib"], 2048.0);
        assert_eq!(json["memory"]["available_mib"], 1536.0);
        assert_eq!(json["load"]["load1"], 0.1);
        assert_eq!(json["load"]["load15"], 0.3);
        assert_eq!(json["uptime"]["seconds"], 86_400);
        assert_eq!(json["disks"][0]["mount_point"], "/");
        assert_eq!(json["disks"][0]["filesystem"], "ext4");
        assert_eq!(json["disks"][0]["used_gb"], 75.0);
        assert_eq!(json["disks"][0]["available_gb"], 25.0);
        assert_eq!(json["disks"][0]["total_gb"], 100.0);
        assert_eq!(json["disks"][0]["usage_percent"], 75.0);
        assert_eq!(json["network"][0]["interface"], "eth0");
        assert_eq!(json["network"][0]["received_bytes"], 123);
        assert_eq!(json["network"][0]["sent_bytes"], 456);
        assert_eq!(json["system_info"]["os_name"], "Debian GNU/Linux");
        assert_eq!(json["system_info"]["kernel_name"], "Linux");
        assert_eq!(json["system_info"]["kernel_version"], "6.8.12-4-pve");
        assert_eq!(json["system_info"]["architecture"], "x86_64");
        assert_eq!(json["mac_addresses"][0], "bc:24:11:8d:62:14");
    }

    #[test]
    fn never_looks_like_a_netdata_payload() {
        let json = serde_json::to_value(sample_payload()).unwrap();

        assert!(json
            .as_object()
            .unwrap()
            .keys()
            .all(|key| !key.starts_with("netdata_")));
    }

    #[test]
    fn names_kernels_like_uname() {
        assert_eq!(kernel_name("linux"), "Linux");
        assert_eq!(kernel_name("macos"), "Darwin");
    }

    #[test]
    fn collects_from_this_machine() {
        let mut collector = NativeCollector::new();
        std::thread::sleep(collector.cpu_settle_time());

        let payload = collector.collect("test-host", &[], 10);

        assert!(payload.monitor_only);
        assert!(payload.processes.total > 0);
        assert!(!payload.apps.is_empty());
        // First report: no rates yet.
        assert!(payload
            .network
            .iter()
            .all(|net| net.received_kbps.is_none()));
        assert!(payload.disks.iter().all(|disk| disk.read_kbps.is_none()));

        // Second report: network rates now known.
        let second = collector.collect("test-host", &[], 10);
        assert!(second
            .network
            .iter()
            .all(|net| net.received_kbps.is_some() && net.sent_kbps.is_some()));
        assert!(second
            .disks
            .iter()
            .filter_map(|disk| disk.inode_usage_percent)
            .all(|percent| (0.0..=100.0).contains(&percent)));
        assert!(payload.memory.total_mib > 0.0);
        assert!((0.0..=100.0).contains(&payload.cpu.usage_percent));
        assert!(payload
            .disks
            .iter()
            .all(|disk| (0.0..=100.0).contains(&disk.usage_percent)));
        assert!(payload.network.iter().all(|net| net.interface != "lo"));
    }
}
