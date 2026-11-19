//! Starts a command as the user logged in at the console, from the SYSTEM
//! service: their token from `WTSQueryUserToken` (or its elevated linked
//! token), their own environment from `CreateEnvironmentBlock`, the
//! `winsta0\default` desktop with no window, and stdout/stderr on pipes so the
//! runner reads them exactly as it reads a SYSTEM run.
//!
//! The command line and environment block are built by plain functions, tested
//! everywhere; the Win32 calls are Windows only.

// Only Windows builds (and tests) run commands.
#![cfg_attr(not(any(windows, test)), allow(dead_code))]

/// One argument quoted the way `CommandLineToArgvW` and the C runtime read it.
pub fn quote_argument(argument: &str) -> String {
    if !argument.is_empty() && !argument.contains([' ', '\t', '"']) {
        return argument.to_string();
    }

    let mut quoted = String::from('"');
    let mut backslashes = 0;

    for character in argument.chars() {
        match character {
            '\\' => {
                backslashes += 1;
                continue;
            }
            '"' => quoted.push_str(&"\\".repeat(backslashes * 2 + 1)),
            _ => quoted.push_str(&"\\".repeat(backslashes)),
        }
        quoted.push(character);
        backslashes = 0;
    }

    quoted.push_str(&"\\".repeat(backslashes * 2));
    quoted.push('"');
    quoted
}

pub fn command_line(program: &str, arguments: &[String]) -> String {
    std::iter::once(program)
        .chain(arguments.iter().map(String::as_str))
        .map(quote_argument)
        .collect::<Vec<_>>()
        .join(" ")
}

/// The `NAME=value` entries of a UTF-16 environment block (each entry
/// NUL-terminated, the block ending with an empty entry).
pub fn parse_environment_block(block: &[u16]) -> Vec<String> {
    block
        .split(|unit| *unit == 0)
        .take_while(|entry| !entry.is_empty())
        .map(String::from_utf16_lossy)
        .collect()
}

/// Windows names may start with `=` (the per-drive `=C:` entries), so the
/// separator is the first `=` after the first character.
fn variable_name(entry: &str) -> &str {
    entry
        .char_indices()
        .skip(1)
        .find(|(_, character)| *character == '=')
        .map_or(entry, |(index, _)| &entry[..index])
}

/// The user's environment with `extra` set on top (replacing same-named
/// variables, ignoring case), sorted by name and encoded as a block.
pub fn build_environment_block(entries: &[String], extra: &[(String, String)]) -> Vec<u16> {
    let mut merged: Vec<String> = entries
        .iter()
        .filter(|entry| {
            !extra
                .iter()
                .any(|(name, _)| variable_name(entry).eq_ignore_ascii_case(name))
        })
        .cloned()
        .chain(extra.iter().map(|(name, value)| format!("{}={}", name, value)))
        .collect();

    merged.sort_by_key(|entry| variable_name(entry).to_ascii_uppercase());

    let mut block: Vec<u16> = merged
        .iter()
        .flat_map(|entry| entry.encode_utf16().chain(Some(0)))
        .collect();
    block.push(0);
    if merged.is_empty() {
        block.push(0);
    }
    block
}

/// The user's profile folder, to start the command in, when the block has one.
pub fn profile_directory(entries: &[String]) -> Option<String> {
    entries
        .iter()
        .find(|entry| variable_name(entry).eq_ignore_ascii_case("USERPROFILE"))
        .and_then(|entry| entry.split_once('=').map(|(_, value)| value.to_string()))
        .filter(|value| !value.is_empty())
}

/// SYSTEM and Administrators keep full control of the staged script; the user
/// it runs as may only read it.
pub fn script_file_sddl(user_sid: &str) -> String {
    format!("D:P(A;;FA;;;SY)(A;;FA;;;BA)(A;;FR;;;{})", user_sid)
}

#[cfg(windows)]
pub use windows::{grant_read, spawn, terminate, wait, Waited, WtsSessions};

#[cfg(windows)]
mod windows {
    use super::{build_environment_block, parse_environment_block, profile_directory, script_file_sddl};
    use crate::run_as::{ConsoleSessions, Elevation};
    use std::ffi::OsStr;
    use std::io;
    use std::mem::{size_of, zeroed};
    use std::os::windows::ffi::OsStrExt;
    use std::os::windows::io::{AsRawHandle, FromRawHandle, OwnedHandle, RawHandle};
    use std::path::Path;
    use std::ptr::{null, null_mut};
    use std::time::Duration;
    use winapi::shared::minwindef::{DWORD, FALSE, LPVOID, TRUE};
    use winapi::shared::sddl::{ConvertSidToStringSidW, ConvertStringSecurityDescriptorToSecurityDescriptorW, SDDL_REVISION_1};
    use winapi::shared::winerror::{ERROR_NO_TOKEN, WAIT_TIMEOUT};
    use winapi::um::handleapi::SetHandleInformation;
    use winapi::um::minwinbase::SECURITY_ATTRIBUTES;
    use winapi::um::namedpipeapi::CreatePipe;
    use winapi::um::processthreadsapi::{
        CreateProcessAsUserW, GetExitCodeProcess, TerminateProcess, PROCESS_INFORMATION, STARTUPINFOW,
    };
    use winapi::um::securitybaseapi::{DuplicateTokenEx, GetTokenInformation, SetFileSecurityW};
    use winapi::um::synchapi::WaitForSingleObject;
    use winapi::um::userenv::{CreateEnvironmentBlock, DestroyEnvironmentBlock};
    use winapi::um::winbase::{
        LocalFree, WTSGetActiveConsoleSessionId, CREATE_NO_WINDOW, CREATE_UNICODE_ENVIRONMENT,
        HANDLE_FLAG_INHERIT, STARTF_USESHOWWINDOW, STARTF_USESTDHANDLES, WAIT_OBJECT_0,
    };
    use winapi::um::winnt::{
        SecurityImpersonation, TokenElevation, TokenElevationType, TokenLinkedToken, TokenPrimary,
        TokenUser, DACL_SECURITY_INFORMATION, HANDLE, PROTECTED_DACL_SECURITY_INFORMATION,
        TOKEN_ALL_ACCESS, TOKEN_ELEVATION, TOKEN_ELEVATION_TYPE, TOKEN_LINKED_TOKEN, TOKEN_USER,
    };
    use winapi::um::winuser::SW_HIDE;
    use winapi::um::wtsapi32::WTSQueryUserToken;

    const NO_CONSOLE_SESSION: DWORD = 0xFFFF_FFFF;

    fn wide(text: &str) -> Vec<u16> {
        OsStr::new(text).encode_wide().chain(Some(0)).collect()
    }

    fn raw(handle: &OwnedHandle) -> HANDLE {
        handle.as_raw_handle() as HANDLE
    }

    /// Takes ownership of a handle a Win32 call just returned.
    fn own(handle: HANDLE) -> OwnedHandle {
        unsafe { OwnedHandle::from_raw_handle(handle as RawHandle) }
    }

    fn last_error(what: &str) -> String {
        format!("{}: {}", what, io::Error::last_os_error())
    }

    /// A fixed-size token fact, such as `TOKEN_ELEVATION`.
    fn token_information<T>(token: &OwnedHandle, class: u32, what: &str) -> Result<T, String> {
        let mut value: T = unsafe { zeroed() };
        let mut returned: DWORD = 0;
        let ok = unsafe {
            GetTokenInformation(
                raw(token),
                class,
                &mut value as *mut T as LPVOID,
                size_of::<T>() as DWORD,
                &mut returned,
            )
        };

        if ok == FALSE {
            return Err(last_error(what));
        }
        Ok(value)
    }

    fn primary_copy(token: HANDLE) -> Result<OwnedHandle, String> {
        let mut primary: HANDLE = null_mut();
        let ok = unsafe {
            DuplicateTokenEx(token, TOKEN_ALL_ACCESS, null_mut(), SecurityImpersonation, TokenPrimary, &mut primary)
        };

        if ok == FALSE {
            return Err(last_error("Could not copy the user's token"));
        }
        Ok(own(primary))
    }

    /// The session at the physical console, through the WTS calls only SYSTEM may make.
    pub struct WtsSessions;

    impl ConsoleSessions for WtsSessions {
        type Token = OwnedHandle;

        fn active_user_token(&self) -> Result<Option<OwnedHandle>, String> {
            let session = unsafe { WTSGetActiveConsoleSessionId() };
            if session == NO_CONSOLE_SESSION {
                return Ok(None);
            }

            let mut token: HANDLE = null_mut();
            if unsafe { WTSQueryUserToken(session, &mut token) } == FALSE {
                let error = io::Error::last_os_error();
                return match error.raw_os_error() {
                    Some(code) if code as u32 == ERROR_NO_TOKEN => Ok(None),
                    _ => Err(format!("Could not get the logged-in user's token: {}", error)),
                };
            }

            Ok(Some(own(token)))
        }

        fn elevation(&self, token: &OwnedHandle) -> Result<Elevation, String> {
            let elevation_type: TOKEN_ELEVATION_TYPE =
                token_information(token, TokenElevationType, "Could not read the user's elevation type")?;
            let elevation: TOKEN_ELEVATION =
                token_information(token, TokenElevation, "Could not read the user's elevation")?;

            Ok(Elevation::from_token_info(elevation_type, elevation.TokenIsElevated != 0))
        }

        fn linked_token(&self, token: &OwnedHandle) -> Result<OwnedHandle, String> {
            let linked: TOKEN_LINKED_TOKEN =
                token_information(token, TokenLinkedToken, "Could not get the user's administrator token")?;
            let linked = own(linked.LinkedToken);

            primary_copy(raw(&linked))
        }
    }

    /// The user's SID as `S-1-5-21-...`.
    fn user_sid(token: &OwnedHandle) -> Result<String, String> {
        let mut needed: DWORD = 0;
        unsafe { GetTokenInformation(raw(token), TokenUser, null_mut(), 0, &mut needed) };
        if needed == 0 {
            return Err(last_error("Could not size the user's SID"));
        }

        let mut buffer = vec![0u64; (needed as usize).div_ceil(8)];
        let ok = unsafe {
            GetTokenInformation(raw(token), TokenUser, buffer.as_mut_ptr() as LPVOID, needed, &mut needed)
        };
        if ok == FALSE {
            return Err(last_error("Could not read the user's SID"));
        }

        let user = unsafe { &*(buffer.as_ptr() as *const TOKEN_USER) };
        let mut text: *mut u16 = null_mut();
        if unsafe { ConvertSidToStringSidW(user.User.Sid, &mut text) } == FALSE {
            return Err(last_error("Could not format the user's SID"));
        }

        let length = (0..).take_while(|&index| unsafe { *text.add(index) } != 0).count();
        let sid = String::from_utf16_lossy(unsafe { std::slice::from_raw_parts(text, length) });
        unsafe { LocalFree(text as _) };
        Ok(sid)
    }

    /// Lets the user read the staged script, which otherwise only SYSTEM and
    /// Administrators can open inside the locked data directory.
    pub fn grant_read(path: &Path, token: &OwnedHandle) -> Result<(), String> {
        let sddl = wide(&script_file_sddl(&user_sid(token)?));
        let mut descriptor: LPVOID = null_mut();
        let ok = unsafe {
            ConvertStringSecurityDescriptorToSecurityDescriptorW(
                sddl.as_ptr(),
                SDDL_REVISION_1 as DWORD,
                &mut descriptor,
                null_mut(),
            )
        };
        if ok == FALSE {
            return Err(last_error("Could not build the script's permissions"));
        }

        let path = wide(&path.to_string_lossy());
        let ok = unsafe {
            SetFileSecurityW(
                path.as_ptr(),
                DACL_SECURITY_INFORMATION | PROTECTED_DACL_SECURITY_INFORMATION,
                descriptor,
            )
        };
        let error = (ok == FALSE).then(|| last_error("Could not let the user read the script"));
        unsafe { LocalFree(descriptor as _) };

        error.map_or(Ok(()), Err)
    }

    /// The user's own environment (their PATH, profile and so on) as entries.
    fn user_environment(token: &OwnedHandle) -> Result<Vec<String>, String> {
        let mut block: LPVOID = null_mut();
        if unsafe { CreateEnvironmentBlock(&mut block, raw(token), FALSE) } == FALSE {
            return Err(last_error("Could not load the user's environment"));
        }

        let units = block as *const u16;
        let mut length = 0;
        while unsafe { *units.add(length) != 0 || *units.add(length + 1) != 0 } {
            length += 1;
        }
        let entries = parse_environment_block(unsafe { std::slice::from_raw_parts(units, length + 2) });
        unsafe { DestroyEnvironmentBlock(block) };

        Ok(entries)
    }

    /// A pipe whose write end the child inherits and whose read end stays here.
    fn output_pipe() -> Result<(OwnedHandle, OwnedHandle), String> {
        let mut attributes = SECURITY_ATTRIBUTES {
            nLength: size_of::<SECURITY_ATTRIBUTES>() as DWORD,
            lpSecurityDescriptor: null_mut(),
            bInheritHandle: TRUE,
        };
        let (mut read, mut write): (HANDLE, HANDLE) = (null_mut(), null_mut());

        if unsafe { CreatePipe(&mut read, &mut write, &mut attributes, 0) } == FALSE {
            return Err(last_error("Could not create an output pipe"));
        }
        let (read, write) = (own(read), own(write));

        if unsafe { SetHandleInformation(raw(&read), HANDLE_FLAG_INHERIT, 0) } == FALSE {
            return Err(last_error("Could not keep the output pipe private"));
        }
        Ok((read, write))
    }

    pub struct UserChild {
        pub process: OwnedHandle,
        pub pid: u32,
        pub stdout: OwnedHandle,
        pub stderr: OwnedHandle,
    }

    /// Start `command_line` as the token's user, hidden on their desktop, with
    /// `extra_env` set on top of their environment.
    pub fn spawn(
        token: &OwnedHandle,
        command_line: &str,
        extra_env: &[(String, String)],
    ) -> Result<UserChild, String> {
        let entries = user_environment(token)?;
        let mut environment = build_environment_block(&entries, extra_env);
        let directory = profile_directory(&entries).map(|directory| wide(&directory));

        let (stdout_read, stdout_write) = output_pipe()?;
        let (stderr_read, stderr_write) = output_pipe()?;

        let mut desktop = wide(r"winsta0\default");
        let mut command_line = wide(command_line);
        let mut startup: STARTUPINFOW = unsafe { zeroed() };
        startup.cb = size_of::<STARTUPINFOW>() as DWORD;
        startup.lpDesktop = desktop.as_mut_ptr();
        startup.dwFlags = STARTF_USESTDHANDLES | STARTF_USESHOWWINDOW;
        startup.wShowWindow = SW_HIDE as u16;
        startup.hStdOutput = raw(&stdout_write);
        startup.hStdError = raw(&stderr_write);

        let mut process: PROCESS_INFORMATION = unsafe { zeroed() };
        let ok = unsafe {
            CreateProcessAsUserW(
                raw(token),
                null(),
                command_line.as_mut_ptr(),
                null_mut(),
                null_mut(),
                TRUE,
                CREATE_NO_WINDOW | CREATE_UNICODE_ENVIRONMENT,
                environment.as_mut_ptr() as LPVOID,
                directory.as_ref().map_or(null(), |directory| directory.as_ptr()),
                &mut startup,
                &mut process,
            )
        };
        let error = (ok == FALSE).then(|| last_error("Could not start the command as the logged-in user"));

        // The child has its own copies now; ours must close or the pipes never end.
        drop(stdout_write);
        drop(stderr_write);

        if let Some(error) = error {
            return Err(error);
        }
        drop(own(process.hThread));

        Ok(UserChild {
            process: own(process.hProcess),
            pid: process.dwProcessId,
            stdout: stdout_read,
            stderr: stderr_read,
        })
    }

    pub enum Waited {
        Exited(i32),
        TimedOut,
        Failed(String),
    }

    /// Blocks until the process exits or `timeout` passes.
    pub fn wait(process: &OwnedHandle, timeout: Duration) -> Waited {
        let milliseconds = timeout.as_millis().min(u128::from(u32::MAX - 1)) as DWORD;

        match unsafe { WaitForSingleObject(raw(process), milliseconds) } {
            WAIT_OBJECT_0 => {
                let mut code: DWORD = 0;
                if unsafe { GetExitCodeProcess(raw(process), &mut code) } == FALSE {
                    return Waited::Failed(last_error("Could not read the exit code"));
                }
                Waited::Exited(code as i32)
            }
            WAIT_TIMEOUT => Waited::TimedOut,
            _ => Waited::Failed(last_error("Lost track of the command's process")),
        }
    }

    pub fn terminate(process: &OwnedHandle) {
        unsafe { TerminateProcess(raw(process), 1) };
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn block(entries: &[&str]) -> Vec<u16> {
        let mut block: Vec<u16> = entries
            .iter()
            .flat_map(|entry| entry.encode_utf16().chain(Some(0)))
            .collect();
        block.push(0);
        block
    }

    #[test]
    fn leaves_plain_arguments_alone() {
        assert_eq!(quote_argument("-NoProfile"), "-NoProfile");
        assert_eq!(quote_argument(r"C:\RMM\work\command-1.ps1"), r"C:\RMM\work\command-1.ps1");
    }

    #[test]
    fn quotes_spaces_quotes_and_trailing_backslashes() {
        assert_eq!(quote_argument(""), "\"\"");
        assert_eq!(quote_argument(r"C:\Program Files\x"), r#""C:\Program Files\x""#);
        assert_eq!(quote_argument(r#"say "hi""#), r#""say \"hi\"""#);
        assert_eq!(quote_argument(r"C:\with space\"), r#""C:\with space\\""#);
        assert_eq!(quote_argument(r#"a\"b c"#), r#""a\\\"b c""#);
    }

    #[test]
    fn builds_a_command_line() {
        let arguments = vec!["-File".to_string(), r"C:\Program Data\s.ps1".to_string()];

        assert_eq!(
            command_line(r"C:\Windows\powershell.exe", &arguments),
            r#"C:\Windows\powershell.exe -File "C:\Program Data\s.ps1""#
        );
    }

    #[test]
    fn parses_an_environment_block() {
        assert_eq!(
            parse_environment_block(&block(&["=C:=C:\\", "PATH=C:\\a;C:\\b", "USERPROFILE=C:\\Users\\ben"])),
            vec!["=C:=C:\\", "PATH=C:\\a;C:\\b", "USERPROFILE=C:\\Users\\ben"]
        );
        assert!(parse_environment_block(&[0, 0]).is_empty());
    }

    #[test]
    fn sets_parameters_on_top_of_the_user_environment_sorted() {
        let entries = vec![
            "Path=C:\\Users\\ben\\scoop\\shims".to_string(),
            "rmm_packageid=old".to_string(),
            "=C:=C:\\".to_string(),
        ];
        let extra = vec![("RMM_PackageId".to_string(), "Mozilla.Firefox".to_string())];

        assert_eq!(
            parse_environment_block(&build_environment_block(&entries, &extra)),
            vec!["=C:=C:\\", "Path=C:\\Users\\ben\\scoop\\shims", "RMM_PackageId=Mozilla.Firefox"]
        );
    }

    #[test]
    fn an_empty_environment_is_still_a_valid_block() {
        assert_eq!(build_environment_block(&[], &[]), vec![0, 0]);
    }

    #[test]
    fn finds_the_profile_directory() {
        let entries = vec!["PATH=x".to_string(), "UserProfile=C:\\Users\\ben".to_string()];

        assert_eq!(profile_directory(&entries).as_deref(), Some("C:\\Users\\ben"));
        assert_eq!(profile_directory(&["USERPROFILE=".to_string()]), None);
    }

    #[test]
    fn script_permissions_let_only_the_user_read() {
        assert_eq!(
            script_file_sddl("S-1-5-21-1-2-3-1001"),
            "D:P(A;;FA;;;SY)(A;;FA;;;BA)(A;;FR;;;S-1-5-21-1-2-3-1001)"
        );
    }
}
