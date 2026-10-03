//! Keeps the machine awake while a freshly started agent checks in.
//!
//! After a self-update the old agent (and its keep-awake) is gone, and a
//! dark-woken Modern Standby PC drops back into throttled standby before the
//! new agent has told the server anything. The startup grace holds a
//! keep-awake until the agent has announced its boot, posted metrics once and
//! drained the command queue once, or until a maximum time, whichever comes
//! first.

use crate::keep_awake::KeepAwake;
use std::time::Duration;
use tokio::sync::mpsc;
use tokio::time::Instant;
use tokio_util::sync::CancellationToken;
use tracing::info;

/// Something the new agent has done that the server needs to see.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Milestone {
    /// The boot powering_on notice went out (whatever the server said).
    BootAnnounced,
    /// Metrics were accepted by the server.
    MetricsPosted,
    /// The command queue was drained to empty at least once.
    CommandsDrained,
}

/// Pure release condition for the startup keep-awake.
#[derive(Debug, Clone)]
pub struct StartupGrace {
    deadline: Instant,
    boot_announced: bool,
    metrics_posted: bool,
    commands_drained: bool,
}

impl StartupGrace {
    pub fn new(started: Instant, max: Duration) -> Self {
        Self {
            deadline: started + max,
            boot_announced: false,
            metrics_posted: false,
            commands_drained: false,
        }
    }

    pub fn deadline(&self) -> Instant {
        self.deadline
    }

    pub fn record(&mut self, milestone: Milestone) {
        match milestone {
            Milestone::BootAnnounced => self.boot_announced = true,
            Milestone::MetricsPosted => self.metrics_posted = true,
            Milestone::CommandsDrained => self.commands_drained = true,
        }
    }

    /// Every milestone reached.
    pub fn is_settled(&self) -> bool {
        self.boot_announced && self.metrics_posted && self.commands_drained
    }

    /// Release the keep-awake: settled, or out of time.
    pub fn should_release(&self, now: Instant) -> bool {
        self.is_settled() || now >= self.deadline
    }
}

/// Where loops report milestones. The default does nothing (no grace held).
#[derive(Debug, Clone, Default)]
pub struct StartupProgress {
    milestones: Option<mpsc::UnboundedSender<Milestone>>,
}

impl StartupProgress {
    pub fn mark(&self, milestone: Milestone) {
        if let Some(milestones) = &self.milestones {
            let _ = milestones.send(milestone);
        }
    }
}

/// Take the startup keep-awake and release it from a background task once
/// `StartupGrace` says so (or on cancellation).
pub fn hold_while_starting(
    max: Duration,
    cancellation_token: CancellationToken,
) -> StartupProgress {
    hold_with(max, cancellation_token, || {
        KeepAwake::acquire("BenJH RMM is starting")
    })
}

fn hold_with<G, F>(
    max: Duration,
    cancellation_token: CancellationToken,
    acquire: F,
) -> StartupProgress
where
    G: Send + 'static,
    F: FnOnce() -> G + Send + 'static,
{
    let (milestones, mut received) = mpsc::unbounded_channel();

    tokio::spawn(async move {
        let awake = acquire();
        let mut grace = StartupGrace::new(Instant::now(), max);

        loop {
            tokio::select! {
                _ = tokio::time::sleep_until(grace.deadline()) => {
                    info!("Startup keep-awake released after {}s", max.as_secs());
                    break;
                }
                _ = cancellation_token.cancelled() => break,
                milestone = received.recv() => {
                    let Some(milestone) = milestone else { break };
                    grace.record(milestone);
                    if grace.should_release(Instant::now()) {
                        info!("Agent checked in; startup keep-awake released");
                        break;
                    }
                }
            }
        }

        drop(awake);
    });

    StartupProgress {
        milestones: Some(milestones),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::atomic::{AtomicBool, Ordering};
    use std::sync::Arc;

    const MAX: Duration = Duration::from_secs(120);

    #[test]
    fn holds_until_every_milestone_is_reached() {
        let start = Instant::now();
        let mut grace = StartupGrace::new(start, MAX);

        assert!(!grace.should_release(start));
        grace.record(Milestone::BootAnnounced);
        assert!(!grace.should_release(start));
        grace.record(Milestone::MetricsPosted);
        assert!(!grace.should_release(start + Duration::from_secs(5)));
        grace.record(Milestone::CommandsDrained);
        assert!(grace.should_release(start + Duration::from_secs(5)));
    }

    #[test]
    fn order_and_repeats_do_not_matter() {
        let start = Instant::now();
        let mut grace = StartupGrace::new(start, MAX);

        grace.record(Milestone::CommandsDrained);
        grace.record(Milestone::CommandsDrained);
        grace.record(Milestone::MetricsPosted);
        assert!(!grace.is_settled());
        grace.record(Milestone::BootAnnounced);
        assert!(grace.is_settled());
    }

    #[test]
    fn releases_at_the_maximum_regardless() {
        let start = Instant::now();
        let mut grace = StartupGrace::new(start, MAX);
        grace.record(Milestone::BootAnnounced);

        assert!(!grace.should_release(start + MAX - Duration::from_secs(1)));
        assert!(grace.should_release(start + MAX));
        assert_eq!(grace.deadline(), start + MAX);
    }

    #[test]
    fn default_progress_ignores_milestones() {
        StartupProgress::default().mark(Milestone::MetricsPosted);
    }

    /// Records when the fake keep-awake is released.
    struct FakeAwake(Arc<AtomicBool>);

    impl Drop for FakeAwake {
        fn drop(&mut self) {
            self.0.store(true, Ordering::SeqCst);
        }
    }

    fn fake_hold(token: CancellationToken) -> (StartupProgress, Arc<AtomicBool>) {
        let released = Arc::new(AtomicBool::new(false));
        let flag = released.clone();
        let progress = hold_with(MAX, token, move || FakeAwake(flag));
        (progress, released)
    }

    async fn settle() {
        for _ in 0..10 {
            tokio::task::yield_now().await;
        }
    }

    #[tokio::test(start_paused = true)]
    async fn releases_once_checked_in() {
        let (progress, released) = fake_hold(CancellationToken::new());

        progress.mark(Milestone::BootAnnounced);
        progress.mark(Milestone::MetricsPosted);
        settle().await;
        assert!(!released.load(Ordering::SeqCst));

        progress.mark(Milestone::CommandsDrained);
        settle().await;
        assert!(released.load(Ordering::SeqCst));
    }

    #[tokio::test(start_paused = true)]
    async fn releases_at_the_maximum_if_the_server_never_answers() {
        let (progress, released) = fake_hold(CancellationToken::new());
        progress.mark(Milestone::BootAnnounced);

        tokio::time::sleep(MAX - Duration::from_secs(1)).await;
        settle().await;
        assert!(!released.load(Ordering::SeqCst));

        tokio::time::sleep(Duration::from_secs(1)).await;
        settle().await;
        assert!(released.load(Ordering::SeqCst));
        drop(progress);
    }

    #[tokio::test(start_paused = true)]
    async fn releases_on_cancellation() {
        let token = CancellationToken::new();
        let (_progress, released) = fake_hold(token.clone());

        settle().await;
        assert!(!released.load(Ordering::SeqCst));
        token.cancel();
        settle().await;
        assert!(released.load(Ordering::SeqCst));
    }
}
