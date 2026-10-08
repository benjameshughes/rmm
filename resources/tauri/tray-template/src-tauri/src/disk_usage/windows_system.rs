//! Windows process and volume setup for `rmm du`: backup privilege,
//! background priority, root normalisation and volume size.

use super::windows::{OwnedHandle, WinDirReader};
use super::Volume;
use std::ffi::OsStr;
use std::mem::zeroed;
use std::os::windows::ffi::OsStrExt;
use std::path::{Path, PathBuf};
use std::ptr::{null, null_mut};
use winapi::shared::minwindef::{DWORD, FALSE, MAX_PATH};
use winapi::shared::ntdef::{LUID, ULARGE_INTEGER};
use winapi::um::fileapi::{GetDiskFreeSpaceExW, GetVolumeInformationW, GetVolumePathNameW};
use winapi::um::processthreadsapi::{GetCurrentProcess, OpenProcessToken, SetPriorityClass};
use winapi::um::securitybaseapi::AdjustTokenPrivileges;
use winapi::um::winbase::{LookupPrivilegeValueW, PROCESS_MODE_BACKGROUND_BEGIN};
use winapi::um::winnt::{
    LUID_AND_ATTRIBUTES, SE_BACKUP_NAME, SE_PRIVILEGE_ENABLED, TOKEN_ADJUST_PRIVILEGES,
    TOKEN_PRIVILEGES,
};

pub fn reader(_root: &Path) -> WinDirReader {
    WinDirReader
}

/// Absolute path with Windows separators. A bare drive (`C:`) means its
/// root, not the drive's current directory.
pub fn normalize_root(path: &Path) -> PathBuf {
    let text = path.to_string_lossy();
    let is_bare_drive = text.len() == 2 && text.ends_with(':');
    let path = if is_bare_drive {
        PathBuf::from(format!(r"{text}\"))
    } else {
        path.to_path_buf()
    };
    std::path::absolute(&path).unwrap_or(path)
}

/// WinSxS hard-links most of System32; reading it last lets System32 own
/// those bytes.
pub fn deferred_dirs(root: &Path) -> Vec<PathBuf> {
    vec![root.join("Windows").join("WinSxS")]
}

/// Background mode lowers CPU, I/O and memory priority for this process.
pub fn enter_background() {
    unsafe { SetPriorityClass(GetCurrentProcess(), PROCESS_MODE_BACKGROUND_BEGIN) };
}

/// SeBackupPrivilege lets an elevated scan list folders whose ACLs exclude
/// Administrators. Best effort: without it those folders count as errors.
pub fn enable_backup_privilege() {
    let mut token = null_mut();
    if unsafe { OpenProcessToken(GetCurrentProcess(), TOKEN_ADJUST_PRIVILEGES, &mut token) }
        == FALSE
    {
        return;
    }
    let token = OwnedHandle(token);

    let name = wide(SE_BACKUP_NAME);
    let mut luid: LUID = unsafe { zeroed() };
    if unsafe { LookupPrivilegeValueW(null(), name.as_ptr(), &mut luid) } == FALSE {
        return;
    }
    let mut privileges = TOKEN_PRIVILEGES {
        PrivilegeCount: 1,
        Privileges: [LUID_AND_ATTRIBUTES {
            Luid: luid,
            Attributes: SE_PRIVILEGE_ENABLED,
        }],
    };
    unsafe { AdjustTokenPrivileges(token.0, FALSE, &mut privileges, 0, null_mut(), null_mut()) };
}

/// Size, free space and filesystem of the volume holding the root.
pub fn volume_info(root: &Path) -> Option<Volume> {
    let root_wide = wide(&root.to_string_lossy());
    let mut total: ULARGE_INTEGER = unsafe { zeroed() };
    let mut free: ULARGE_INTEGER = unsafe { zeroed() };
    let ok = unsafe { GetDiskFreeSpaceExW(root_wide.as_ptr(), null_mut(), &mut total, &mut free) };
    if ok == FALSE {
        return None;
    }

    let mut volume_path = [0u16; MAX_PATH + 1];
    let found = unsafe {
        GetVolumePathNameW(
            root_wide.as_ptr(),
            volume_path.as_mut_ptr(),
            volume_path.len() as DWORD,
        )
    };
    let volume_path = if found == FALSE {
        Vec::new()
    } else {
        until_nul(&volume_path)
    };
    let root_text = with_trailing_separator(&root.to_string_lossy());
    let is_volume_root = String::from_utf16_lossy(&volume_path).eq_ignore_ascii_case(&root_text);

    Some(Volume {
        total: unsafe { *total.QuadPart() },
        free: unsafe { *free.QuadPart() },
        fs: filesystem_name(&volume_path),
        is_volume_root,
    })
}

fn filesystem_name(volume_path: &[u16]) -> String {
    if volume_path.is_empty() {
        return String::new();
    }
    let volume: Vec<u16> = volume_path.iter().copied().chain(Some(0)).collect();
    let mut name = [0u16; MAX_PATH + 1];
    let ok = unsafe {
        GetVolumeInformationW(
            volume.as_ptr(),
            null_mut(),
            0,
            null_mut(),
            null_mut(),
            null_mut(),
            name.as_mut_ptr(),
            name.len() as DWORD,
        )
    };
    if ok == FALSE {
        return String::new();
    }
    String::from_utf16_lossy(&until_nul(&name))
}

fn wide(text: &str) -> Vec<u16> {
    OsStr::new(text).encode_wide().chain(Some(0)).collect()
}

fn until_nul(buffer: &[u16]) -> Vec<u16> {
    buffer
        .iter()
        .copied()
        .take_while(|&unit| unit != 0)
        .collect()
}

fn with_trailing_separator(text: &str) -> String {
    if text.ends_with('\\') {
        text.to_string()
    } else {
        format!(r"{text}\")
    }
}
