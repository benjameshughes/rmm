//! Windows integration tests of the real directory reader on a temp folder.

use super::walk::{walk, Scan, WalkOptions};
use super::windows::WinDirReader;
use super::{DirReader, EntryKind, FLAG_NOT_FOLLOWED};
use std::path::Path;
use std::process::Command;

fn scan(root: &Path) -> Scan {
    let options = WalkOptions {
        threads: 4,
        top: 10,
        deferred: Vec::new(),
    };
    walk(root, &WinDirReader, &options)
}

fn run(program: &str, args: &[&str]) {
    let status = Command::new(program).args(args).status().unwrap();
    assert!(status.success(), "{program} {args:?} failed");
}

#[test]
fn hard_links_are_counted_once() {
    let dir = tempfile::tempdir().unwrap();
    let original = dir.path().join("a.bin");
    std::fs::write(&original, vec![1u8; 100_000]).unwrap();
    std::fs::hard_link(&original, dir.path().join("b.bin")).unwrap();

    let scan = scan(dir.path());

    assert_eq!(scan.nodes[0].files, 1);
    assert_eq!(scan.nodes[0].logical, 100_000);
    assert!(scan.tally.deduped_bytes > 0);
}

#[test]
fn junctions_are_listed_but_not_followed() {
    let dir = tempfile::tempdir().unwrap();
    let target = tempfile::tempdir().unwrap();
    std::fs::write(target.path().join("big.bin"), vec![1u8; 200_000]).unwrap();
    let junction = dir.path().join("junction");
    run(
        "cmd",
        &[
            "/c",
            "mklink",
            "/J",
            &junction.to_string_lossy(),
            &target.path().to_string_lossy(),
        ],
    );

    let scan = scan(dir.path());

    let node = scan
        .nodes
        .iter()
        .find(|node| node.name == "junction")
        .unwrap();
    assert_eq!(node.flags, FLAG_NOT_FOLLOWED);
    assert_eq!(scan.nodes[0].logical, 0);
    assert_eq!(scan.tally.reparse_skipped, 1);
}

#[test]
fn directory_symlinks_are_not_followed() {
    let dir = tempfile::tempdir().unwrap();
    let target = tempfile::tempdir().unwrap();
    std::fs::write(target.path().join("big.bin"), vec![1u8; 200_000]).unwrap();
    std::os::windows::fs::symlink_dir(target.path(), dir.path().join("link")).unwrap();

    let entries = WinDirReader.read_dir(dir.path()).entries;

    assert_eq!(entries.len(), 1);
    assert_eq!(entries[0].kind, EntryKind::LinkNotFollowed);
    assert_eq!(scan(dir.path()).nodes[0].logical, 0);
}

#[test]
fn sparse_files_report_less_allocated_than_logical() {
    let dir = tempfile::tempdir().unwrap();
    let file = dir.path().join("sparse.bin");
    std::fs::write(&file, b"x").unwrap();
    run("fsutil", &["sparse", "setflag", &file.to_string_lossy()]);
    std::fs::OpenOptions::new()
        .write(true)
        .open(&file)
        .unwrap()
        .set_len(256 * 1024 * 1024)
        .unwrap();

    let scan = scan(dir.path());

    assert_eq!(scan.nodes[0].logical, 256 * 1024 * 1024);
    assert!(scan.nodes[0].allocated < 1024 * 1024);
}

#[test]
fn paths_longer_than_max_path_are_read() {
    let dir = tempfile::tempdir().unwrap();
    let mut deep = dir.path().to_path_buf();
    for level in 0..12 {
        deep.push(format!("level-{level:02}-padding-padding-padding"));
    }
    assert!(deep.to_string_lossy().len() > 300);
    std::fs::create_dir_all(&deep).unwrap();
    std::fs::write(deep.join("deep.bin"), vec![1u8; 4096]).unwrap();

    let scan = scan(dir.path());

    assert_eq!(scan.tally.errors.count, 0);
    assert_eq!(scan.nodes[0].files, 1);
    assert_eq!(scan.nodes[0].dirs, 12);
    assert_eq!(scan.top_files[0].name, "deep.bin");
}

#[test]
fn unicode_names_survive() {
    let dir = tempfile::tempdir().unwrap();
    let name = "Résumé ファイル 😀.txt";
    std::fs::write(dir.path().join(name), b"hello").unwrap();

    let entries = WinDirReader.read_dir(dir.path()).entries;

    assert_eq!(entries.len(), 1);
    assert_eq!(entries[0].name, name);
    assert_eq!(entries[0].logical, 5);
}

#[test]
fn large_folders_need_several_listing_calls() {
    let dir = tempfile::tempdir().unwrap();
    for index in 0..10_000 {
        std::fs::write(dir.path().join(format!("file-{index:05}.dat")), b"").unwrap();
    }

    let scan = scan(dir.path());

    assert_eq!(scan.tally.errors.count, 0);
    assert_eq!(scan.nodes[0].files, 10_000);
}

#[test]
fn refresh_reads_the_size_from_the_file() {
    let dir = tempfile::tempdir().unwrap();
    let file = dir.path().join("grow.bin");
    std::fs::write(&file, vec![1u8; 12_345]).unwrap();

    let (allocated, logical) = WinDirReader.refresh_size(&file).unwrap();

    assert_eq!(logical, 12_345);
    assert!(allocated >= logical);
}
