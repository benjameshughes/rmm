//! Local Windows accounts: who can sign in to this PC, which of them are in
//! the local Administrators group (S-1-5-32-544, found by SID so any language
//! works), when each last signed in and who is signed in now.
//!
//! Read-only: NetUserEnum, NetUserGetInfo, NetLocalGroupGetMembers and
//! NetWkstaUserEnum. Never reads a password or a hash. Each reading fails
//! soft: an unreadable group or session list leaves `is_admin` or
//! `is_signed_in` null rather than false. Windows only; other builds send
//! nothing, so the Linux agent stays exactly as it was.
#![cfg_attr(not(windows), allow(dead_code))]

use chrono::{DateTime, SecondsFormat};
use serde::Serialize;
use std::collections::HashSet;

/// One local account as the server receives it.
#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct LocalAccount {
    pub name: String,
    pub full_name: Option<String>,
    pub sid: String,
    pub is_enabled: bool,
    pub is_admin: Option<bool>,
    pub last_logon_at: Option<String>,
    pub is_signed_in: Option<bool>,
}

/// One account as read from USER_INFO_4.
#[derive(Debug, Clone, PartialEq)]
pub struct RawAccount {
    pub name: String,
    pub full_name: String,
    pub sid: String,
    pub flags: u32,
    /// Seconds since 1970 from usri4_last_logon; 0 means never.
    pub last_logon: Option<u32>,
}

/// UF_ACCOUNTDISABLE, copied so the pure code builds on every platform.
const ACCOUNT_DISABLED_FLAG: u32 = 0x0002;

/// Combines the readings. `admin_sids` and `signed_in` are None when they
/// could not be read; `signed_in` holds lower-cased local user names.
pub fn assemble(
    raw: Vec<RawAccount>,
    admin_sids: Option<&HashSet<String>>,
    signed_in: Option<&HashSet<String>>,
) -> Vec<LocalAccount> {
    raw.into_iter()
        .map(|account| LocalAccount {
            is_admin: admin_sids.map(|sids| sids.contains(&account.sid.to_uppercase())),
            is_signed_in: signed_in.map(|names| names.contains(&account.name.to_lowercase())),
            is_enabled: account.flags & ACCOUNT_DISABLED_FLAG == 0,
            full_name: Some(account.full_name.trim().to_string()).filter(|name| !name.is_empty()),
            last_logon_at: account.last_logon.and_then(logon_time),
            name: account.name,
            sid: account.sid,
        })
        .collect()
}

/// usri4_last_logon as an ISO-8601 time; 0 (never signed in) is None.
pub fn logon_time(seconds: u32) -> Option<String> {
    if seconds == 0 {
        return None;
    }

    DateTime::from_timestamp(i64::from(seconds), 0).map(|time| time.to_rfc3339_opts(SecondsFormat::Secs, true))
}

/// The lower-cased names of the local users among the workstation's logon
/// sessions: those whose logon domain is this computer.
pub fn local_session_names(sessions: &[(String, String)], computer: &str) -> HashSet<String> {
    sessions
        .iter()
        .filter(|(_, domain)| domain.eq_ignore_ascii_case(computer))
        .map(|(user, _)| user.to_lowercase())
        .collect()
}

/// Reads every local account, or None when the account list itself could not
/// be read. Runs the blocking Win32 calls off the async runtime.
#[cfg(windows)]
pub async fn collect() -> Option<Vec<LocalAccount>> {
    tokio::task::spawn_blocking(platform::read).await.ok().flatten()
}

#[cfg(not(windows))]
pub async fn collect() -> Option<Vec<LocalAccount>> {
    None
}

#[cfg(windows)]
mod platform {
    use super::{assemble, local_session_names, LocalAccount, RawAccount};
    use std::collections::HashSet;
    use std::ptr::{null, null_mut};
    use winapi::shared::lmcons::MAX_PREFERRED_LENGTH;
    use winapi::shared::minwindef::{DWORD, LPBYTE};
    use winapi::shared::sddl::{ConvertSidToStringSidW, ConvertStringSidToSidW};
    use winapi::shared::winerror::ERROR_MORE_DATA;
    use winapi::um::lmaccess::{
        NetLocalGroupGetMembers, NetUserEnum, NetUserGetInfo, FILTER_NORMAL_ACCOUNT,
        LOCALGROUP_MEMBERS_INFO_0, USER_INFO_0, USER_INFO_4,
    };
    use winapi::um::lmapibuf::NetApiBufferFree;
    use winapi::um::lmwksta::{NetWkstaUserEnum, WKSTA_USER_INFO_1};
    use winapi::um::winbase::{GetComputerNameW, LocalFree, LookupAccountSidW};
    use winapi::um::winnt::{PSID, SID_NAME_USE};
    use tracing::warn;

    const NERR_SUCCESS: DWORD = 0;
    const ADMINISTRATORS_SID: &str = "S-1-5-32-544";
    const NAME_BUFFER_CHARS: usize = 256;

    pub fn read() -> Option<Vec<LocalAccount>> {
        let accounts = users()?;
        let admins = administrator_sids();
        let signed_in = signed_in_names();

        Some(assemble(accounts, admins.as_ref(), signed_in.as_ref()))
    }

    fn users() -> Option<Vec<RawAccount>> {
        let mut accounts = Vec::new();
        let mut resume: DWORD = 0;

        loop {
            let mut buffer: LPBYTE = null_mut();
            let (mut read, mut total): (DWORD, DWORD) = (0, 0);
            // SAFETY: every out-pointer is a valid local; the buffer is freed below.
            let status = unsafe {
                NetUserEnum(null(), 0, FILTER_NORMAL_ACCOUNT, &mut buffer, MAX_PREFERRED_LENGTH, &mut read, &mut total, &mut resume)
            };

            if status != NERR_SUCCESS && status != ERROR_MORE_DATA {
                warn!("NetUserEnum failed with status {}; local accounts not reported", status);
                free(buffer);
                return None;
            }

            if !buffer.is_null() {
                // SAFETY: on success the buffer holds `read` USER_INFO_0 entries.
                let entries = unsafe { std::slice::from_raw_parts(buffer as *const USER_INFO_0, read as usize) };
                accounts.extend(entries.iter().filter_map(|entry| raw_account(entry.usri0_name)));
                free(buffer);
            }

            if status != ERROR_MORE_DATA {
                return Some(accounts);
            }
        }
    }

    /// One account's details from NetUserGetInfo level 4, which carries the SID.
    fn raw_account(name: *const u16) -> Option<RawAccount> {
        let mut buffer: LPBYTE = null_mut();
        // SAFETY: `name` is the NUL-terminated name from NetUserEnum's live buffer.
        let status = unsafe { NetUserGetInfo(null(), name, 4, &mut buffer) };
        if status != NERR_SUCCESS || buffer.is_null() {
            warn!("NetUserGetInfo failed with status {}; skipping one local account", status);
            free(buffer);
            return None;
        }

        // SAFETY: level 4 fills one USER_INFO_4.
        let info = unsafe { &*(buffer as *const USER_INFO_4) };
        let account = (|| {
            Some(RawAccount {
                name: wide(info.usri4_name)?,
                full_name: wide(info.usri4_full_name).unwrap_or_default(),
                sid: sid_string(info.usri4_user_sid)?,
                flags: info.usri4_flags,
                last_logon: Some(info.usri4_last_logon),
            })
        })();
        free(buffer);

        account
    }

    /// The upper-cased SIDs of the local Administrators group's direct members.
    fn administrator_sids() -> Option<HashSet<String>> {
        let group = administrators_group_name()?;
        let mut sids = HashSet::new();
        let mut resume: usize = 0;

        loop {
            let mut buffer: LPBYTE = null_mut();
            let (mut read, mut total): (DWORD, DWORD) = (0, 0);
            // SAFETY: `group` is NUL-terminated; out-pointers are valid locals.
            let status = unsafe {
                NetLocalGroupGetMembers(null(), group.as_ptr(), 0, &mut buffer, MAX_PREFERRED_LENGTH, &mut read, &mut total, &mut resume)
            };

            if status != NERR_SUCCESS && status != ERROR_MORE_DATA {
                free(buffer);
                return None;
            }

            if !buffer.is_null() {
                // SAFETY: level 0 fills `read` LOCALGROUP_MEMBERS_INFO_0 entries.
                let members = unsafe { std::slice::from_raw_parts(buffer as *const LOCALGROUP_MEMBERS_INFO_0, read as usize) };
                sids.extend(members.iter().filter_map(|member| sid_string(member.lgrmi0_sid)).map(|sid| sid.to_uppercase()));
                free(buffer);
            }

            if status != ERROR_MORE_DATA {
                return Some(sids);
            }
        }
    }

    /// The Administrators group's name in this PC's language, NUL-terminated.
    fn administrators_group_name() -> Option<Vec<u16>> {
        let text: Vec<u16> = ADMINISTRATORS_SID.encode_utf16().chain(Some(0)).collect();
        let mut sid: PSID = null_mut();
        // SAFETY: `text` is NUL-terminated; the SID is freed with LocalFree below.
        if unsafe { ConvertStringSidToSidW(text.as_ptr(), &mut sid) } == 0 {
            return None;
        }

        let mut name = vec![0u16; NAME_BUFFER_CHARS];
        let mut domain = vec![0u16; NAME_BUFFER_CHARS];
        let (mut name_len, mut domain_len) = (NAME_BUFFER_CHARS as DWORD, NAME_BUFFER_CHARS as DWORD);
        let mut name_use: SID_NAME_USE = 0;
        // SAFETY: the buffers and their lengths match.
        let found = unsafe {
            LookupAccountSidW(null(), sid, name.as_mut_ptr(), &mut name_len, domain.as_mut_ptr(), &mut domain_len, &mut name_use)
        };
        // SAFETY: allocated by ConvertStringSidToSidW.
        unsafe { LocalFree(sid as _) };

        if found == 0 {
            return None;
        }

        name.truncate(name_len as usize);
        name.push(0);
        Some(name)
    }

    /// The lower-cased names of local users with a logon session right now.
    fn signed_in_names() -> Option<HashSet<String>> {
        let computer = computer_name()?;
        let mut sessions = Vec::new();
        let mut resume: DWORD = 0;

        loop {
            let mut buffer: LPBYTE = null_mut();
            let (mut read, mut total): (DWORD, DWORD) = (0, 0);
            // SAFETY: out-pointers are valid locals; the buffer is freed below.
            let status = unsafe { NetWkstaUserEnum(null_mut(), 1, &mut buffer, MAX_PREFERRED_LENGTH, &mut read, &mut total, &mut resume) };

            if status != NERR_SUCCESS && status != ERROR_MORE_DATA {
                free(buffer);
                return None;
            }

            if !buffer.is_null() {
                // SAFETY: level 1 fills `read` WKSTA_USER_INFO_1 entries.
                let entries = unsafe { std::slice::from_raw_parts(buffer as *const WKSTA_USER_INFO_1, read as usize) };
                sessions.extend(entries.iter().filter_map(|entry| Some((wide(entry.wkui1_username)?, wide(entry.wkui1_logon_domain)?))));
                free(buffer);
            }

            if status != ERROR_MORE_DATA {
                return Some(local_session_names(&sessions, &computer));
            }
        }
    }

    fn computer_name() -> Option<String> {
        let mut buffer = vec![0u16; NAME_BUFFER_CHARS];
        let mut len = NAME_BUFFER_CHARS as DWORD;
        // SAFETY: the buffer and its length match.
        if unsafe { GetComputerNameW(buffer.as_mut_ptr(), &mut len) } == 0 {
            return None;
        }

        Some(String::from_utf16_lossy(&buffer[..len as usize]))
    }

    fn sid_string(sid: PSID) -> Option<String> {
        if sid.is_null() {
            return None;
        }

        let mut text: *mut u16 = null_mut();
        // SAFETY: `sid` came from a live NetApi buffer; `text` is freed with LocalFree.
        if unsafe { ConvertSidToStringSidW(sid, &mut text) } == 0 {
            return None;
        }

        let value = wide(text);
        // SAFETY: allocated by ConvertSidToStringSidW.
        unsafe { LocalFree(text as _) };

        value
    }

    /// A NUL-terminated UTF-16 string, or None for a null pointer.
    fn wide(pointer: *const u16) -> Option<String> {
        if pointer.is_null() {
            return None;
        }

        // SAFETY: Win32 hands back NUL-terminated strings; we stop at the NUL.
        let len = (0..).take_while(|&offset| unsafe { *pointer.add(offset) } != 0).count();
        // SAFETY: `len` characters were just read from this pointer.
        Some(String::from_utf16_lossy(unsafe { std::slice::from_raw_parts(pointer, len) }))
    }

    fn free(buffer: LPBYTE) {
        if !buffer.is_null() {
            // SAFETY: every buffer here was allocated by a NetApi call.
            unsafe { NetApiBufferFree(buffer as _) };
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn raw(name: &str, sid: &str, flags: u32, last_logon: Option<u32>) -> RawAccount {
        RawAccount {
            name: name.into(),
            full_name: String::new(),
            sid: sid.into(),
            flags,
            last_logon,
        }
    }

    #[test]
    fn marks_disabled_admin_and_signed_in_accounts() {
        let admins: HashSet<String> = ["S-1-5-21-1-2-3-500".to_string()].into();
        let signed_in: HashSet<String> = ["sophie".to_string()].into();

        let accounts = assemble(
            vec![
                raw("Administrator", "S-1-5-21-1-2-3-500", 0x0202, Some(0)),
                raw("Sophie", "S-1-5-21-1-2-3-1001", 0x0200, Some(1_793_000_000)),
            ],
            Some(&admins),
            Some(&signed_in),
        );

        assert!(!accounts[0].is_enabled);
        assert_eq!(accounts[0].is_admin, Some(true));
        assert_eq!(accounts[0].last_logon_at, None);
        assert_eq!(accounts[0].is_signed_in, Some(false));

        assert!(accounts[1].is_enabled);
        assert_eq!(accounts[1].is_admin, Some(false));
        assert_eq!(accounts[1].is_signed_in, Some(true));
        assert_eq!(accounts[1].last_logon_at.as_deref(), Some("2026-10-26T07:33:20Z"));
    }

    #[test]
    fn unreadable_readings_stay_unknown() {
        let accounts = assemble(vec![raw("Sophie", "S-1-5-21-1-2-3-1001", 0, None)], None, None);

        assert_eq!(accounts[0].is_admin, None);
        assert_eq!(accounts[0].is_signed_in, None);
        assert_eq!(accounts[0].last_logon_at, None);

        let json = serde_json::to_value(&accounts[0]).unwrap();
        assert!(json["is_admin"].is_null());
        assert!(json["is_signed_in"].is_null());
    }

    #[test]
    fn matches_admin_sids_whatever_their_case() {
        let admins: HashSet<String> = ["S-1-5-21-1-2-3-1001".to_string()].into();
        let accounts = assemble(vec![raw("sophie", "s-1-5-21-1-2-3-1001", 0, None)], Some(&admins), None);

        assert_eq!(accounts[0].is_admin, Some(true));
    }

    #[test]
    fn blank_full_names_are_none() {
        let mut account = raw("sophie", "S-1-5-21-1-2-3-1001", 0, None);
        account.full_name = "  ".into();

        assert_eq!(assemble(vec![account], None, None)[0].full_name, None);
    }

    #[test]
    fn only_sessions_on_this_computer_count_as_local() {
        let sessions = vec![
            ("Sophie".to_string(), "OFFICE-PC1".to_string()),
            ("OFFICE-PC1$".to_string(), "WORKGROUP".to_string()),
            ("ben".to_string(), "CONTOSO".to_string()),
        ];

        let names = local_session_names(&sessions, "office-pc1");

        assert_eq!(names, ["sophie".to_string()].into());
    }
}
