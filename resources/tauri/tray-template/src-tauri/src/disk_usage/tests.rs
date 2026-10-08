//! Platform-neutral tests of the walk and report against an in-memory tree.

use super::keep_pattern::KeepPattern;
use super::report::{render, ReportOptions};
use super::walk::{walk, Scan, WalkOptions};
use super::*;
use serde_json::Value;
use std::collections::HashMap;
use std::sync::Mutex;

/// In-memory directory tree keyed by full path.
#[derive(Default)]
struct FakeFs {
    dirs: HashMap<PathBuf, Vec<Entry>>,
    failing: HashMap<PathBuf, i32>,
    fresh_sizes: HashMap<PathBuf, (u64, u64)>,
    read_order: Mutex<Vec<PathBuf>>,
}

impl FakeFs {
    fn with(mut self, dir: &str, entries: Vec<Entry>) -> Self {
        self.dirs.insert(path(dir), entries);
        self
    }

    fn failing(mut self, dir: &str, code: i32) -> Self {
        self.failing.insert(path(dir), code);
        self
    }
}

impl DirReader for FakeFs {
    fn read_dir(&self, dir: &Path) -> DirListing {
        self.read_order.lock().unwrap().push(dir.to_path_buf());
        if let Some(code) = self.failing.get(dir) {
            return DirListing::failed(dir, *code);
        }
        DirListing {
            entries: self.dirs.get(dir).cloned().unwrap_or_default(),
            errors: Vec::new(),
        }
    }

    fn refresh_size(&self, file: &Path) -> Option<(u64, u64)> {
        self.fresh_sizes.get(file).copied()
    }
}

/// "root/a/b" built with the platform separator.
fn path(slashed: &str) -> PathBuf {
    slashed.split('/').collect()
}

fn file(name: &str, allocated: u64) -> Entry {
    Entry {
        name: name.to_string(),
        kind: EntryKind::File,
        allocated,
        logical: allocated,
        attributes: 32,
        modified: 1_700_000_000,
        link_key: None,
        is_cloud: false,
    }
}

fn linked(name: &str, allocated: u64, id: u64) -> Entry {
    Entry {
        link_key: Some((7, id)),
        ..file(name, allocated)
    }
}

fn folder(name: &str) -> Entry {
    Entry {
        kind: EntryKind::Dir,
        ..file(name, 0)
    }
}

fn special(name: &str, kind: EntryKind) -> Entry {
    Entry {
        kind,
        ..file(name, 0)
    }
}

fn scan(fs: &FakeFs, top: usize, deferred: Vec<PathBuf>) -> Scan {
    let options = WalkOptions {
        threads: 4,
        top,
        deferred,
    };
    walk(&path("root"), fs, &options)
}

fn options(min_bytes: u64, max_depth: u32, keep: &[&str]) -> ReportOptions {
    ReportOptions {
        root: "root".to_string(),
        max_depth,
        min_bytes,
        keep: keep.iter().map(|glob| KeepPattern::parse(glob)).collect(),
        volume: None,
        started_at: "2026-10-08T06:00:00Z".to_string(),
        duration_ms: 42,
    }
}

fn report(scan: &Scan, options: &ReportOptions) -> Value {
    serde_json::from_str(&render(scan, options)).unwrap()
}

fn node<'a>(scan: &'a Scan, name: &str) -> &'a walk::Node {
    scan.nodes.iter().find(|node| node.name == name).unwrap()
}

/// Rows whose parent is `row`.
fn children(report: &Value, row: usize) -> Vec<&Value> {
    report["nodes"]
        .as_array()
        .unwrap()
        .iter()
        .filter(|node| node[0].as_i64() == Some(row as i64))
        .collect()
}

fn sample_tree() -> FakeFs {
    FakeFs::default()
        .with("root", vec![file("a.txt", 100), folder("sub")])
        .with("root/sub", vec![file("b.bin", 200), folder("deep")])
        .with("root/sub/deep", vec![file("c.ost", 300)])
}

#[test]
fn sums_every_folder_bottom_up() {
    let scan = scan(&sample_tree(), 10, Vec::new());

    assert_eq!(scan.nodes[0].allocated, 600);
    assert_eq!(scan.nodes[0].files, 3);
    assert_eq!(scan.nodes[0].dirs, 2);
    assert_eq!(node(&scan, "sub").allocated, 500);
    assert_eq!(node(&scan, "deep").allocated, 300);
    assert_eq!(scan.tally.extensions[".ost"], (300, 1));
}

#[test]
fn counts_a_hard_link_once() {
    let fs = FakeFs::default()
        .with("root", vec![folder("one"), folder("two")])
        .with("root/one", vec![linked("x.dll", 400, 9)])
        .with(
            "root/two",
            vec![linked("x.dll", 400, 9), linked("y.dll", 50, 10)],
        );

    let scan = scan(&fs, 10, Vec::new());

    assert_eq!(scan.nodes[0].allocated, 450);
    assert_eq!(scan.nodes[0].files, 2);
    assert_eq!(scan.tally.deduped_bytes, 400);
}

#[test]
fn reads_deferred_folders_last_so_they_lose_shared_bytes() {
    let fs = FakeFs::default()
        .with("root", vec![folder("Windows")])
        .with("root/Windows", vec![folder("WinSxS"), folder("System32")])
        .with("root/Windows/WinSxS", vec![linked("k.dll", 900, 1)])
        .with("root/Windows/System32", vec![linked("k.dll", 900, 1)]);

    let scan = scan(&fs, 10, vec![path("root/Windows/WinSxS")]);

    assert_eq!(node(&scan, "System32").allocated, 900);
    assert_eq!(node(&scan, "WinSxS").allocated, 0);
    assert_eq!(
        fs.read_order.lock().unwrap().last(),
        Some(&path("root/Windows/WinSxS"))
    );
}

#[test]
fn never_enters_links_or_cloud_folders() {
    let fs = FakeFs::default()
        .with(
            "root",
            vec![
                special("junction", EntryKind::LinkNotFollowed),
                special("OneDrive", EntryKind::CloudDir),
                special("mnt", EntryKind::OtherVolume),
            ],
        )
        .with("root/junction", vec![file("loop", 999)]);

    let scan = scan(&fs, 10, Vec::new());

    assert_eq!(scan.nodes[0].allocated, 0);
    assert_eq!(node(&scan, "junction").flags, FLAG_NOT_FOLLOWED);
    assert_eq!(node(&scan, "mnt").flags, FLAG_NOT_FOLLOWED);
    assert_eq!(node(&scan, "OneDrive").flags, FLAG_CLOUD);
    assert_eq!(scan.tally.reparse_skipped, 2);
    assert_eq!(fs.read_order.lock().unwrap().len(), 1);
}

#[test]
fn counts_cloud_file_sizes_separately() {
    let cloud = Entry {
        is_cloud: true,
        allocated: 0,
        ..file("big.mp4", 5000)
    };
    let fs = FakeFs::default().with("root", vec![cloud]);

    let scan = scan(&fs, 10, Vec::new());

    assert_eq!(scan.tally.cloud_logical, 5000);
    assert_eq!(scan.nodes[0].allocated, 0);
}

#[test]
fn pruning_rolls_small_siblings_into_one_star_and_conserves_sums() {
    let mut root = vec![folder("big")];
    let mut fs = FakeFs::default().with("root/big", vec![file("x", 10_000)]);
    for index in 0..20 {
        let name = format!("small{index}");
        root.push(folder(&name));
        fs = fs.with(&format!("root/{name}"), vec![file("y", 10 + index)]);
    }
    let fs = fs.with("root", root);
    let scan = scan(&fs, 10, Vec::new());

    let report = report(&scan, &options(1000, 4, &[]));
    let rows = children(&report, 0);

    assert_eq!(rows.len(), 2);
    let star = rows.iter().find(|row| row[1] == "*").unwrap();
    assert_eq!(star[6], FLAG_OTHER);
    assert_eq!(star[5], 20);
    let total: u64 = rows.iter().map(|row| row[2].as_u64().unwrap()).sum();
    assert_eq!(total, scan.nodes[0].allocated);
}

#[test]
fn pruning_stops_at_the_depth_limit() {
    let scan = scan(&sample_tree(), 10, Vec::new());

    let report = report(&scan, &options(0, 1, &[]));
    let names: Vec<&str> = report["nodes"]
        .as_array()
        .unwrap()
        .iter()
        .map(|row| row[1].as_str().unwrap())
        .collect();

    assert_eq!(names, vec!["root", "sub"]);
}

#[test]
fn keep_patterns_survive_any_size_or_depth() {
    let fs = FakeFs::default()
        .with("root", vec![folder("Users"), folder("big")])
        .with("root/big", vec![file("x", 1_000_000)])
        .with("root/Users", vec![folder("ben")])
        .with("root/Users/ben", vec![folder("Temp")])
        .with("root/Users/ben/Temp", vec![file("t", 5)]);
    let scan = scan(&fs, 10, Vec::new());

    let report = report(&scan, &options(1000, 1, &[r"Users\*\Temp"]));
    let nodes = report["nodes"].as_array().unwrap();
    let temp = nodes.iter().find(|row| row[1] == "Temp").unwrap();

    assert_eq!(temp[6], FLAG_KEPT);
    assert_eq!(temp[2], 5);
    assert!(nodes.iter().any(|row| row[1] == "ben"));
    assert!(nodes.iter().any(|row| row[1] == "Users"));
}

#[test]
fn top_files_are_bounded_largest_first_and_always_include_root_files() {
    let mut files: Vec<Entry> = (0..50)
        .map(|i| file(&format!("f{i}.log"), 1000 + i))
        .collect();
    files.push(folder("nested"));
    let fs = FakeFs::default()
        .with("root", vec![folder("data"), file("pagefile.sys", 1)])
        .with("root/data", files)
        .with("root/data/nested", vec![file("deep.iso", 5000)]);
    let scan = scan(&fs, 10, Vec::new());

    let report = report(&scan, &options(1_000_000, 4, &[]));
    let top = report["top_files"].as_array().unwrap();

    assert_eq!(top.len(), 11);
    assert_eq!(
        top[0][1],
        path("data/nested/deep.iso").to_string_lossy().as_ref()
    );
    assert_eq!(top[0][0], 0);
    assert_eq!(top[1][1], path("data/f49.log").to_string_lossy().as_ref());
    assert_eq!(top[10][1], "pagefile.sys");
}

#[test]
fn stale_sizes_are_re_read_and_their_folder_corrected() {
    let mut fs = FakeFs::default()
        .with("root", vec![folder("vm")])
        .with("root/vm", vec![file("disk.vhdx", 100)]);
    fs.fresh_sizes
        .insert(path("root/vm/disk.vhdx"), (8000, 9000));

    let scan = scan(&fs, 10, Vec::new());

    assert_eq!(node(&scan, "vm").allocated, 8000);
    assert_eq!(scan.nodes[0].logical, 9000);
    assert_eq!(scan.top_files[0].allocated, 8000);
}

#[test]
fn shrinks_until_the_node_cap_is_met() {
    let names: Vec<String> = (0..5000).map(|i| format!("d{i}")).collect();
    let mut fs = FakeFs::default().with("root", names.iter().map(|n| folder(n)).collect());
    for (index, name) in names.iter().enumerate() {
        fs = fs.with(&format!("root/{name}"), vec![file("x", 1 + index as u64)]);
    }
    let scan = scan(&fs, 0, Vec::new());

    let report = report(&scan, &options(0, 4, &[]));
    let rows = report["nodes"].as_array().unwrap();

    assert!(rows.len() <= crate::config::DU_MAX_NODES);
    let total: u64 = children(&report, 0)
        .iter()
        .map(|row| row[2].as_u64().unwrap())
        .sum();
    assert_eq!(total, scan.nodes[0].allocated);
}

#[test]
fn errors_are_all_counted_but_only_a_sample_kept() {
    let names: Vec<String> = (0..80).map(|i| format!("locked{i}")).collect();
    let mut fs = FakeFs::default().with("root", names.iter().map(|n| folder(n)).collect());
    for name in &names {
        fs = fs.failing(&format!("root/{name}"), 5);
    }

    let scan = scan(&fs, 10, Vec::new());
    let report = report(&scan, &options(0, 4, &[]));

    assert_eq!(report["errors"]["count"], 80);
    assert_eq!(report["errors"]["sample"].as_array().unwrap().len(), 50);
    assert_eq!(report["errors"]["sample"][0][1], 5);
    assert_eq!(node(&scan, "locked3").flags, FLAG_INCOMPLETE);
}

#[test]
fn renders_the_rmm_du_1_schema() {
    let scan = scan(&sample_tree(), 10, Vec::new());
    let mut options = options(250, 4, &[]);
    options.volume = Some(Volume {
        total: 10_000,
        free: 4_000,
        fs: "NTFS".to_string(),
        is_volume_root: true,
    });

    let json = render(&scan, &options);

    let expected = format!(
        concat!(
            r#"{{"schema":"rmm.du/1","root":"root","agent":"{agent}","#,
            r#""started_at":"2026-10-08T06:00:00Z","duration_ms":42,"#,
            r#""volume":{{"total":10000,"free":4000,"fs":"NTFS"}},"#,
            r#""totals":{{"allocated":600,"logical":600,"files":3,"dirs":2,"#,
            r#""hardlink_bytes_deduped":0,"cloud_logical":0,"reparse_skipped":0,"unaccounted":5400}},"#,
            r#""nodes":[[-1,"root",600,600,3,2,0],[0,"sub",500,500,2,1,0],[1,"deep",300,300,1,0,0]],"#,
            r#""top_files":[[2,"c.ost",300,300,"2023-11-14T22:13:20Z",32],"#,
            r#"[1,"b.bin",200,200,"2023-11-14T22:13:20Z",32],"#,
            r#"[0,"a.txt",100,100,"2023-11-14T22:13:20Z",32]],"#,
            r#""extensions":[[".ost",300,1],[".bin",200,1],[".txt",100,1]],"#,
            r#""errors":{{"count":0,"sample":[]}}}}"#
        ),
        agent = crate::config::AGENT_VERSION,
    );

    assert_eq!(json, expected);
}
