//! Live progress from a running command.
//!
//! A script reports progress by printing `PROGRESS: {json}` on its own stdout
//! line. The stdout reader hands every chunk to `ProgressLines`, which keeps
//! the latest progress object in a watch channel and passes every other byte
//! through untouched. `post_progress` sends the latest object to the server at
//! most once per interval, on its own future, so a slow server never holds up
//! reading the child's output.

// Progress only flows on Windows builds (and tests).
#![cfg_attr(not(any(windows, test)), allow(dead_code))]

use crate::config::{PROGRESS_LINE_PREFIX, PROGRESS_MAX_JSON_BYTES};
use serde_json::Value;
use std::future::Future;
use std::time::Duration;
use tokio::sync::watch;
use tracing::warn;

pub type ProgressSender = watch::Sender<Option<Value>>;
pub type ProgressReceiver = watch::Receiver<Option<Value>>;

pub fn channel() -> (ProgressSender, ProgressReceiver) {
    watch::channel(None)
}

/// Splits stdout into lines, publishing progress lines and passing the rest on.
///
/// Only the start of a line that could still be a progress line is held back;
/// anything else streams straight through, so a huge line without a newline
/// is never buffered here.
pub struct ProgressLines {
    sender: ProgressSender,
    held: Vec<u8>,
    passing: bool,
}

impl ProgressLines {
    pub fn new(sender: ProgressSender) -> Self {
        Self {
            sender,
            held: Vec::new(),
            passing: false,
        }
    }

    /// Feed the next chunk of stdout; bytes that are not progress go to `out`.
    pub fn feed(&mut self, mut chunk: &[u8], out: &mut Vec<u8>) {
        while !chunk.is_empty() {
            let (piece, rest) = match chunk.iter().position(|&byte| byte == b'\n') {
                Some(newline) => chunk.split_at(newline + 1),
                None => (chunk, &[][..]),
            };

            self.take(piece, piece.ends_with(b"\n"), out);
            chunk = rest;
        }
    }

    /// The stream ended: settle a last line that had no newline.
    pub fn finish(&mut self, out: &mut Vec<u8>) {
        if !self.passing {
            self.settle(out);
        }
        self.passing = false;
    }

    fn take(&mut self, piece: &[u8], ends_line: bool, out: &mut Vec<u8>) {
        if self.passing {
            out.extend_from_slice(piece);
        } else {
            self.held.extend_from_slice(piece);

            if ends_line {
                self.settle(out);
            } else if !may_be_progress(&self.held) {
                out.append(&mut self.held);
                self.passing = true;
            }
        }

        if ends_line {
            self.passing = false;
        }
    }

    fn settle(&mut self, out: &mut Vec<u8>) {
        match parse_progress(&self.held) {
            Some(progress) => {
                self.sender.send_replace(Some(progress));
                self.held.clear();
            }
            None => out.append(&mut self.held),
        }
    }
}

/// Whether an unfinished line could still turn out to be a progress line.
fn may_be_progress(held: &[u8]) -> bool {
    let prefix = PROGRESS_LINE_PREFIX.as_bytes();
    let compared = held.len().min(prefix.len());

    held[..compared] == prefix[..compared]
        && held.len() <= prefix.len() + PROGRESS_MAX_JSON_BYTES + 1
}

/// The JSON object on a `PROGRESS: ` line, newline and CR stripped.
pub fn parse_progress(line: &[u8]) -> Option<Value> {
    let line = line.strip_suffix(b"\n").unwrap_or(line);
    let line = line.strip_suffix(b"\r").unwrap_or(line);
    let json = line.strip_prefix(PROGRESS_LINE_PREFIX.as_bytes())?;

    if json.len() > PROGRESS_MAX_JSON_BYTES {
        return None;
    }

    serde_json::from_slice::<Value>(json)
        .ok()
        .filter(Value::is_object)
}

/// What the server made of one progress post.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Posted {
    Accepted,
    /// The command already finished or was cancelled: stop posting.
    Gone,
    Failed(String),
}

/// Where progress goes, so the throttle can be tested without a server.
pub trait ProgressSink {
    async fn post(&mut self, progress: &Value) -> Posted;
}

/// Post the latest progress at most once per `interval`, skipping repeats,
/// until the sender is dropped or the server says the command is gone. Never
/// retries: a failed post just waits for the next change.
pub async fn post_progress<S: ProgressSink>(
    mut updates: ProgressReceiver,
    interval: Duration,
    mut sink: S,
    command_id: u64,
) {
    let mut last_posted: Option<Value> = None;
    let mut warned = false;

    while updates.changed().await.is_ok() {
        let Some(progress) = updates.borrow_and_update().clone() else {
            continue;
        };
        if last_posted.as_ref() == Some(&progress) {
            continue;
        }

        match sink.post(&progress).await {
            Posted::Gone => return,
            Posted::Failed(reason) if !warned => {
                warn!(
                    "Could not post progress of command {}: {}",
                    command_id, reason
                );
                warned = true;
            }
            _ => {}
        }

        last_posted = Some(progress);
        tokio::time::sleep(interval).await;
    }
}

/// Run `work` to completion while `alongside` runs next to it; `alongside` is
/// dropped as soon as `work` finishes, mid-request or not.
pub async fn while_running<T>(
    work: impl Future<Output = T>,
    alongside: impl Future<Output = ()>,
) -> T {
    tokio::pin!(work);

    tokio::select! {
        biased;
        result = &mut work => return result,
        () = alongside => {}
    }

    work.await
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;
    use std::sync::{Arc, Mutex};
    use tokio::time::Instant;

    fn scan(chunks: &[&[u8]]) -> (Vec<u8>, Option<Value>) {
        let (sender, receiver) = channel();
        let mut lines = ProgressLines::new(sender);
        let mut out = Vec::new();

        for chunk in chunks {
            lines.feed(chunk, &mut out);
        }
        lines.finish(&mut out);

        let latest = receiver.borrow().clone();
        (out, latest)
    }

    #[test]
    fn strips_progress_lines_and_keeps_the_rest() {
        let (out, latest) = scan(&[b"one\nPROGRESS: {\"step\":1}\ntwo\n"]);

        assert_eq!(out, b"one\ntwo\n");
        assert_eq!(latest, Some(json!({"step": 1})));
    }

    #[test]
    fn joins_lines_split_across_chunks() {
        let text = b"start\nPROGRESS: {\"percent\":42,\"stage\":\"copy\"}\nend\n";
        let chunks: Vec<&[u8]> = text.chunks(1).collect();

        let (out, latest) = scan(&chunks);

        assert_eq!(out, b"start\nend\n");
        assert_eq!(latest, Some(json!({"percent": 42, "stage": "copy"})));
    }

    #[test]
    fn handles_crlf_line_endings() {
        let (out, latest) = scan(&[b"a\r\nPROGRESS: {\"n\":1}\r", b"\nb\r\n"]);

        assert_eq!(out, b"a\r\nb\r\n");
        assert_eq!(latest, Some(json!({"n": 1})));
    }

    #[test]
    fn keeps_only_the_latest_progress() {
        let (out, latest) = scan(&[
            b"PROGRESS: {\"n\":1}\nPROGRESS: {\"n\":2}\n",
            b"PROGRESS: {\"n\":3}\n",
        ]);

        assert!(out.is_empty());
        assert_eq!(latest, Some(json!({"n": 3})));
    }

    #[test]
    fn reads_a_last_progress_line_without_a_newline() {
        let (out, latest) = scan(&[b"done\nPROGRESS: {\"n\":9}"]);

        assert_eq!(out, b"done\n");
        assert_eq!(latest, Some(json!({"n": 9})));
    }

    #[test]
    fn leaves_lines_that_are_not_progress_objects_in_the_output() {
        let oversize = format!(
            "PROGRESS: {{\"x\":\"{}\"}}\n",
            "a".repeat(PROGRESS_MAX_JSON_BYTES)
        );
        let text = format!(
            "PROGRESS: not json\nPROGRESS: [1,2]\nPROGRESS: 5\n  PROGRESS: {{}}\nPROGRESS:{{}}\nprogress: {{}}\n{}",
            oversize
        );

        let (out, latest) = scan(&[text.as_bytes()]);

        assert_eq!(out, text.as_bytes());
        assert_eq!(latest, None);
    }

    #[test]
    fn accepts_a_json_object_at_the_size_limit() {
        let json = format!("{{\"x\":\"{}\"}}", "a".repeat(PROGRESS_MAX_JSON_BYTES - 8));
        assert_eq!(json.len(), PROGRESS_MAX_JSON_BYTES);

        let (out, latest) = scan(&[format!("PROGRESS: {}\r\n", json).as_bytes()]);

        assert!(out.is_empty());
        assert!(latest.is_some());
    }

    #[test]
    fn streams_long_ordinary_lines_without_holding_them() {
        let (sender, _receiver) = channel();
        let mut lines = ProgressLines::new(sender);
        let mut out = Vec::new();

        lines.feed(&vec![b'x'; 20_000], &mut out);
        assert_eq!(out.len(), 20_000);

        lines.feed(b"PRO", &mut out);
        assert_eq!(out.len(), 20_003);
    }

    #[test]
    fn passes_output_without_progress_through_unchanged() {
        let text: &[u8] = b"line 1\r\nline 2\n\nPROGRESS\n\xFF tail";

        let (out, latest) = scan(&[&text[..7], &text[7..]]);

        assert_eq!(out, text);
        assert_eq!(latest, None);
    }

    #[derive(Clone)]
    struct FakeSink {
        posts: Arc<Mutex<Vec<(Value, Duration)>>>,
        started: Instant,
        reply: Posted,
    }

    impl FakeSink {
        fn new(reply: Posted) -> Self {
            Self {
                posts: Arc::default(),
                started: Instant::now(),
                reply,
            }
        }

        fn posts(&self) -> Vec<(Value, Duration)> {
            self.posts.lock().unwrap().clone()
        }
    }

    impl ProgressSink for FakeSink {
        async fn post(&mut self, progress: &Value) -> Posted {
            self.posts
                .lock()
                .unwrap()
                .push((progress.clone(), self.started.elapsed()));
            self.reply.clone()
        }
    }

    #[tokio::test(start_paused = true)]
    async fn posts_the_latest_progress_at_most_once_per_interval() {
        let (sender, updates) = channel();
        let sink = FakeSink::new(Posted::Accepted);
        let task = tokio::spawn(post_progress(
            updates,
            Duration::from_secs(5),
            sink.clone(),
            1,
        ));

        sender.send_replace(Some(json!({"n": 1})));
        tokio::time::sleep(Duration::from_secs(1)).await;
        sender.send_replace(Some(json!({"n": 2})));
        sender.send_replace(Some(json!({"n": 3})));
        tokio::time::sleep(Duration::from_secs(10)).await;
        drop(sender);
        task.await.unwrap();

        assert_eq!(
            sink.posts(),
            vec![
                (json!({"n": 1}), Duration::ZERO),
                (json!({"n": 3}), Duration::from_secs(5))
            ]
        );
    }

    #[tokio::test(start_paused = true)]
    async fn does_not_repost_unchanged_progress() {
        let (sender, updates) = channel();
        let sink = FakeSink::new(Posted::Accepted);
        let task = tokio::spawn(post_progress(
            updates,
            Duration::from_secs(5),
            sink.clone(),
            1,
        ));

        sender.send_replace(Some(json!({"n": 1})));
        tokio::time::sleep(Duration::from_secs(6)).await;
        sender.send_replace(Some(json!({"n": 1})));
        tokio::time::sleep(Duration::from_secs(6)).await;
        drop(sender);
        task.await.unwrap();

        assert_eq!(sink.posts().len(), 1);
    }

    #[tokio::test(start_paused = true)]
    async fn posts_nothing_without_progress() {
        let (sender, updates) = channel();
        let sink = FakeSink::new(Posted::Accepted);
        let task = tokio::spawn(post_progress(
            updates,
            Duration::from_secs(5),
            sink.clone(),
            1,
        ));

        tokio::time::sleep(Duration::from_secs(30)).await;
        drop(sender);
        task.await.unwrap();

        assert!(sink.posts().is_empty());
    }

    #[tokio::test(start_paused = true)]
    async fn stops_posting_once_the_command_is_gone() {
        let (sender, updates) = channel();
        let sink = FakeSink::new(Posted::Gone);
        let task = tokio::spawn(post_progress(
            updates,
            Duration::from_secs(5),
            sink.clone(),
            1,
        ));

        sender.send_replace(Some(json!({"n": 1})));
        task.await.unwrap();
        sender.send_replace(Some(json!({"n": 2})));

        assert_eq!(sink.posts().len(), 1);
    }

    #[tokio::test(start_paused = true)]
    async fn keeps_posting_new_progress_after_a_failure() {
        let (sender, updates) = channel();
        let sink = FakeSink::new(Posted::Failed("503".to_string()));
        let task = tokio::spawn(post_progress(
            updates,
            Duration::from_secs(5),
            sink.clone(),
            1,
        ));

        sender.send_replace(Some(json!({"n": 1})));
        tokio::time::sleep(Duration::from_secs(6)).await;
        sender.send_replace(Some(json!({"n": 2})));
        tokio::time::sleep(Duration::from_secs(6)).await;
        drop(sender);
        task.await.unwrap();

        assert_eq!(
            sink.posts()
                .into_iter()
                .map(|(progress, _)| progress)
                .collect::<Vec<_>>(),
            vec![json!({"n": 1}), json!({"n": 2})]
        );
    }

    #[tokio::test]
    async fn while_running_returns_the_work_and_drops_what_runs_alongside() {
        let result = while_running(async { 7 }, std::future::pending()).await;
        assert_eq!(result, 7);

        let result = while_running(
            async {
                tokio::task::yield_now().await;
                8
            },
            async {},
        )
        .await;
        assert_eq!(result, 8);
    }
}
