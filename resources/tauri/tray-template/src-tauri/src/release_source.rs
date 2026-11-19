//! Where the agent looks for new releases.
//!
//! The server names the latest-release API URL in every heartbeat response
//! (`agent_releases_url`). Until one arrives, or when an older server never
//! sends one, the compiled `DEFAULT_RELEASES_URL` is used. Nothing is stored
//! on the box: the server owns the setting.

use std::sync::Arc;
use std::time::Duration;
use tokio::sync::watch;
use tracing::{info, warn};

use crate::config::DEFAULT_RELEASES_URL;

/// A releases URL the agent will follow: https only, surrounding whitespace
/// trimmed. Anything else is ignored so a bad setting falls back to the default.
pub fn accepted_releases_url(url: &str) -> Option<String> {
    let url = url.trim();
    let host = url.strip_prefix("https://")?;
    (!host.is_empty()).then(|| url.to_string())
}

/// Shared between the heartbeat loop (writer) and the updater (reader).
#[derive(Clone)]
pub struct ReleaseSource {
    server_url: Arc<watch::Sender<Option<String>>>,
}

impl Default for ReleaseSource {
    fn default() -> Self {
        Self::new()
    }
}

impl ReleaseSource {
    pub fn new() -> Self {
        Self {
            server_url: Arc::new(watch::Sender::new(None)),
        }
    }

    /// Adopt the URL a heartbeat response named, if it is usable.
    pub fn offer(&self, url: Option<&str>) {
        let Some(url) = url else {
            return;
        };
        let Some(accepted) = accepted_releases_url(url) else {
            warn!("Ignoring releases URL from server (not https): {}", url);
            return;
        };

        self.server_url.send_if_modified(|current| {
            if current.as_deref() == Some(accepted.as_str()) {
                return false;
            }
            info!("Releases URL set by server: {}", accepted);
            *current = Some(accepted);
            true
        });
    }

    /// The server-named URL, else the compiled default.
    pub fn current(&self) -> String {
        self.server_url
            .borrow()
            .clone()
            .unwrap_or_else(|| DEFAULT_RELEASES_URL.to_string())
    }

    /// Wait up to `max` for the server to name a URL, so the first update
    /// check after start-up asks the right place. Old servers never name one;
    /// the wait then just runs out.
    pub async fn wait_for_server(&self, max: Duration) {
        let mut receiver = self.server_url.subscribe();
        let _ = tokio::time::timeout(max, receiver.wait_for(Option::is_some)).await;
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    const FORGEJO: &str = "https://git.example.test/api/v1/repos/ben/rmm/releases/latest";

    #[test]
    fn falls_back_to_the_compiled_default() {
        assert_eq!(ReleaseSource::new().current(), DEFAULT_RELEASES_URL);
    }

    #[test]
    fn prefers_the_url_the_server_names() {
        let source = ReleaseSource::new();
        source.offer(Some(FORGEJO));
        assert_eq!(source.current(), FORGEJO);
    }

    #[test]
    fn keeps_the_last_server_url_when_a_heartbeat_names_none() {
        let source = ReleaseSource::new();
        source.offer(Some(FORGEJO));
        source.offer(None);
        assert_eq!(source.current(), FORGEJO);
    }

    #[test]
    fn ignores_urls_that_are_not_https() {
        let source = ReleaseSource::new();
        source.offer(Some("http://git.example.test/releases/latest"));
        source.offer(Some("  "));
        source.offer(Some("https://"));
        assert_eq!(source.current(), DEFAULT_RELEASES_URL);
    }

    #[test]
    fn trims_whitespace_around_the_url() {
        assert_eq!(accepted_releases_url(&format!(" {FORGEJO}\n")).as_deref(), Some(FORGEJO));
    }

    #[tokio::test(start_paused = true)]
    async fn waiting_ends_when_the_server_names_a_url() {
        let source = ReleaseSource::new();
        let writer = source.clone();
        tokio::spawn(async move { writer.offer(Some(FORGEJO)) });
        source.wait_for_server(Duration::from_secs(60)).await;
        assert_eq!(source.current(), FORGEJO);
    }

    #[tokio::test(start_paused = true)]
    async fn waiting_gives_up_on_an_old_server() {
        let source = ReleaseSource::new();
        source.wait_for_server(Duration::from_secs(60)).await;
        assert_eq!(source.current(), DEFAULT_RELEASES_URL);
    }
}
