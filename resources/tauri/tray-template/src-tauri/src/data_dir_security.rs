//! Data directory hardening.
//!
//! On Windows the agent runs as LocalSystem and keeps its secrets (agent.key),
//! its configuration (config.json) and its logs in `C:\ProgramData\BenJH RMM`.
//! Sub-folders of ProgramData inherit an ACL that lets BUILTIN\Users create
//! files and folders, so without hardening any local user could plant files the
//! SYSTEM service later trusts.
//!
//! At service startup, before anything in the directory is read, we:
//!  1. make sure the data dir is a real directory (not a symlink/junction),
//!  2. drop any links / sub-directories that were planted inside it,
//!  3. lock the directory itself down to SYSTEM + Administrators (icacls,
//!     protected DACL, no inheritance) and make SYSTEM its owner,
//!  4. sweep the contents again and *rebuild* every regular file (copy the
//!     bytes into a brand-new file created by SYSTEM, then atomically replace
//!     the old name). Rebuilt files inherit the locked ACL and are owned by
//!     SYSTEM, which neutralises anything a user pre-created (ownership,
//!     explicit ACEs, hard links) without ever running a recursive ACL tool
//!     over a tree a user could have filled with links.
//!
//! Sub-directories (only `update\` exists legitimately, and nothing in it is
//! needed across restarts any more) are deleted without following links.
//!
//! Everything except the icacls invocation is platform independent and unit
//! tested.

// Only the Windows service calls into this module; keep macOS/Linux builds quiet.
#![cfg_attr(not(windows), allow(dead_code))]

use std::fs;
use std::io::{self, Write};
use std::path::{Path, PathBuf};

/// Regular files larger than this are deleted instead of rebuilt (the only
/// legitimate files are a tiny key, a tiny config and daily log files).
const MAX_REBUILD_BYTES: u64 = 64 * 1024 * 1024;

/// SID for NT AUTHORITY\SYSTEM.
pub const SID_SYSTEM: &str = "S-1-5-18";
/// SID for BUILTIN\Administrators.
pub const SID_ADMINISTRATORS: &str = "S-1-5-32-544";

/// Message shown when a CLI command cannot access the locked data dir.
pub fn elevation_message(data_dir: &Path) -> String {
    format!(
        "Access denied to {}.\nThe agent's data directory is restricted to SYSTEM and Administrators.\nRun this command from an elevated (Administrator) prompt.",
        data_dir.display()
    )
}

/// Arguments (after the directory path) that lock a single directory down:
/// remove inherited ACEs, protect the DACL, and grant full control (inherited
/// by children) to SYSTEM and Administrators only. Well-known SIDs are used so
/// this works on non-English Windows. Deliberately NOT recursive (/T): the
/// contents are rebuilt by [`sweep_contents`] instead.
pub fn icacls_lock_args() -> Vec<String> {
    vec![
        "/inheritance:r".to_string(),
        "/grant:r".to_string(),
        format!("*{}:(OI)(CI)F", SID_SYSTEM),
        format!("*{}:(OI)(CI)F", SID_ADMINISTRATORS),
        "/C".to_string(),
        "/Q".to_string(),
    ]
}

/// Arguments (after the directory path) that make SYSTEM the owner of the
/// directory itself (not recursive).
pub fn icacls_setowner_args() -> Vec<String> {
    vec![
        "/setowner".to_string(),
        format!("*{}", SID_SYSTEM),
        "/C".to_string(),
        "/Q".to_string(),
    ]
}

/// Outcome of a hardening pass. Messages are collected (rather than logged
/// directly) because hardening runs before logging is initialised.
#[derive(Debug, Default)]
pub struct HardeningReport {
    /// True when the directory ACL was successfully locked down.
    pub acl_locked: bool,
    pub info: Vec<String>,
    pub warnings: Vec<String>,
    pub errors: Vec<String>,
}

impl HardeningReport {
    /// Emit the collected messages to the tracing log.
    pub fn log(&self) {
        for msg in &self.info {
            tracing::info!("[data-dir] {}", msg);
        }
        for msg in &self.warnings {
            tracing::warn!("[data-dir] {}", msg);
        }
        for msg in &self.errors {
            tracing::error!("[data-dir] {}", msg);
        }
        if self.acl_locked {
            tracing::info!("[data-dir] Data directory locked to SYSTEM + Administrators");
        } else {
            tracing::error!(
                "[data-dir] SECURITY: data directory ACL could NOT be locked down - local users may be able to tamper with agent files"
            );
        }
    }
}

/// Harden the data directory. Never fails: problems are recorded in the
/// report so the service keeps running.
pub fn secure_data_dir(data_dir: &Path) -> HardeningReport {
    let mut report = HardeningReport::default();

    if let Err(e) = ensure_real_dir(data_dir, &mut report) {
        report
            .errors
            .push(format!("Cannot create data directory {}: {}", data_dir.display(), e));
        return report;
    }

    // Pre-lock pass: remove links / sub-directories so the ACL change cannot
    // be propagated through a planted junction.
    sweep_contents(data_dir, false, &mut report);

    match lock_directory_acl(data_dir, &mut report) {
        Ok(()) => report.acl_locked = true,
        Err(e) => report.errors.push(e),
    }

    // Post-lock pass: catch anything created in between and rebuild files so
    // they are owned by us and inherit the locked ACL.
    sweep_contents(data_dir, true, &mut report);

    report
}

/// Make sure `dir` exists and is a real directory, not a symlink/junction.
fn ensure_real_dir(dir: &Path, report: &mut HardeningReport) -> io::Result<()> {
    match fs::symlink_metadata(dir) {
        Ok(meta) if meta.file_type().is_symlink() => {
            report.errors.push(format!(
                "{} was a symlink/junction - removed it and created a real directory",
                dir.display()
            ));
            remove_link(dir)?;
        }
        Ok(meta) if !meta.is_dir() => {
            report.errors.push(format!(
                "{} was not a directory - removed it",
                dir.display()
            ));
            fs::remove_file(dir)?;
        }
        Ok(_) => return Ok(()),
        Err(e) if e.kind() == io::ErrorKind::NotFound => {}
        Err(e) => return Err(e),
    }
    fs::create_dir_all(dir)
}

/// Remove a symlink or junction itself (never its target).
fn remove_link(path: &Path) -> io::Result<()> {
    // File symlinks are removed with remove_file; directory symlinks and
    // junctions on Windows need remove_dir (which removes only the link).
    fs::remove_file(path).or_else(|_| fs::remove_dir(path))
}

/// Walk the top level of the data dir: remove links and sub-directories, and
/// (when `rebuild_files` is set) rebuild every regular file.
pub fn sweep_contents(dir: &Path, rebuild_files: bool, report: &mut HardeningReport) {
    let entries = match fs::read_dir(dir) {
        Ok(entries) => entries,
        Err(e) => {
            report
                .errors
                .push(format!("Cannot list {}: {}", dir.display(), e));
            return;
        }
    };

    for entry in entries.flatten() {
        let path = entry.path();
        let meta = match fs::symlink_metadata(&path) {
            Ok(m) => m,
            Err(e) => {
                report
                    .errors
                    .push(format!("Cannot stat {}: {}", path.display(), e));
                continue;
            }
        };
        let file_type = meta.file_type();

        if file_type.is_symlink() {
            match remove_link(&path) {
                Ok(()) => report
                    .info
                    .push(format!("Removed link {}", path.display())),
                Err(e) => report
                    .errors
                    .push(format!("Failed to remove link {}: {}", path.display(), e)),
            }
        } else if file_type.is_dir() {
            // remove_dir_all does not follow symlinks/junctions.
            match fs::remove_dir_all(&path) {
                Ok(()) => report
                    .info
                    .push(format!("Removed directory {}", path.display())),
                Err(e) => report.errors.push(format!(
                    "Failed to remove directory {}: {}",
                    path.display(),
                    e
                )),
            }
        } else if file_type.is_file() {
            if !rebuild_files {
                continue;
            }
            if meta.len() > MAX_REBUILD_BYTES {
                match fs::remove_file(&path) {
                    Ok(()) => report.info.push(format!(
                        "Removed oversized file {} ({} bytes)",
                        path.display(),
                        meta.len()
                    )),
                    Err(e) => report.errors.push(format!(
                        "Failed to remove oversized file {}: {}",
                        path.display(),
                        e
                    )),
                }
                continue;
            }
            if let Err(e) = rebuild_file(&path) {
                report
                    .errors
                    .push(format!("Failed to rebuild {}: {}", path.display(), e));
            }
        } else if let Err(e) = fs::remove_file(&path) {
            report
                .errors
                .push(format!("Failed to remove {}: {}", path.display(), e));
        }
    }
}

/// Replace `path` with a freshly created file holding the same bytes.
///
/// The new file is created by this process (so it is owned by SYSTEM when run
/// as the service and inherits the directory's ACL), then renamed over the old
/// name. If the old name was a hard link, only the name is replaced; the link
/// target is never written to.
pub fn rebuild_file(path: &Path) -> io::Result<()> {
    let contents = fs::read(path)?;
    let tmp = rebuild_tmp_path(path);

    let mut file = match create_new(&tmp) {
        Ok(f) => f,
        Err(e) if e.kind() == io::ErrorKind::AlreadyExists => {
            // Stale temp name (possibly planted): unlink the name, retry once.
            remove_link(&tmp)?;
            create_new(&tmp)?
        }
        Err(e) => return Err(e),
    };

    let write_result = file
        .write_all(&contents)
        .and_then(|_| file.sync_all());
    drop(file);
    if let Err(e) = write_result {
        let _ = fs::remove_file(&tmp);
        return Err(e);
    }

    if let Err(e) = fs::rename(&tmp, path) {
        let _ = fs::remove_file(&tmp);
        return Err(e);
    }
    Ok(())
}

fn create_new(path: &Path) -> io::Result<fs::File> {
    fs::OpenOptions::new().write(true).create_new(true).open(path)
}

fn rebuild_tmp_path(path: &Path) -> PathBuf {
    let name = path
        .file_name()
        .map(|n| n.to_string_lossy().to_string())
        .unwrap_or_default();
    path.with_file_name(format!(".{}.rebuild", name))
}

/// Full path to a tool in System32 (avoids PATH lookups when running as SYSTEM).
pub fn system32_tool(system_root: Option<&str>, tool: &str) -> PathBuf {
    let root = system_root
        .filter(|r| !r.trim().is_empty())
        .unwrap_or(r"C:\Windows");
    PathBuf::from(format!(r"{}\System32\{}", root.trim_end_matches('\\'), tool))
}

/// Lock the directory itself (not its contents) to SYSTEM + Administrators.
#[cfg(windows)]
fn lock_directory_acl(dir: &Path, report: &mut HardeningReport) -> Result<(), String> {
    let system_root = std::env::var("SystemRoot").ok();
    let icacls = system32_tool(system_root.as_deref(), "icacls.exe");

    let run = |args: Vec<String>, what: &str| -> Result<(), String> {
        let output = std::process::Command::new(&icacls)
            .arg(dir)
            .args(&args)
            .output()
            .map_err(|e| format!("Failed to run icacls ({}): {}", what, e))?;
        if output.status.success() {
            Ok(())
        } else {
            Err(format!(
                "icacls ({}) failed with {}: {} {}",
                what,
                output.status,
                String::from_utf8_lossy(&output.stdout).trim(),
                String::from_utf8_lossy(&output.stderr).trim()
            ))
        }
    };

    run(icacls_lock_args(), "lock ACL")?;

    // Ownership matters only if the directory was created by a non-admin
    // before the agent was installed; failure here is not fatal.
    if let Err(e) = run(icacls_setowner_args(), "set owner") {
        report.warnings.push(e);
    }

    Ok(())
}

#[cfg(not(windows))]
fn lock_directory_acl(dir: &Path, _report: &mut HardeningReport) -> Result<(), String> {
    use std::os::unix::fs::PermissionsExt;
    fs::set_permissions(dir, fs::Permissions::from_mode(0o700))
        .map_err(|e| format!("Failed to chmod 700 {}: {}", dir.display(), e))
}

/// Returns true if the error chain contains an I/O "permission denied" error.
pub fn is_permission_denied(err: &anyhow::Error) -> bool {
    err.chain().any(|cause| {
        cause
            .downcast_ref::<io::Error>()
            .map(|io_err| io_err.kind() == io::ErrorKind::PermissionDenied)
            .unwrap_or(false)
    })
}

/// Check that the current process can list the data directory. A missing
/// directory is fine (nothing has been written yet).
pub fn check_data_dir_access(data_dir: &Path) -> io::Result<()> {
    match fs::read_dir(data_dir) {
        Ok(_) => Ok(()),
        Err(e) if e.kind() == io::ErrorKind::NotFound => Ok(()),
        Err(e) => Err(e),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use tempfile::TempDir;

    #[test]
    fn lock_args_use_well_known_sids_and_are_not_recursive() {
        let args = icacls_lock_args();
        assert_eq!(
            args,
            vec![
                "/inheritance:r",
                "/grant:r",
                "*S-1-5-18:(OI)(CI)F",
                "*S-1-5-32-544:(OI)(CI)F",
                "/C",
                "/Q"
            ]
        );
        assert!(!args.iter().any(|a| a.eq_ignore_ascii_case("/T")));
        assert!(!args.iter().any(|a| a.contains("S-1-5-32-545"))); // Users
    }

    #[test]
    fn setowner_args_target_system() {
        assert_eq!(
            icacls_setowner_args(),
            vec!["/setowner", "*S-1-5-18", "/C", "/Q"]
        );
    }

    #[test]
    fn system32_tool_paths() {
        assert_eq!(
            system32_tool(Some(r"D:\WINDOWS"), "icacls.exe"),
            PathBuf::from(r"D:\WINDOWS\System32\icacls.exe")
        );
        assert_eq!(
            system32_tool(None, "icacls.exe"),
            PathBuf::from(r"C:\Windows\System32\icacls.exe")
        );
        assert_eq!(
            system32_tool(Some(""), "icacls.exe"),
            PathBuf::from(r"C:\Windows\System32\icacls.exe")
        );
    }

    #[test]
    fn secure_creates_missing_dir() {
        let tmp = TempDir::new().unwrap();
        let dir = tmp.path().join("data");
        let report = secure_data_dir(&dir);
        assert!(dir.is_dir());
        assert!(report.acl_locked, "errors: {:?}", report.errors);
    }

    #[test]
    fn secure_wipes_subdirectories_and_keeps_files() {
        let tmp = TempDir::new().unwrap();
        let dir = tmp.path();
        fs::write(dir.join("agent.key"), "secret").unwrap();
        fs::write(dir.join("config.json"), "{}").unwrap();
        fs::create_dir_all(dir.join("update")).unwrap();
        fs::write(dir.join("update").join("pending.json"), "{}").unwrap();
        fs::write(dir.join("update").join("rmm.exe.new"), "evil").unwrap();

        let report = secure_data_dir(dir);

        assert!(report.errors.is_empty(), "errors: {:?}", report.errors);
        assert!(!dir.join("update").exists());
        assert_eq!(fs::read_to_string(dir.join("agent.key")).unwrap(), "secret");
        assert_eq!(fs::read_to_string(dir.join("config.json")).unwrap(), "{}");
        assert!(!dir.join(".agent.key.rebuild").exists());
    }

    #[cfg(unix)]
    #[test]
    fn secure_removes_links_without_touching_targets() {
        use std::os::unix::fs::symlink;

        let tmp = TempDir::new().unwrap();
        let outside = tmp.path().join("outside");
        fs::create_dir_all(&outside).unwrap();
        fs::write(outside.join("precious.txt"), "keep me").unwrap();

        let dir = tmp.path().join("data");
        fs::create_dir_all(&dir).unwrap();
        symlink(&outside, dir.join("update")).unwrap();
        symlink(outside.join("precious.txt"), dir.join("agent.log.2099-01-01")).unwrap();

        let report = secure_data_dir(&dir);

        assert!(report.errors.is_empty(), "errors: {:?}", report.errors);
        assert!(fs::symlink_metadata(dir.join("update")).is_err());
        assert!(fs::symlink_metadata(dir.join("agent.log.2099-01-01")).is_err());
        assert_eq!(
            fs::read_to_string(outside.join("precious.txt")).unwrap(),
            "keep me"
        );
    }

    #[cfg(unix)]
    #[test]
    fn secure_replaces_data_dir_symlink_with_real_dir() {
        use std::os::unix::fs::symlink;

        let tmp = TempDir::new().unwrap();
        let outside = tmp.path().join("outside");
        fs::create_dir_all(&outside).unwrap();
        fs::write(outside.join("precious.txt"), "keep me").unwrap();
        let dir = tmp.path().join("data");
        symlink(&outside, &dir).unwrap();

        let report = secure_data_dir(&dir);

        assert!(!fs::symlink_metadata(&dir).unwrap().file_type().is_symlink());
        assert!(dir.is_dir());
        assert!(!report.errors.is_empty()); // the replacement is reported loudly
        assert_eq!(
            fs::read_to_string(outside.join("precious.txt")).unwrap(),
            "keep me"
        );
    }

    #[cfg(unix)]
    #[test]
    fn rebuild_breaks_hard_links() {
        use std::os::unix::fs::MetadataExt;

        let tmp = TempDir::new().unwrap();
        let target = tmp.path().join("system-file");
        fs::write(&target, "original").unwrap();
        let dir = tmp.path().join("data");
        fs::create_dir_all(&dir).unwrap();
        let link = dir.join("agent.log");
        fs::hard_link(&target, &link).unwrap();

        rebuild_file(&link).unwrap();
        fs::write(&link, "agent writes").unwrap();

        assert_eq!(fs::read_to_string(&target).unwrap(), "original");
        assert_eq!(fs::metadata(&target).unwrap().nlink(), 1);
    }

    #[test]
    fn rebuild_handles_stale_temp_file() {
        let tmp = TempDir::new().unwrap();
        let file = tmp.path().join("config.json");
        fs::write(&file, "real").unwrap();
        fs::write(tmp.path().join(".config.json.rebuild"), "planted").unwrap();

        rebuild_file(&file).unwrap();

        assert_eq!(fs::read_to_string(&file).unwrap(), "real");
        assert!(!tmp.path().join(".config.json.rebuild").exists());
    }

    /// Runs the real icacls on a Windows box (CI) and reads the ACL back.
    #[cfg(windows)]
    #[test]
    fn windows_acl_is_protected_and_limited_to_system_and_admins() {
        let tmp = TempDir::new().unwrap();
        let dir = tmp.path().join("data");
        fs::create_dir_all(&dir).unwrap();
        fs::write(dir.join("agent.key"), "secret").unwrap();

        let report = secure_data_dir(&dir);
        assert!(report.acl_locked, "errors: {:?}", report.errors);
        assert!(report.errors.is_empty(), "errors: {:?}", report.errors);

        let icacls = system32_tool(std::env::var("SystemRoot").ok().as_deref(), "icacls.exe");
        let dir_acl = std::process::Command::new(&icacls).arg(&dir).output().unwrap();
        let dir_acl = String::from_utf8_lossy(&dir_acl.stdout).to_string();
        assert!(!dir_acl.contains("(I)"), "dir still inherits: {}", dir_acl);
        assert_eq!(dir_acl.matches("(OI)(CI)(F)").count(), 2, "{}", dir_acl);

        // The rebuilt file inherits exactly the two ACEs from the locked dir.
        let file_acl = std::process::Command::new(&icacls)
            .arg(dir.join("agent.key"))
            .output()
            .unwrap();
        let file_acl = String::from_utf8_lossy(&file_acl.stdout).to_string();
        assert_eq!(file_acl.matches("(I)(F)").count(), 2, "{}", file_acl);
        assert_eq!(fs::read_to_string(dir.join("agent.key")).unwrap(), "secret");
    }

    #[test]
    fn detects_permission_denied_in_chain() {
        let io_err = io::Error::new(io::ErrorKind::PermissionDenied, "denied");
        let err = anyhow::Error::new(io_err).context("Failed to read runtime config file");
        assert!(is_permission_denied(&err));

        let other = anyhow::Error::new(io::Error::new(io::ErrorKind::NotFound, "nope"));
        assert!(!is_permission_denied(&other));
        assert!(!is_permission_denied(&anyhow::anyhow!("plain")));
    }

    #[test]
    fn access_check_allows_missing_dir() {
        let tmp = TempDir::new().unwrap();
        assert!(check_data_dir_access(&tmp.path().join("missing")).is_ok());
        assert!(check_data_dir_access(tmp.path()).is_ok());
    }

    #[test]
    fn elevation_message_mentions_admin() {
        let msg = elevation_message(Path::new("/data"));
        assert!(msg.contains("elevated (Administrator)"));
    }
}
