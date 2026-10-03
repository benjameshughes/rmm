//! Pure parsers and arithmetic for the Linux metrics: /proc/diskstats,
//! /proc/stat, /sys/class/net counters, rates between two samples, inode
//! usage and the "top apps" selection. No I/O here, so everything is tested
//! on any platform.

// Only non-Windows builds collect native metrics.
#![cfg_attr(windows, allow(dead_code))]

use serde::Serialize;
use std::collections::HashMap;
use std::path::Path;
use std::time::Duration;

/// Bytes per /proc/diskstats sector (always 512, whatever the device).
pub const DISKSTATS_SECTOR_BYTES: u64 = 512;

/// Cumulative counters for one block device from /proc/diskstats.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct DiskCounters {
    pub sectors_read: u64,
    pub sectors_written: u64,
    /// Milliseconds the device had I/O in flight.
    pub io_ticks_ms: u64,
}

/// Parse /proc/diskstats into counters keyed by device name (`sda1`,
/// `nvme0n1p2`, `dm-0`, ...). Malformed lines are skipped.
pub fn parse_diskstats(text: &str) -> HashMap<String, DiskCounters> {
    text.lines()
        .filter_map(|line| {
            let fields: Vec<&str> = line.split_whitespace().collect();
            if fields.len() < 13 {
                return None;
            }
            let number = |index: usize| fields[index].parse::<u64>().ok();
            Some((
                fields[2].to_string(),
                DiskCounters {
                    sectors_read: number(5)?,
                    sectors_written: number(9)?,
                    io_ticks_ms: number(12)?,
                },
            ))
        })
        .collect()
}

/// The /proc/diskstats name for a mount's source device: `/dev/sda1` ->
/// `sda1`; `/dev/mapper/vg-root` -> its resolved target (`dm-0`). None for
/// sources that are not block devices (zfs datasets, `rootfs`, network
/// shares), so no I/O figures are guessed for them.
pub fn block_device_name(source: &str, resolved: Option<&Path>) -> Option<String> {
    if !source.starts_with("/dev/") {
        return None;
    }

    let path = resolved.unwrap_or_else(|| Path::new(source));
    let name = path.file_name()?.to_string_lossy().to_string();
    (!name.is_empty()).then_some(name)
}

/// Read and write throughput and busy percentage between two samples.
#[derive(Debug, Clone, Copy, PartialEq)]
pub struct DiskRates {
    pub read_kbps: Option<f64>,
    pub write_kbps: Option<f64>,
    pub utilization_percent: Option<f64>,
}

/// Disk rates in KiB/s and busy %, each None when its counter went backwards
/// (device reset or replaced) or no time passed.
pub fn disk_rates(previous: &DiskCounters, current: &DiskCounters, elapsed: Duration) -> DiskRates {
    let kib_per_sec = |before: u64, after: u64| {
        per_second(before, after, elapsed)
            .map(|sectors| round2(sectors * DISKSTATS_SECTOR_BYTES as f64 / 1024.0))
    };
    let elapsed_ms = elapsed.as_secs_f64() * 1000.0;

    DiskRates {
        read_kbps: kib_per_sec(previous.sectors_read, current.sectors_read),
        write_kbps: kib_per_sec(previous.sectors_written, current.sectors_written),
        utilization_percent: current
            .io_ticks_ms
            .checked_sub(previous.io_ticks_ms)
            .filter(|_| elapsed_ms > 0.0)
            .map(|busy_ms| round2((busy_ms as f64 / elapsed_ms * 100.0).clamp(0.0, 100.0))),
    }
}

/// Network throughput in kilobits per second (1000-based, as link speeds
/// are quoted) between two byte counters.
pub fn kilobits_per_second(
    previous_bytes: u64,
    current_bytes: u64,
    elapsed: Duration,
) -> Option<f64> {
    per_second(previous_bytes, current_bytes, elapsed).map(|bytes| round2(bytes * 8.0 / 1000.0))
}

/// Counter increase per second; None on a reset (counter went down) or when
/// no time has passed.
pub fn per_second(previous: u64, current: u64, elapsed: Duration) -> Option<f64> {
    let seconds = elapsed.as_secs_f64();
    if seconds <= 0.0 {
        return None;
    }
    current
        .checked_sub(previous)
        .map(|delta| delta as f64 / seconds)
}

/// procs_running / procs_blocked from /proc/stat.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct ProcStat {
    pub procs_running: Option<u64>,
    pub procs_blocked: Option<u64>,
}

pub fn parse_proc_stat(text: &str) -> ProcStat {
    let mut stat = ProcStat::default();
    for line in text.lines() {
        let mut fields = line.split_whitespace();
        let (Some(key), Some(value)) = (fields.next(), fields.next()) else {
            continue;
        };
        let value = value.parse::<u64>().ok();
        match key {
            "procs_running" => stat.procs_running = value,
            "procs_blocked" => stat.procs_blocked = value,
            _ => {}
        }
    }
    stat
}

/// A single counter file such as /sys/class/net/eth0/statistics/rx_errors.
pub fn parse_counter(text: &str) -> Option<u64> {
    text.trim().parse::<u64>().ok()
}

/// /sys/class/net/<if>/speed in Mbit/s. Virtual or down links report -1 or
/// nothing; those give None.
pub fn parse_link_speed(text: &str) -> Option<u64> {
    text.trim()
        .parse::<i64>()
        .ok()
        .filter(|speed| *speed > 0)
        .map(|speed| speed as u64)
}

/// Inodes in use as a percentage. None when the filesystem reports no inode
/// table (btrfs, some network and FUSE filesystems report 0).
pub fn inode_usage_percent(total_inodes: u64, free_inodes: u64) -> Option<f64> {
    if total_inodes == 0 {
        return None;
    }
    let used = total_inodes.saturating_sub(free_inodes.min(total_inodes));
    Some(round2(
        (used as f64 / total_inodes as f64 * 100.0).clamp(0.0, 100.0),
    ))
}

/// One app (processes grouped by name).
#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct AppUsage {
    pub name: String,
    pub cpu_percent: f64,
    pub memory_mib: f64,
}

/// Group per-process samples `(name, cpu % of one core, rss bytes)` by name.
/// CPU becomes a share of the whole machine (`cpu_count` cores).
pub fn group_apps<'a>(
    processes: impl IntoIterator<Item = (&'a str, f32, u64)>,
    cpu_count: usize,
) -> Vec<AppUsage> {
    let cores = cpu_count.max(1) as f64;
    let mut grouped: HashMap<&str, (f64, u64)> = HashMap::new();

    for (name, cpu, rss) in processes {
        if name.is_empty() {
            continue;
        }
        let entry = grouped.entry(name).or_default();
        entry.0 += f64::from(cpu.max(0.0));
        entry.1 += rss;
    }

    grouped
        .into_iter()
        .map(|(name, (cpu, rss))| AppUsage {
            name: name.to_string(),
            cpu_percent: round2((cpu / cores).clamp(0.0, 100.0)),
            memory_mib: round2(rss as f64 / 1024.0 / 1024.0),
        })
        .collect()
}

/// The busiest `limit` apps by CPU plus the biggest `limit` by memory (an
/// idle app hogging RAM is kept alongside the ones burning CPU), sorted by
/// CPU then memory, both descending. Same semantics as the server's
/// NetdataAppUsage::top.
pub fn top_apps(apps: Vec<AppUsage>, limit: usize) -> Vec<AppUsage> {
    let by = |key: fn(&AppUsage) -> f64| {
        let mut sorted: Vec<&AppUsage> = apps.iter().collect();
        sorted.sort_by(|a, b| key(b).total_cmp(&key(a)).then_with(|| a.name.cmp(&b.name)));
        sorted
            .into_iter()
            .take(limit)
            .map(|app| app.name.clone())
            .collect::<Vec<_>>()
    };

    let mut keep = by(|app| app.cpu_percent);
    for name in by(|app| app.memory_mib) {
        if !keep.contains(&name) {
            keep.push(name);
        }
    }

    let mut top: Vec<AppUsage> = apps
        .into_iter()
        .filter(|app| keep.contains(&app.name))
        .collect();
    top.sort_by(|a, b| {
        b.cpu_percent
            .total_cmp(&a.cpu_percent)
            .then_with(|| b.memory_mib.total_cmp(&a.memory_mib))
            .then_with(|| a.name.cmp(&b.name))
    });
    top
}

fn round2(value: f64) -> f64 {
    (value * 100.0).round() / 100.0
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::path::PathBuf;

    const DISKSTATS: &str = "\
   7       0 loop0 53 0 2210 12 0 0 0 0 0 40 12 0 0 0 0
   8       0 sda 123456 789 9876543 4000 65432 1000 2468000 9000 0 120000 13000 0 0 0 0
   8       1 sda1 120000 700 9800000 3900 65000 990 2460000 8900 0 119000 12800 0 0 0 0
 253       0 dm-0 5000 0 400000 100 6000 0 480000 300 0 5000 400
 259       0 nvme0n1 1 2 3
";

    #[test]
    fn parses_diskstats() {
        let stats = parse_diskstats(DISKSTATS);

        assert_eq!(
            stats["sda1"],
            DiskCounters {
                sectors_read: 9_800_000,
                sectors_written: 2_460_000,
                io_ticks_ms: 119_000
            }
        );
        // Kernels before 4.18 have only 14 fields; still parsed.
        assert_eq!(stats["dm-0"].io_ticks_ms, 5000);
        // Too short to be valid.
        assert!(!stats.contains_key("nvme0n1"));
        assert_eq!(stats.len(), 4);
    }

    #[test]
    fn maps_mount_sources_to_block_devices() {
        assert_eq!(
            block_device_name("/dev/sda1", None),
            Some("sda1".to_string())
        );
        assert_eq!(
            block_device_name("/dev/mapper/pve-root", Some(&PathBuf::from("/dev/dm-1"))),
            Some("dm-1".to_string())
        );
        assert_eq!(
            block_device_name("/dev/nvme0n1p2", None),
            Some("nvme0n1p2".to_string())
        );
        // LXC / ZFS / pseudo sources: no block device to read.
        assert_eq!(
            block_device_name("rpool/data/subvol-101-disk-0", None),
            None
        );
        assert_eq!(block_device_name("rootfs", None), None);
        assert_eq!(block_device_name("//nas/share", None), None);
    }

    #[test]
    fn computes_disk_rates() {
        let before = DiskCounters {
            sectors_read: 1000,
            sectors_written: 2000,
            io_ticks_ms: 10_000,
        };
        let after = DiskCounters {
            sectors_read: 1000 + 2048 * 60,
            sectors_written: 2000 + 1024 * 60,
            io_ticks_ms: 10_000 + 15_000,
        };

        let rates = disk_rates(&before, &after, Duration::from_secs(60));

        assert_eq!(rates.read_kbps, Some(1024.0));
        assert_eq!(rates.write_kbps, Some(512.0));
        assert_eq!(rates.utilization_percent, Some(25.0));
    }

    #[test]
    fn disk_counter_resets_give_no_rate() {
        let before = DiskCounters {
            sectors_read: 5000,
            sectors_written: 5000,
            io_ticks_ms: 5000,
        };
        let after = DiskCounters {
            sectors_read: 10,
            sectors_written: 6000,
            io_ticks_ms: 10,
        };

        let rates = disk_rates(&before, &after, Duration::from_secs(60));

        assert_eq!(rates.read_kbps, None);
        assert!(rates.write_kbps.is_some());
        assert_eq!(rates.utilization_percent, None);
        assert_eq!(
            disk_rates(&before, &before, Duration::ZERO),
            DiskRates {
                read_kbps: None,
                write_kbps: None,
                utilization_percent: None
            }
        );
    }

    #[test]
    fn busy_percentage_never_exceeds_one_hundred() {
        let before = DiskCounters {
            sectors_read: 0,
            sectors_written: 0,
            io_ticks_ms: 0,
        };
        let after = DiskCounters {
            io_ticks_ms: 61_000,
            ..before
        };

        let rates = disk_rates(&before, &after, Duration::from_secs(60));
        assert_eq!(rates.utilization_percent, Some(100.0));
    }

    #[test]
    fn computes_network_kilobits_per_second() {
        // 7.5 MB in 60s = 1000 kbit/s.
        assert_eq!(
            kilobits_per_second(1_000, 1_000 + 7_500_000, Duration::from_secs(60)),
            Some(1000.0)
        );
        assert_eq!(
            kilobits_per_second(500, 500, Duration::from_secs(60)),
            Some(0.0)
        );
        // Interface re-created: counter reset.
        assert_eq!(
            kilobits_per_second(9_000, 100, Duration::from_secs(60)),
            None
        );
        assert_eq!(kilobits_per_second(0, 100, Duration::ZERO), None);
    }

    #[test]
    fn parses_proc_stat() {
        let stat = parse_proc_stat(
            "cpu  1 2 3 4\ncpu0 1 2 3 4\nprocesses 12345\nprocs_running 3\nprocs_blocked 1\nsoftirq 0 0\n",
        );

        assert_eq!(stat.procs_running, Some(3));
        assert_eq!(stat.procs_blocked, Some(1));
        assert_eq!(parse_proc_stat(""), ProcStat::default());
    }

    #[test]
    fn parses_net_counters_and_link_speed() {
        assert_eq!(parse_counter("42\n"), Some(42));
        assert_eq!(parse_counter(""), None);
        assert_eq!(parse_link_speed("1000\n"), Some(1000));
        assert_eq!(parse_link_speed("10000"), Some(10000));
        assert_eq!(parse_link_speed("-1\n"), None);
        assert_eq!(parse_link_speed("0"), None);
        assert_eq!(parse_link_speed(""), None);
    }

    #[test]
    fn computes_inode_usage() {
        assert_eq!(inode_usage_percent(1000, 250), Some(75.0));
        assert_eq!(inode_usage_percent(1000, 1000), Some(0.0));
        assert_eq!(inode_usage_percent(1000, 0), Some(100.0));
        // btrfs reports no inode table.
        assert_eq!(inode_usage_percent(0, 0), None);
        // Free larger than total (never seen, but never negative).
        assert_eq!(inode_usage_percent(10, 20), Some(0.0));
    }

    #[test]
    fn groups_processes_by_name_as_a_share_of_the_machine() {
        let mib = 1024 * 1024;
        let apps = group_apps(
            [
                ("php-fpm8.4", 50.0, 100 * mib),
                ("php-fpm8.4", 30.0, 50 * mib),
                ("nginx", 10.0, 20 * mib),
                ("", 99.0, mib),
            ],
            4,
        );

        let php = apps.iter().find(|app| app.name == "php-fpm8.4").unwrap();
        assert_eq!(php.cpu_percent, 20.0);
        assert_eq!(php.memory_mib, 150.0);
        let nginx = apps.iter().find(|app| app.name == "nginx").unwrap();
        assert_eq!(nginx.cpu_percent, 2.5);
        assert_eq!(apps.len(), 2);
    }

    fn app(name: &str, cpu_percent: f64, memory_mib: f64) -> AppUsage {
        AppUsage {
            name: name.to_string(),
            cpu_percent,
            memory_mib,
        }
    }

    #[test]
    fn keeps_top_by_cpu_plus_top_by_memory() {
        let apps = vec![
            app("busy", 50.0, 10.0),
            app("warm", 20.0, 20.0),
            app("idle-hog", 0.0, 4000.0),
            app("tiny", 0.1, 1.0),
            app("big", 1.0, 900.0),
        ];

        let top = top_apps(apps, 2);
        let names: Vec<&str> = top.iter().map(|app| app.name.as_str()).collect();

        // Top 2 CPU (busy, warm) + top 2 memory (idle-hog, big), sorted by CPU.
        assert_eq!(names, vec!["busy", "warm", "big", "idle-hog"]);
    }

    #[test]
    fn top_apps_overlap_is_not_duplicated() {
        let top = top_apps(vec![app("db", 40.0, 2000.0), app("web", 5.0, 100.0)], 10);

        assert_eq!(top.len(), 2);
        assert_eq!(top[0].name, "db");
        assert!(top_apps(vec![], 10).is_empty());
    }
}
