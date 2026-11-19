//! Who an ad-hoc command runs as: SYSTEM (the default), the user logged in at
//! the console, or that user with their elevated (admin) token.
//!
//! The decisions live here as plain functions over a `ConsoleSessions` trait,
//! so the "nobody logged in" and "not an admin" paths are tested without
//! Windows. The real token calls are in `user_process`.

// Only Windows builds (and tests) run commands.
#![cfg_attr(not(any(windows, test)), allow(dead_code))]

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum RunAs {
    System,
    User,
    UserElevated,
}

impl RunAs {
    /// Servers older than run-as leave the field out, which means SYSTEM. An
    /// unknown value is refused rather than quietly run as SYSTEM.
    pub fn parse(value: Option<&str>) -> Result<Self, String> {
        match value.map(|value| value.trim().to_ascii_lowercase()).as_deref() {
            None | Some("") | Some("system") => Ok(RunAs::System),
            Some("user") => Ok(RunAs::User),
            Some("user_elevated") => Ok(RunAs::UserElevated),
            Some(other) => Err(format!(
                "This agent does not know how to run a command as '{}'. Update the agent.",
                other
            )),
        }
    }

    pub fn name(&self) -> &'static str {
        match self {
            RunAs::System => "system",
            RunAs::User => "user",
            RunAs::UserElevated => "user_elevated",
        }
    }
}

/// How much admin a user's token carries.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Elevation {
    /// Already elevated: UAC is off for this admin, or it is the built-in Administrator.
    Full,
    /// A filtered admin token with an elevated token linked to it.
    Limited,
    /// A standard user: there is no admin token to be had.
    Standard,
}

impl Elevation {
    /// From `TokenElevationType` (1 default, 2 full, 3 limited) and `TokenElevation`.
    pub fn from_token_info(elevation_type: u32, is_elevated: bool) -> Self {
        match (elevation_type, is_elevated) {
            (2, _) => Elevation::Full,
            (3, _) => Elevation::Limited,
            (_, true) => Elevation::Full,
            (_, false) => Elevation::Standard,
        }
    }
}

/// The console session's user, as the run-as decision needs it.
pub trait ConsoleSessions {
    type Token;

    /// The primary token of the user logged in at the console, `None` when
    /// nobody is (the lock-free login screen, or no console session at all).
    fn active_user_token(&self) -> Result<Option<Self::Token>, String>;

    fn elevation(&self, token: &Self::Token) -> Result<Elevation, String>;

    /// The elevated token linked to a limited admin token, as a primary token.
    fn linked_token(&self, token: &Self::Token) -> Result<Self::Token, String>;
}

pub fn nobody_logged_in_message(hostname: &str) -> String {
    format!("Nobody is logged in on {}", hostname)
}

pub fn not_an_admin_message(hostname: &str) -> String {
    format!(
        "The user logged in on {} is not an administrator, so the command cannot run elevated",
        hostname
    )
}

/// The token to start the command with: `None` for SYSTEM (run it the usual
/// way), the console user's token otherwise. Never falls back to another user.
pub fn token_for<S: ConsoleSessions>(
    sessions: &S,
    run_as: RunAs,
    hostname: &str,
) -> Result<Option<S::Token>, String> {
    if run_as == RunAs::System {
        return Ok(None);
    }

    let token = sessions
        .active_user_token()?
        .ok_or_else(|| nobody_logged_in_message(hostname))?;

    if run_as == RunAs::User {
        return Ok(Some(token));
    }

    match sessions.elevation(&token)? {
        Elevation::Full => Ok(Some(token)),
        Elevation::Limited => sessions.linked_token(&token).map(Some),
        Elevation::Standard => Err(not_an_admin_message(hostname)),
    }
}

/// The machine name for messages, from the environment Windows sets.
#[cfg(windows)]
pub fn hostname() -> String {
    std::env::var("COMPUTERNAME")
        .ok()
        .filter(|name| !name.trim().is_empty())
        .unwrap_or_else(|| "this PC".to_string())
}

#[cfg(test)]
mod tests {
    use super::*;

    struct FakeSessions {
        user: Option<&'static str>,
        elevation: Elevation,
    }

    impl ConsoleSessions for FakeSessions {
        type Token = String;

        fn active_user_token(&self) -> Result<Option<String>, String> {
            Ok(self.user.map(str::to_string))
        }

        fn elevation(&self, _token: &String) -> Result<Elevation, String> {
            Ok(self.elevation)
        }

        fn linked_token(&self, token: &String) -> Result<String, String> {
            Ok(format!("{} (elevated)", token))
        }
    }

    fn logged_in(elevation: Elevation) -> FakeSessions {
        FakeSessions {
            user: Some("ben"),
            elevation,
        }
    }

    #[test]
    fn parses_run_as_and_defaults_to_system() {
        assert_eq!(RunAs::parse(None), Ok(RunAs::System));
        assert_eq!(RunAs::parse(Some("")), Ok(RunAs::System));
        assert_eq!(RunAs::parse(Some("system")), Ok(RunAs::System));
        assert_eq!(RunAs::parse(Some(" User ")), Ok(RunAs::User));
        assert_eq!(RunAs::parse(Some("user_elevated")), Ok(RunAs::UserElevated));
    }

    #[test]
    fn refuses_an_unknown_run_as_instead_of_running_as_system() {
        let error = RunAs::parse(Some("administrator")).unwrap_err();

        assert!(error.contains("'administrator'"));
    }

    #[test]
    fn names_round_trip() {
        for run_as in [RunAs::System, RunAs::User, RunAs::UserElevated] {
            assert_eq!(RunAs::parse(Some(run_as.name())), Ok(run_as));
        }
    }

    #[test]
    fn reads_elevation_from_token_info() {
        assert_eq!(Elevation::from_token_info(1, false), Elevation::Standard);
        assert_eq!(Elevation::from_token_info(1, true), Elevation::Full);
        assert_eq!(Elevation::from_token_info(2, true), Elevation::Full);
        assert_eq!(Elevation::from_token_info(3, false), Elevation::Limited);
    }

    #[test]
    fn system_never_asks_for_a_session() {
        let nobody = FakeSessions {
            user: None,
            elevation: Elevation::Standard,
        };

        assert_eq!(token_for(&nobody, RunAs::System, "TILL-01"), Ok(None));
    }

    #[test]
    fn user_and_elevated_fail_when_nobody_is_logged_in() {
        let nobody = FakeSessions {
            user: None,
            elevation: Elevation::Full,
        };

        for run_as in [RunAs::User, RunAs::UserElevated] {
            assert_eq!(
                token_for(&nobody, run_as, "TILL-01"),
                Err("Nobody is logged in on TILL-01".to_string())
            );
        }
    }

    #[test]
    fn user_runs_with_the_console_token_whatever_its_elevation() {
        assert_eq!(
            token_for(&logged_in(Elevation::Limited), RunAs::User, "TILL-01"),
            Ok(Some("ben".to_string()))
        );
    }

    #[test]
    fn elevated_uses_the_linked_token_for_a_limited_admin() {
        assert_eq!(
            token_for(&logged_in(Elevation::Limited), RunAs::UserElevated, "TILL-01"),
            Ok(Some("ben (elevated)".to_string()))
        );
    }

    #[test]
    fn elevated_uses_an_already_elevated_token_as_is() {
        assert_eq!(
            token_for(&logged_in(Elevation::Full), RunAs::UserElevated, "TILL-01"),
            Ok(Some("ben".to_string()))
        );
    }

    #[test]
    fn elevated_fails_cleanly_for_a_standard_user() {
        let error = token_for(&logged_in(Elevation::Standard), RunAs::UserElevated, "TILL-01")
            .unwrap_err();

        assert!(error.contains("TILL-01 is not an administrator"));
    }
}
