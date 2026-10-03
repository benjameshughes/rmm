//! Native metrics for monitor-only (non-Windows) agents.
//!
//! Linux servers and containers run no Netdata: the agent reads CPU, memory,
//! load, uptime, filesystems and network counters itself with `sysinfo` and
//! posts them in the server's "standard" metrics format, flagged
//! `monitor_only` so the server knows this device never takes commands.

// Only non-Windows builds (and tests) collect native metrics.
#![cfg_attr(windows, allow(dead_code))]

use chrono::Utc;
use serde::Serialize;
use std::collections::HashSet;
use std::time::Instant;
use sysinfo::{Disks, Networks, System, MINIMUM_CPU_UPDATE_INTERVAL};

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
}

#[derive(Debug, Clone, Serialize)]
pub struct NetworkMetrics {
    pub interface: String,
    pub received_bytes: u64,
    pub sent_bytes: u64,
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
    })
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
}

impl Default for NativeCollector {
    fn default() -> Self {
        Self::new()
    }
}

impl NativeCollector {
    pub fn new() -> Self {
        let mut system = System::new();
        system.refresh_cpu_usage();

        Self {
            system,
            last_cpu_refresh: Instant::now(),
        }
    }

    /// How long to wait before CPU usage can be measured (sysinfo needs two
    /// refreshes at least `MINIMUM_CPU_UPDATE_INTERVAL` apart).
    pub fn cpu_settle_time(&self) -> std::time::Duration {
        MINIMUM_CPU_UPDATE_INTERVAL.saturating_sub(self.last_cpu_refresh.elapsed())
    }

    /// Read everything. Blocking (statvfs, /proc): call off the async runtime.
    pub fn collect(&mut self, hostname: &str, mac_addresses: &[String]) -> StandardMetricsPayload {
        self.system.refresh_cpu_usage();
        self.last_cpu_refresh = Instant::now();
        self.system.refresh_memory();

        let load = System::load_average();
        let memory = memory_metrics(
            self.system.total_memory(),
            self.system.used_memory(),
            self.system.free_memory(),
            self.system.available_memory(),
        );

        let mut seen_mounts = HashSet::new();
        let disks = Disks::new_with_refreshed_list()
            .iter()
            .filter_map(|disk| {
                disk_metrics(
                    &disk.mount_point().to_string_lossy(),
                    &disk.file_system().to_string_lossy(),
                    disk.total_space(),
                    disk.available_space(),
                )
            })
            .filter(|disk| seen_mounts.insert(disk.mount_point.clone()))
            .collect();

        let mut network: Vec<NetworkMetrics> = Networks::new_with_refreshed_list()
            .iter()
            .filter(|(name, _)| name.as_str() != "lo")
            .map(|(name, data)| NetworkMetrics {
                interface: name.clone(),
                received_bytes: data.total_received(),
                sent_bytes: data.total_transmitted(),
            })
            .collect();
        network.sort_by(|a, b| a.interface.cmp(&b.interface));

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
        }
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
            }],
            system_info: SystemInfoMetrics {
                os_name: Some("Debian GNU/Linux".to_string()),
                os_version: Some("12".to_string()),
                kernel_name: "Linux".to_string(),
                kernel_version: Some("6.8.12-4-pve".to_string()),
                architecture: "x86_64".to_string(),
            },
        }
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

        let payload = collector.collect("test-host", &[]);

        assert!(payload.monitor_only);
        assert!(payload.memory.total_mib > 0.0);
        assert!((0.0..=100.0).contains(&payload.cpu.usage_percent));
        assert!(payload
            .disks
            .iter()
            .all(|disk| (0.0..=100.0).contains(&disk.usage_percent)));
        assert!(payload.network.iter().all(|net| net.interface != "lo"));
    }
}
