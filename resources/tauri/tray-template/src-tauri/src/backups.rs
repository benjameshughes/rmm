//! Backup status: backup scripts on a Linux server write one `<job>.json`
//! per job into `BACKUP_STATUS_DIR`, and each report forwards them.
//!
//! Strictly read-only: the agent only reads those files. It never runs
//! restic or anything else.

// Only Linux builds report backups.
#![cfg_attr(not(target_os = "linux"), allow(dead_code))]

use chrono::{DateTime, Utc};
use serde::{Deserialize, Serialize};
use serde_json::Value;
use std::cmp::Reverse;
use std::fs::{self, File};
use std::io::{ErrorKind, Read};
use std::path::{Path, PathBuf};
use tracing::debug;

use crate::config::{
    BACKUP_ERROR_MAX_CHARS, BACKUP_SNAPSHOTS_SENT, BACKUP_STATUS_MAX_FILES,
    BACKUP_STATUS_MAX_FILE_BYTES,
};

/// One entry of `backups` in the metrics payload.
#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct BackupStatus {
    /// The file name without `.json`.
    pub job: String,
    pub tool: Option<String>,
    pub repository: Option<String>,
    pub exit_code: Option<i64>,
    pub started_at: Option<String>,
    pub finished_at: Option<String>,
    pub file_modified_at: Option<String>,
    /// Newest first, raw restic snapshot objects.
    pub snapshots: Vec<Value>,
    /// Every snapshot in the file, not just those sent.
    pub snapshot_count: usize,
    /// Raw `restic stats --json --mode raw-data`.
    pub stats: Option<Value>,
    /// Why the file could not be read; everything else is empty then.
    pub error: Option<String>,
}

/// A status file as a backup script writes it. Unknown keys are ignored.
#[derive(Debug, Deserialize)]
struct StatusFile {
    tool: Option<String>,
    repository: Option<String>,
    exit_code: Option<i64>,
    started_at: Option<String>,
    finished_at: Option<String>,
    snapshots: Option<Vec<Value>>,
    stats: Option<Value>,
}

/// Backups for this report (Linux only). None when there is no status
/// directory, so the key is left out of the payload.
#[cfg(target_os = "linux")]
pub async fn collect() -> Option<Vec<BackupStatus>> {
    tokio::task::spawn_blocking(|| {
        read_backup_statuses(Path::new(crate::config::BACKUP_STATUS_DIR))
    })
    .await
    .ok()
    .flatten()
}

#[cfg(not(target_os = "linux"))]
pub async fn collect() -> Option<Vec<BackupStatus>> {
    None
}

/// Every `<job>.json` in `dir`, by job name. Symlinks, other non-regular
/// files and files over the size cap are skipped.
pub fn read_backup_statuses(dir: &Path) -> Option<Vec<BackupStatus>> {
    let entries = match fs::read_dir(dir) {
        Ok(entries) => entries,
        Err(e) if e.kind() == ErrorKind::NotFound => return None,
        Err(e) => {
            debug!("Cannot list {}: {}", dir.display(), e);
            return None;
        }
    };

    let mut files: Vec<(String, PathBuf)> = entries
        .filter_map(|entry| entry.ok())
        .filter_map(|entry| {
            let path = entry.path();
            job_name(&path).map(|job| (job, path))
        })
        .collect();
    files.sort();

    Some(
        files
            .iter()
            .filter_map(|(job, path)| read_status(job, path))
            .take(BACKUP_STATUS_MAX_FILES)
            .collect(),
    )
}

/// The job a status file is for: its name without `.json`, when that is a
/// valid job name (`[a-z0-9_-]{1,64}`).
fn job_name(path: &Path) -> Option<String> {
    if path.extension()? != "json" {
        return None;
    }

    let job = path.file_stem()?.to_str()?;
    let is_valid = (1..=64).contains(&job.len())
        && job
            .bytes()
            .all(|b| b.is_ascii_lowercase() || b.is_ascii_digit() || b == b'_' || b == b'-');

    is_valid.then(|| job.to_string())
}

/// None when the file is skipped; an entry with `error` when it is there
/// but unreadable.
fn read_status(job: &str, path: &Path) -> Option<BackupStatus> {
    let metadata = fs::symlink_metadata(path).ok()?;
    if !metadata.file_type().is_file() || metadata.len() > BACKUP_STATUS_MAX_FILE_BYTES {
        return None;
    }

    let modified_at = metadata
        .modified()
        .ok()
        .map(|at| DateTime::<Utc>::from(at).to_rfc3339());

    let contents = match read_capped(path) {
        Ok(Some(contents)) => contents,
        Ok(None) => return None,
        Err(e) => {
            return Some(unreadable(
                job,
                modified_at,
                format!("Cannot read the file: {e}"),
            ))
        }
    };

    Some(match serde_json::from_slice::<StatusFile>(&contents) {
        Ok(file) => parsed(job, modified_at, file),
        Err(e) => unreadable(job, modified_at, format!("Invalid status file: {e}")),
    })
}

/// The file's bytes, or None when it grew past the cap or stopped being a
/// regular file since it was listed.
fn read_capped(path: &Path) -> std::io::Result<Option<Vec<u8>>> {
    let file = File::open(path)?;
    if !file.metadata()?.is_file() {
        return Ok(None);
    }

    let mut contents = Vec::new();
    file.take(BACKUP_STATUS_MAX_FILE_BYTES + 1)
        .read_to_end(&mut contents)?;

    Ok((contents.len() as u64 <= BACKUP_STATUS_MAX_FILE_BYTES).then_some(contents))
}

fn parsed(job: &str, modified_at: Option<String>, file: StatusFile) -> BackupStatus {
    let snapshots = file.snapshots.unwrap_or_default();

    BackupStatus {
        job: job.to_string(),
        tool: file.tool,
        repository: file.repository,
        exit_code: file.exit_code,
        started_at: file.started_at,
        finished_at: file.finished_at,
        file_modified_at: modified_at,
        snapshot_count: snapshots.len(),
        snapshots: newest_snapshots(snapshots, BACKUP_SNAPSHOTS_SENT),
        stats: file.stats,
        error: None,
    }
}

fn unreadable(job: &str, modified_at: Option<String>, error: String) -> BackupStatus {
    BackupStatus {
        job: job.to_string(),
        tool: None,
        repository: None,
        exit_code: None,
        started_at: None,
        finished_at: None,
        file_modified_at: modified_at,
        snapshots: Vec::new(),
        snapshot_count: 0,
        stats: None,
        error: Some(error.chars().take(BACKUP_ERROR_MAX_CHARS).collect()),
    }
}

/// The `keep` newest snapshots by their `time`, newest first. Snapshots
/// without a readable time sort last.
fn newest_snapshots(mut snapshots: Vec<Value>, keep: usize) -> Vec<Value> {
    snapshots.sort_by_cached_key(|snapshot| Reverse(snapshot_time(snapshot)));
    snapshots.truncate(keep);
    snapshots
}

fn snapshot_time(snapshot: &Value) -> Option<DateTime<Utc>> {
    let time = snapshot.get("time")?.as_str()?;
    DateTime::parse_from_rfc3339(time)
        .ok()
        .map(|time| time.with_timezone(&Utc))
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn snapshot(short_id: &str, time: &str) -> Value {
        json!({
            "id": format!("{short_id}0000"),
            "short_id": short_id,
            "time": time,
            "hostname": "achcto",
            "paths": ["/var/backups/mariadb"],
            "summary": {
                "total_bytes_processed": 1024,
                "data_added": 512,
                "total_files_processed": 3
            }
        })
    }

    fn status_file(snapshots: Vec<Value>) -> Value {
        json!({
            "schema": "rmm.backup/1",
            "tool": "restic",
            "job": "mariadb",
            "repository": "sftp:scarif:/mnt/scarif/data/backups/achcto",
            "exit_code": 0,
            "started_at": "2026-10-09T09:00:00+01:00",
            "finished_at": "2026-10-09T09:04:02+01:00",
            "snapshots": snapshots,
            "stats": { "total_size": 2048, "snapshots_count": 2 }
        })
    }

    fn write(dir: &Path, name: &str, contents: &str) {
        fs::write(dir.join(name), contents).unwrap();
    }

    #[test]
    fn a_missing_directory_sends_nothing() {
        let dir = tempfile::tempdir().unwrap();

        assert_eq!(read_backup_statuses(&dir.path().join("backups")), None);
    }

    #[test]
    fn an_empty_directory_sends_an_empty_list() {
        let dir = tempfile::tempdir().unwrap();

        assert_eq!(read_backup_statuses(dir.path()), Some(vec![]));
    }

    #[test]
    fn reads_a_valid_status_file() {
        let dir = tempfile::tempdir().unwrap();
        let older = snapshot("aaaa", "2026-10-08T09:00:01.123456789+01:00");
        let newer = snapshot("bbbb", "2026-10-09T09:00:01.987654321+01:00");
        write(
            dir.path(),
            "mariadb.json",
            &status_file(vec![older.clone(), newer.clone()]).to_string(),
        );

        let statuses = read_backup_statuses(dir.path()).unwrap();

        assert_eq!(statuses.len(), 1);
        let status = &statuses[0];
        assert_eq!(status.job, "mariadb");
        assert_eq!(status.tool.as_deref(), Some("restic"));
        assert_eq!(
            status.repository.as_deref(),
            Some("sftp:scarif:/mnt/scarif/data/backups/achcto")
        );
        assert_eq!(status.exit_code, Some(0));
        assert_eq!(
            status.started_at.as_deref(),
            Some("2026-10-09T09:00:00+01:00")
        );
        assert_eq!(
            status.finished_at.as_deref(),
            Some("2026-10-09T09:04:02+01:00")
        );
        assert!(status.file_modified_at.is_some());
        assert_eq!(status.snapshots, vec![newer, older]);
        assert_eq!(status.snapshot_count, 2);
        assert_eq!(
            status.stats,
            Some(json!({ "total_size": 2048, "snapshots_count": 2 }))
        );
        assert_eq!(status.error, None);
    }

    #[test]
    fn missing_snapshots_and_stats_are_empty() {
        let dir = tempfile::tempdir().unwrap();
        write(
            dir.path(),
            "web.json",
            r#"{"tool":"restic","exit_code":1,"snapshots":null,"stats":null}"#,
        );

        let status = &read_backup_statuses(dir.path()).unwrap()[0];

        assert_eq!(status.exit_code, Some(1));
        assert!(status.snapshots.is_empty());
        assert_eq!(status.snapshot_count, 0);
        assert_eq!(status.stats, None);
        assert_eq!(status.error, None);
    }

    #[test]
    fn a_malformed_file_is_reported_with_a_short_error() {
        let dir = tempfile::tempdir().unwrap();
        write(dir.path(), "mariadb.json", "{\"tool\": \"restic\", ");
        write(dir.path(), "web.json", r#"{"exit_code":"zero"}"#);

        let statuses = read_backup_statuses(dir.path()).unwrap();

        assert_eq!(statuses.len(), 2);
        for status in &statuses {
            let error = status.error.as_deref().unwrap();
            assert!(error.starts_with("Invalid status file:"), "{error}");
            assert!(error.chars().count() <= BACKUP_ERROR_MAX_CHARS);
            assert!(status.file_modified_at.is_some());
            assert!(status.snapshots.is_empty());
        }
        assert_eq!(statuses[0].job, "mariadb");
        assert_eq!(statuses[1].job, "web");
    }

    #[test]
    fn long_errors_are_cut_short() {
        let status = unreadable("web", None, "x".repeat(1000));

        assert_eq!(
            status.error.unwrap().chars().count(),
            BACKUP_ERROR_MAX_CHARS
        );
    }

    #[test]
    fn an_oversize_file_is_skipped() {
        let dir = tempfile::tempdir().unwrap();
        let padding = " ".repeat(BACKUP_STATUS_MAX_FILE_BYTES as usize);
        write(dir.path(), "huge.json", &format!("{{}}{padding}"));
        write(dir.path(), "web.json", "{}");

        let statuses = read_backup_statuses(dir.path()).unwrap();

        assert_eq!(statuses.len(), 1);
        assert_eq!(statuses[0].job, "web");
    }

    #[cfg(unix)]
    #[test]
    fn symlinks_are_skipped() {
        let dir = tempfile::tempdir().unwrap();
        let elsewhere = tempfile::tempdir().unwrap();
        write(elsewhere.path(), "secret.json", "{}");
        std::os::unix::fs::symlink(
            elsewhere.path().join("secret.json"),
            dir.path().join("linked.json"),
        )
        .unwrap();
        fs::create_dir(dir.path().join("folder.json")).unwrap();

        assert_eq!(read_backup_statuses(dir.path()), Some(vec![]));
    }

    #[test]
    fn only_job_named_json_files_are_read() {
        let dir = tempfile::tempdir().unwrap();
        write(dir.path(), "mariadb.json", "{}");
        write(dir.path(), "mariadb.json.tmp", "{}");
        write(dir.path(), "notes.txt", "{}");
        write(dir.path(), "Upper.json", "{}");
        write(dir.path(), "has space.json", "{}");
        write(dir.path(), &format!("{}.json", "a".repeat(65)), "{}");

        let statuses = read_backup_statuses(dir.path()).unwrap();

        assert_eq!(statuses.len(), 1);
        assert_eq!(statuses[0].job, "mariadb");
    }

    #[test]
    fn at_most_32_files_are_read() {
        let dir = tempfile::tempdir().unwrap();
        for index in 0..40 {
            write(dir.path(), &format!("job-{index:02}.json"), "{}");
        }

        let statuses = read_backup_statuses(dir.path()).unwrap();

        assert_eq!(statuses.len(), BACKUP_STATUS_MAX_FILES);
        assert_eq!(statuses[0].job, "job-00");
        assert_eq!(statuses[31].job, "job-31");
    }

    #[test]
    fn sends_the_200_newest_snapshots_newest_first() {
        let start = DateTime::parse_from_rfc3339("2026-01-01T00:00:00+00:00").unwrap();
        let mut snapshots: Vec<Value> = (0..250)
            .map(|day| {
                let time = start + chrono::Duration::days(day);
                snapshot(&format!("{day:04}"), &time.to_rfc3339())
            })
            .collect();
        snapshots.reverse();
        snapshots.swap(10, 120);
        snapshots.push(json!({ "short_id": "notime" }));

        let status = parsed(
            "mariadb",
            None,
            serde_json::from_value(status_file(snapshots)).unwrap(),
        );

        assert_eq!(status.snapshot_count, 251);
        assert_eq!(status.snapshots.len(), BACKUP_SNAPSHOTS_SENT);
        assert_eq!(status.snapshots[0]["short_id"], "0249");
        assert_eq!(status.snapshots[199]["short_id"], "0050");
    }

    #[test]
    fn compares_snapshot_times_across_offsets() {
        let snapshots = vec![
            snapshot("utc", "2026-10-09T08:30:00Z"),
            snapshot("bst", "2026-10-09T09:00:00+01:00"),
            snapshot("broken", "yesterday"),
        ];

        let newest = newest_snapshots(snapshots, 10);

        let ids: Vec<&str> = newest
            .iter()
            .map(|snapshot| snapshot["short_id"].as_str().unwrap())
            .collect();
        assert_eq!(ids, vec!["utc", "bst", "broken"]);
    }

    #[test]
    fn serialises_every_key_even_when_empty() {
        let status = unreadable("web", None, "Invalid status file: EOF".to_string());

        assert_eq!(
            serde_json::to_value(&status).unwrap(),
            json!({
                "job": "web",
                "tool": null,
                "repository": null,
                "exit_code": null,
                "started_at": null,
                "finished_at": null,
                "file_modified_at": null,
                "snapshots": [],
                "snapshot_count": 0,
                "stats": null,
                "error": "Invalid status file: EOF"
            })
        );
    }
}
