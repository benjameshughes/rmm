//! Runs a single admin-queued script on this machine and captures the result.
//!
//! Scripts are written to a uniquely named file inside the agent's locked data
//! directory (SYSTEM + Administrators only), executed with an absolute
//! interpreter path, killed together with any child processes when they exceed
//! their timeout, and deleted afterwards.
//!
//! Script parameters reach the child only as `RMM_<NAME>` environment
//! variables, never spliced into the script text, so values need no escaping
//! and cannot inject code.
//!
//! Only Windows agents run commands. Every other build is monitor-only by
//! construction: `run_script` refuses without touching disk, and the executor
//! behind it is only reachable from Windows builds (and this module's tests).

// The executor is only called from Windows builds and tests.
#![cfg_attr(not(any(windows, test)), allow(dead_code))]

use crate::command_progress::{ProgressLines, ProgressSender};
use std::collections::HashMap;
use std::path::{Path, PathBuf};
use std::process::Stdio;
use std::time::{Duration, Instant};
use tokio::io::{AsyncRead, AsyncReadExt};
use tokio::process::Command;

const STDERR_SEPARATOR: &str = "\n\n--- stderr ---\n";
const TRUNCATION_MARKER: &str = "\n[output truncated]";
const PARAMETER_ENV_PREFIX: &str = "RMM_";

/// Whether this build may run commands at all. Compile-time: false on every
/// non-Windows build (Linux agents are read-only monitors).
pub const COMMANDS_ENABLED: bool = cfg!(windows);

/// Error returned by `run_script` on monitor-only builds.
pub const MONITOR_ONLY_REFUSAL: &str = "This agent is monitor-only and never runs commands";

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum ScriptType {
    Powershell,
    Cmd,
    Bash,
    Sh,
    Unknown(String),
}

impl ScriptType {
    pub fn parse(value: &str) -> Self {
        match value.trim().to_ascii_lowercase().as_str() {
            "powershell" => ScriptType::Powershell,
            "cmd" => ScriptType::Cmd,
            "bash" => ScriptType::Bash,
            "sh" => ScriptType::Sh,
            other => ScriptType::Unknown(other.to_string()),
        }
    }

    pub fn name(&self) -> &str {
        match self {
            ScriptType::Powershell => "powershell",
            ScriptType::Cmd => "cmd",
            ScriptType::Bash => "bash",
            ScriptType::Sh => "sh",
            ScriptType::Unknown(name) => name,
        }
    }

    fn file_extension(&self) -> &'static str {
        match self {
            ScriptType::Powershell => "ps1",
            ScriptType::Cmd => "cmd",
            ScriptType::Bash | ScriptType::Sh | ScriptType::Unknown(_) => "sh",
        }
    }

    fn is_supported_here(&self) -> bool {
        if cfg!(windows) {
            matches!(self, ScriptType::Powershell | ScriptType::Cmd)
        } else {
            matches!(self, ScriptType::Bash | ScriptType::Sh)
        }
    }
}

/// How long a script may run, how much output to keep, and where to stage it.
#[derive(Debug, Clone)]
pub struct RunLimits {
    pub timeout: Duration,
    pub output_limit: usize,
    pub drain_grace: Duration,
    pub work_dir: PathBuf,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ExecutionResult {
    pub exit_code: i32,
    pub output: String,
    pub error_message: Option<String>,
    pub timed_out: bool,
    pub duration: Duration,
}

impl ExecutionResult {
    fn failed(message: String, started: Instant) -> Self {
        Self {
            exit_code: -1,
            output: String::new(),
            error_message: Some(message),
            timed_out: false,
            duration: started.elapsed(),
        }
    }
}

pub fn clamp_timeout(requested_secs: u64, min_secs: u64, max_secs: u64) -> Duration {
    Duration::from_secs(requested_secs.clamp(min_secs, max_secs.max(min_secs)))
}

pub fn combine_output(stdout: &[u8], stderr: &[u8], limit_chars: usize) -> String {
    let stdout = String::from_utf8_lossy(stdout);
    let stderr = String::from_utf8_lossy(stderr);

    let combined = match stderr.trim().is_empty() {
        true => stdout.into_owned(),
        false => format!("{}{}{}", stdout, STDERR_SEPARATOR, stderr),
    };

    truncate_chars(combined, limit_chars)
}

fn truncate_chars(text: String, limit_chars: usize) -> String {
    if text.chars().count() <= limit_chars {
        return text;
    }

    let keep = limit_chars.saturating_sub(TRUNCATION_MARKER.chars().count());
    let mut truncated: String = text.chars().take(keep).collect();
    truncated.push_str(TRUNCATION_MARKER);
    truncated
}

/// The server already validates names; this guard keeps a malformed name
/// (`=`, NUL, spaces) from ever reaching the process environment.
fn is_valid_parameter_name(name: &str) -> bool {
    let mut chars = name.chars();

    matches!(chars.next(), Some(first) if first.is_ascii_alphabetic())
        && chars.all(|c| c.is_ascii_alphanumeric() || c == '_')
}

/// `RMM_<NAME>` pairs for every well-formed parameter, name casing preserved.
pub fn parameter_env_vars(parameters: &HashMap<String, String>) -> Vec<(String, String)> {
    parameters
        .iter()
        .filter(|(name, _)| is_valid_parameter_name(name))
        .map(|(name, value)| (format!("{}{}", PARAMETER_ENV_PREFIX, name), value.clone()))
        .collect()
}

/// Execute `content` as a script of `script_type` within `limits`, exposing
/// each of `parameters` as an `RMM_<NAME>` environment variable. `PROGRESS: `
/// lines on stdout go to `progress` instead of the output. Refuses on
/// monitor-only (non-Windows) builds.
pub async fn run_script(
    script_type: &ScriptType,
    content: &str,
    parameters: &HashMap<String, String>,
    command_id: u64,
    limits: &RunLimits,
    progress: ProgressSender,
) -> ExecutionResult {
    #[cfg(windows)]
    {
        run_script_unchecked(script_type, content, parameters, command_id, limits, progress).await
    }

    #[cfg(not(windows))]
    {
        let _ = (script_type, content, parameters, command_id, limits, progress);
        ExecutionResult::failed(MONITOR_ONLY_REFUSAL.to_string(), Instant::now())
    }
}

async fn run_script_unchecked(
    script_type: &ScriptType,
    content: &str,
    parameters: &HashMap<String, String>,
    command_id: u64,
    limits: &RunLimits,
    progress: ProgressSender,
) -> ExecutionResult {
    let started = Instant::now();

    if !script_type.is_supported_here() {
        return ExecutionResult::failed(
            format!(
                "Script type '{}' is not supported on {}",
                script_type.name(),
                std::env::consts::OS
            ),
            started,
        );
    }

    let script_path = match write_script(script_type, content, &limits.work_dir, command_id) {
        Ok(path) => path,
        Err(e) => {
            return ExecutionResult::failed(
                format!("Could not write the script file: {}", e),
                started,
            )
        }
    };

    let result = execute(script_type, &script_path, parameters, limits, progress, started).await;
    let _ = std::fs::remove_file(&script_path);
    result
}

fn write_script(
    script_type: &ScriptType,
    content: &str,
    work_dir: &Path,
    command_id: u64,
) -> std::io::Result<PathBuf> {
    use std::io::Write;

    std::fs::create_dir_all(work_dir)?;
    let path = work_dir.join(format!(
        "command-{}-{}.{}",
        command_id,
        std::process::id(),
        script_type.file_extension()
    ));

    let mut file = std::fs::OpenOptions::new()
        .write(true)
        .create_new(true)
        .open(&path)?;

    file.write_all(&script_bytes(script_type, content))?;
    file.sync_all()?;
    Ok(path)
}

/// Windows PowerShell 5.1 reads a BOM-less .ps1 as ANSI, so non-ASCII text
/// would be mangled without the UTF-8 BOM; output is forced to UTF-8 too.
fn script_bytes(script_type: &ScriptType, content: &str) -> Vec<u8> {
    match script_type {
        ScriptType::Powershell => {
            let mut bytes = vec![0xEF, 0xBB, 0xBF];
            bytes
                .extend_from_slice(b"[Console]::OutputEncoding = [System.Text.Encoding]::UTF8\r\n");
            bytes.extend_from_slice(content.as_bytes());
            bytes
        }
        _ => content.as_bytes().to_vec(),
    }
}

async fn execute(
    script_type: &ScriptType,
    script_path: &Path,
    parameters: &HashMap<String, String>,
    limits: &RunLimits,
    progress: ProgressSender,
    started: Instant,
) -> ExecutionResult {
    let mut command = interpreter_command(script_type, script_path);
    command
        .envs(parameter_env_vars(parameters))
        .stdin(Stdio::null())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .kill_on_drop(true);

    let mut child = match command.spawn() {
        Ok(child) => child,
        Err(e) => {
            return ExecutionResult::failed(
                format!("Could not start {}: {}", script_type.name(), e),
                started,
            )
        }
    };

    let byte_limit = limits.output_limit.saturating_mul(4);
    let stdout_buffer = Capture::scanning_progress(byte_limit, progress);
    let stderr_buffer = Capture::plain(byte_limit);
    let stdout_task = tokio::spawn(read_capped(child.stdout.take(), stdout_buffer.clone()));
    let stderr_task = tokio::spawn(read_capped(child.stderr.take(), stderr_buffer.clone()));

    let (exit_code, timed_out) = match tokio::time::timeout(limits.timeout, child.wait()).await {
        Ok(Ok(status)) => (status.code().unwrap_or(-1), false),
        Ok(Err(e)) => {
            return ExecutionResult::failed(
                format!("Lost track of the script process: {}", e),
                started,
            )
        }
        Err(_) => {
            kill_process_tree(child.id());
            let _ = child.kill().await;
            (-1, true)
        }
    };

    let stdout = drain(stdout_task, &stdout_buffer, limits.drain_grace).await;
    let stderr = drain(stderr_task, &stderr_buffer, limits.drain_grace).await;

    ExecutionResult {
        exit_code,
        output: combine_output(&stdout, &stderr, limits.output_limit),
        error_message: timed_out.then(|| {
            format!(
                "Command timed out after {} seconds",
                limits.timeout.as_secs()
            )
        }),
        timed_out,
        duration: started.elapsed(),
    }
}

/// Output captured so far, shared with the reader task so a pipe that never
/// closes (a background child still holding it after a timeout) cannot throw
/// away what was already read.
type CapturedOutput = std::sync::Arc<std::sync::Mutex<Capture>>;

/// One pipe's output, capped at `limit` bytes. Stdout also has its progress
/// lines taken out (they count toward nothing).
struct Capture {
    bytes: Vec<u8>,
    limit: usize,
    progress: Option<ProgressLines>,
}

impl Capture {
    fn plain(limit: usize) -> CapturedOutput {
        Self::shared(limit, None)
    }

    fn scanning_progress(limit: usize, progress: ProgressSender) -> CapturedOutput {
        Self::shared(limit, Some(ProgressLines::new(progress)))
    }

    fn shared(limit: usize, progress: Option<ProgressLines>) -> CapturedOutput {
        std::sync::Arc::new(std::sync::Mutex::new(Self {
            bytes: Vec::new(),
            limit,
            progress,
        }))
    }

    fn push(&mut self, chunk: &[u8]) {
        let Some(progress) = self.progress.as_mut() else {
            return self.keep(chunk);
        };

        let mut output = Vec::with_capacity(chunk.len());
        progress.feed(chunk, &mut output);
        self.keep(&output);
    }

    fn keep(&mut self, bytes: &[u8]) {
        let room = self.limit.saturating_sub(self.bytes.len());
        self.bytes.extend_from_slice(&bytes[..bytes.len().min(room)]);
    }

    /// Everything kept, including a last line that had no newline.
    fn take(&mut self) -> Vec<u8> {
        if let Some(mut progress) = self.progress.take() {
            let mut output = Vec::new();
            progress.finish(&mut output);
            self.keep(&output);
        }

        std::mem::take(&mut self.bytes)
    }
}

fn lock(captured: &CapturedOutput) -> std::sync::MutexGuard<'_, Capture> {
    captured.lock().unwrap_or_else(|poisoned| poisoned.into_inner())
}

async fn read_capped<R: AsyncRead + Unpin>(pipe: Option<R>, captured: CapturedOutput) {
    let Some(mut pipe) = pipe else {
        return;
    };

    let mut chunk = [0u8; 8192];
    loop {
        let read = match pipe.read(&mut chunk).await {
            Ok(0) | Err(_) => break,
            Ok(read) => read,
        };

        lock(&captured).push(&chunk[..read]);
    }
}

/// Waits up to `grace` for the pipe to close, then returns whatever was read,
/// stopping the reader if the pipe is still held open.
async fn drain(mut task: tokio::task::JoinHandle<()>, captured: &CapturedOutput, grace: Duration) -> Vec<u8> {
    if tokio::time::timeout(grace, &mut task).await.is_err() {
        task.abort();
    }

    lock(captured).take()
}

#[cfg(windows)]
fn interpreter_command(script_type: &ScriptType, script_path: &Path) -> Command {
    use crate::data_dir_security::system32_tool;

    let system_root = std::env::var("SystemRoot").ok();

    match script_type {
        ScriptType::Cmd => {
            let mut command = Command::new(system32_tool(system_root.as_deref(), "cmd.exe"));
            command.arg("/d").arg("/c").arg(script_path);
            command
        }
        _ => {
            let mut command = Command::new(system32_tool(
                system_root.as_deref(),
                r"WindowsPowerShell\v1.0\powershell.exe",
            ));
            command
                .args([
                    "-NoProfile",
                    "-NonInteractive",
                    "-ExecutionPolicy",
                    "Bypass",
                    "-File",
                ])
                .arg(script_path);
            command
        }
    }
}

#[cfg(not(windows))]
fn interpreter_command(script_type: &ScriptType, script_path: &Path) -> Command {
    let shell = match script_type {
        ScriptType::Bash => "/bin/bash",
        _ => "/bin/sh",
    };

    let mut command = Command::new(shell);
    command.arg(script_path).process_group(0);
    command
}

#[cfg(windows)]
fn kill_process_tree(pid: Option<u32>) {
    use crate::data_dir_security::system32_tool;

    let Some(pid) = pid else {
        return;
    };

    let system_root = std::env::var("SystemRoot").ok();
    let _ = std::process::Command::new(system32_tool(system_root.as_deref(), "taskkill.exe"))
        .args(["/T", "/F", "/PID", &pid.to_string()])
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status();
}

#[cfg(not(windows))]
fn kill_process_tree(pid: Option<u32>) {
    let Some(pid) = pid else {
        return;
    };

    // "--" ends the options so "-<pgid>" is read as a process group, not a
    // malformed signal: macOS accepted it without, Linux silently did not.
    let _ = std::process::Command::new("/bin/kill")
        .args(["-KILL", "--", &format!("-{}", pid)])
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status();
}

#[cfg(test)]
mod tests {
    use super::*;

    fn limits(work_dir: &Path, timeout_secs: u64, output_limit: usize) -> RunLimits {
        RunLimits {
            timeout: Duration::from_secs(timeout_secs),
            output_limit,
            drain_grace: Duration::from_secs(5),
            work_dir: work_dir.to_path_buf(),
        }
    }

    #[test]
    fn parses_script_types_case_insensitively() {
        assert_eq!(ScriptType::parse("PowerShell"), ScriptType::Powershell);
        assert_eq!(ScriptType::parse(" cmd "), ScriptType::Cmd);
        assert_eq!(ScriptType::parse("bash"), ScriptType::Bash);
        assert_eq!(ScriptType::parse("sh"), ScriptType::Sh);
        assert_eq!(
            ScriptType::parse("cobol"),
            ScriptType::Unknown("cobol".to_string())
        );
    }

    #[test]
    fn clamps_timeouts_into_range() {
        assert_eq!(clamp_timeout(1, 10, 7200), Duration::from_secs(10));
        assert_eq!(clamp_timeout(300, 10, 7200), Duration::from_secs(300));
        assert_eq!(clamp_timeout(99_999, 10, 7200), Duration::from_secs(7200));
        assert_eq!(clamp_timeout(50, 10, 5), Duration::from_secs(10));
    }

    #[test]
    fn combines_stdout_and_stderr() {
        assert_eq!(combine_output(b"hello", b"", 100), "hello");
        assert_eq!(combine_output(b"hello", b"  \n", 100), "hello");
        assert_eq!(
            combine_output(b"hello", b"oops", 100),
            format!("hello{}oops", STDERR_SEPARATOR)
        );
    }

    #[test]
    fn truncates_to_the_character_limit_with_a_marker() {
        let output = combine_output("é".repeat(50).as_bytes(), b"", 30);

        assert_eq!(output.chars().count(), 30);
        assert!(output.ends_with(TRUNCATION_MARKER));
    }

    #[test]
    fn decodes_invalid_utf8_lossily() {
        assert_eq!(combine_output(&[0x66, 0xFF, 0x6F], b"", 100), "f\u{FFFD}o");
    }

    #[test]
    fn powershell_scripts_get_a_bom_and_utf8_output() {
        let bytes = script_bytes(&ScriptType::Powershell, "Get-Date");

        assert!(bytes.starts_with(&[0xEF, 0xBB, 0xBF]));
        assert!(String::from_utf8_lossy(&bytes).ends_with("Get-Date"));
        assert_eq!(script_bytes(&ScriptType::Cmd, "dir"), b"dir".to_vec());
    }

    #[tokio::test]
    async fn refuses_unsupported_types_without_writing_anything() {
        let dir = tempfile::tempdir().unwrap();
        let foreign = if cfg!(windows) {
            ScriptType::Bash
        } else {
            ScriptType::Powershell
        };

        let result = run_script_unchecked(
            &foreign,
            "echo hi",
            &HashMap::new(),
            1,
            &limits(dir.path(), 5, 100),
            crate::command_progress::channel().0,
        )
        .await;

        assert_eq!(result.exit_code, -1);
        assert!(result.error_message.unwrap().contains("not supported"));
        assert_eq!(std::fs::read_dir(dir.path()).unwrap().count(), 0);
    }

    #[tokio::test]
    async fn refuses_unknown_types() {
        let dir = tempfile::tempdir().unwrap();

        let result = run_script_unchecked(
            &ScriptType::Unknown("cobol".to_string()),
            "DISPLAY 'HI'",
            &HashMap::new(),
            1,
            &limits(dir.path(), 5, 100),
            crate::command_progress::channel().0,
        )
        .await;

        assert_eq!(result.exit_code, -1);
        assert!(result.error_message.unwrap().contains("'cobol'"));
    }

    #[cfg(unix)]
    #[tokio::test]
    async fn runs_a_shell_script_and_cleans_up() {
        let dir = tempfile::tempdir().unwrap();

        let result = run_script_unchecked(
            &ScriptType::Sh,
            "echo hello\necho problem >&2\nexit 3\n",
            &HashMap::new(),
            7,
            &limits(dir.path(), 10, 1000),
            crate::command_progress::channel().0,
        )
        .await;

        assert_eq!(result.exit_code, 3);
        assert!(!result.timed_out);
        assert!(result.error_message.is_none());
        assert_eq!(
            result.output,
            format!("hello\n{}problem\n", STDERR_SEPARATOR)
        );
        assert_eq!(std::fs::read_dir(dir.path()).unwrap().count(), 0);
    }

    #[cfg(not(windows))]
    #[test]
    fn monitor_only_builds_cannot_run_commands() {
        assert!(!COMMANDS_ENABLED);
    }

    #[cfg(windows)]
    #[test]
    fn windows_builds_run_commands() {
        assert!(COMMANDS_ENABLED);
    }

    #[cfg(not(windows))]
    #[tokio::test]
    async fn run_script_refuses_on_monitor_only_builds_without_writing_anything() {
        let dir = tempfile::tempdir().unwrap();

        for script_type in [ScriptType::Sh, ScriptType::Bash, ScriptType::Powershell] {
            let result = run_script(
                &script_type,
                "touch should-not-exist",
                &HashMap::new(),
                1,
                &limits(dir.path(), 5, 100),
                crate::command_progress::channel().0,
            )
            .await;

            assert_eq!(result.exit_code, -1);
            assert_eq!(result.error_message.as_deref(), Some(MONITOR_ONLY_REFUSAL));
            assert!(!result.timed_out);
        }
        assert_eq!(std::fs::read_dir(dir.path()).unwrap().count(), 0);
    }

    #[test]
    fn prefixes_parameter_names_and_drops_malformed_ones() {
        let parameters = HashMap::from([
            ("PackageId".to_string(), "Mozilla.Firefox".to_string()),
            ("bad=name".to_string(), "x".to_string()),
            ("1st".to_string(), "x".to_string()),
            ("".to_string(), "x".to_string()),
        ]);

        assert_eq!(
            parameter_env_vars(&parameters),
            vec![("RMM_PackageId".to_string(), "Mozilla.Firefox".to_string())]
        );
    }

    #[cfg(unix)]
    #[tokio::test]
    async fn exposes_parameters_to_a_bash_script_as_env_vars() {
        let dir = tempfile::tempdir().unwrap();
        let parameters = HashMap::from([(
            "PackageId".to_string(),
            "it's \"quoted\" $(whoami); rm -rf /".to_string(),
        )]);

        let result = run_script_unchecked(
            &ScriptType::Bash,
            "printf '%s' \"$RMM_PackageId\"\n",
            &parameters,
            11,
            &limits(dir.path(), 10, 1000),
            crate::command_progress::channel().0,
        )
        .await;

        assert_eq!(result.exit_code, 0, "output: {}", result.output);
        assert_eq!(result.output, "it's \"quoted\" $(whoami); rm -rf /");
    }

    #[cfg(windows)]
    #[tokio::test]
    async fn exposes_parameters_to_a_powershell_script_as_env_vars() {
        let dir = tempfile::tempdir().unwrap();
        let parameters = HashMap::from([("PackageId".to_string(), "Mozilla.Firefox".to_string())]);

        let result = run_script_unchecked(
            &ScriptType::Powershell,
            "Write-Output $env:RMM_PackageId",
            &parameters,
            12,
            &limits(dir.path(), 60, 1000),
            crate::command_progress::channel().0,
        )
        .await;

        assert_eq!(result.exit_code, 0, "output: {}", result.output);
        assert!(result.output.contains("Mozilla.Firefox"));
    }

    #[tokio::test]
    async fn keeps_output_already_read_when_the_pipe_never_closes() {
        use tokio::io::AsyncWriteExt;

        let (mut writer, reader) = tokio::io::duplex(64);
        writer.write_all(b"before").await.unwrap();

        let captured = Capture::plain(1000);
        let task = tokio::spawn(read_capped(Some(reader), captured.clone()));
        let output = drain(task, &captured, Duration::from_millis(200)).await;

        assert_eq!(output, b"before");
        drop(writer);
    }

    #[test]
    fn stdout_capture_drops_progress_lines_before_the_cap() {
        let (sender, receiver) = crate::command_progress::channel();
        let captured = Capture::scanning_progress(6, sender);

        lock(&captured).push(b"PROGRESS: {\"n\":1}\nabc\nPROGRESS: {\"n\":2}\ndefgh");
        lock(&captured).push(b"\nPROGRESS: {\"n\":3}");

        assert_eq!(lock(&captured).take(), b"abc\nde");
        assert_eq!(*receiver.borrow(), Some(serde_json::json!({"n": 3})));
    }

    #[tokio::test]
    async fn keeps_a_held_partial_line_when_the_pipe_never_closes() {
        use tokio::io::AsyncWriteExt;

        let (mut writer, reader) = tokio::io::duplex(64);
        writer.write_all(b"done\nPROGRESS: not json").await.unwrap();

        let captured = Capture::scanning_progress(1000, crate::command_progress::channel().0);
        let task = tokio::spawn(read_capped(Some(reader), captured.clone()));
        let output = drain(task, &captured, Duration::from_millis(200)).await;

        assert_eq!(output, b"done\nPROGRESS: not json");
        drop(writer);
    }

    #[cfg(unix)]
    #[tokio::test]
    async fn reports_progress_lines_and_leaves_them_out_of_the_output() {
        let dir = tempfile::tempdir().unwrap();
        let (sender, receiver) = crate::command_progress::channel();

        let result = run_script_unchecked(
            &ScriptType::Sh,
            "echo start\necho 'PROGRESS: {\"percent\":50}'\necho 'PROGRESS: {\"percent\":100}'\necho end\necho 'PROGRESS: {\"x\":1}' >&2\n",
            &HashMap::new(),
            13,
            &limits(dir.path(), 10, 1000),
            sender,
        )
        .await;

        assert_eq!(result.exit_code, 0, "output: {}", result.output);
        assert_eq!(
            result.output,
            format!("start\nend\n{}PROGRESS: {{\"x\":1}}\n", STDERR_SEPARATOR)
        );
        assert_eq!(*receiver.borrow(), Some(serde_json::json!({"percent": 100})));
    }

    #[cfg(unix)]
    #[tokio::test]
    async fn kills_scripts_and_their_children_on_timeout() {
        let dir = tempfile::tempdir().unwrap();
        let started = Instant::now();

        let result = run_script_unchecked(
            &ScriptType::Sh,
            "echo before\nsleep 30 &\nsleep 30\n",
            &HashMap::new(),
            8,
            &limits(dir.path(), 1, 1000),
            crate::command_progress::channel().0,
        )
        .await;

        assert!(result.timed_out);
        assert_eq!(result.exit_code, -1);
        assert!(result.output.contains("before"));
        assert_eq!(
            result.error_message.as_deref(),
            Some("Command timed out after 1 seconds")
        );
        assert!(started.elapsed() < Duration::from_secs(10));
    }

    #[cfg(windows)]
    #[tokio::test]
    async fn runs_a_powershell_script() {
        let dir = tempfile::tempdir().unwrap();

        let result = run_script_unchecked(
            &ScriptType::Powershell,
            "Write-Output 'hello from powershell'\nexit 4",
            &HashMap::new(),
            9,
            &limits(dir.path(), 60, 1000),
            crate::command_progress::channel().0,
        )
        .await;

        assert_eq!(result.exit_code, 4, "output: {}", result.output);
        assert!(result.output.contains("hello from powershell"));
        assert!(!result.timed_out);
    }

    #[cfg(windows)]
    #[tokio::test]
    async fn runs_a_cmd_script() {
        let dir = tempfile::tempdir().unwrap();

        let result = run_script_unchecked(
            &ScriptType::Cmd,
            "@echo off\r\necho hello from cmd\r\nexit /b 0\r\n",
            &HashMap::new(),
            10,
            &limits(dir.path(), 60, 1000),
            crate::command_progress::channel().0,
        )
        .await;

        assert_eq!(result.exit_code, 0, "output: {}", result.output);
        assert!(result.output.contains("hello from cmd"));
    }
}
