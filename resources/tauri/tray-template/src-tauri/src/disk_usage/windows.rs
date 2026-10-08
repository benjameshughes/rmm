//! Windows directory reader for `rmm du`: one handle per folder and
//! `GetFileInformationByHandleEx(FileIdBothDirectoryInfo)` batches, which
//! return sizes, attributes, reparse tags and file ids without opening any
//! file. Ported in spirit from dua-cli's Windows walker (MIT).

use super::{DirListing, DirReader, Entry, EntryKind};
use crate::config::DU_DIR_BUFFER_BYTES;
use std::cell::RefCell;
use std::ffi::OsStr;
use std::mem::{offset_of, size_of, zeroed};
use std::os::windows::ffi::OsStrExt;
use std::path::Path;
use std::ptr::null_mut;
use winapi::shared::minwindef::{DWORD, FALSE, LPVOID};
use winapi::shared::winerror::{ERROR_INVALID_DATA, ERROR_NO_MORE_FILES};
use winapi::um::errhandlingapi::GetLastError;
use winapi::um::fileapi::{
    CreateFileW, GetFileInformationByHandle, BY_HANDLE_FILE_INFORMATION, FILE_ID_BOTH_DIR_INFO,
    FILE_STANDARD_INFO, OPEN_EXISTING,
};
use winapi::um::handleapi::{CloseHandle, INVALID_HANDLE_VALUE};
use winapi::um::minwinbase::{FileIdBothDirectoryInfo, FileStandardInfo};
use winapi::um::winbase::{
    GetFileInformationByHandleEx, FILE_FLAG_BACKUP_SEMANTICS, FILE_FLAG_OPEN_REPARSE_POINT,
};
use winapi::um::winnt::{
    IsReparseTagNameSurrogate, FILE_ATTRIBUTE_DIRECTORY, FILE_ATTRIBUTE_OFFLINE,
    FILE_ATTRIBUTE_RECALL_ON_DATA_ACCESS, FILE_ATTRIBUTE_RECALL_ON_OPEN,
    FILE_ATTRIBUTE_REPARSE_POINT, FILE_LIST_DIRECTORY, FILE_READ_ATTRIBUTES, FILE_SHARE_DELETE,
    FILE_SHARE_READ, FILE_SHARE_WRITE, HANDLE, SYNCHRONIZE,
};

/// Seconds between 1601-01-01 (FILETIME epoch) and 1970-01-01.
const FILETIME_UNIX_OFFSET_SECS: i64 = 11_644_473_600;
const FILETIME_TICKS_PER_SEC: i64 = 10_000_000;
const NAME_OFFSET: usize = offset_of!(FILE_ID_BOTH_DIR_INFO, FileName);

thread_local! {
    /// One listing buffer per worker thread; u64-backed so records align.
    static BUFFER: RefCell<Vec<u64>> =
        RefCell::new(vec![0u64; DU_DIR_BUFFER_BYTES / size_of::<u64>()]);
}

/// A handle closed on drop.
pub struct OwnedHandle(pub HANDLE);

impl Drop for OwnedHandle {
    fn drop(&mut self) {
        unsafe { CloseHandle(self.0) };
    }
}

/// `\\?\`-prefixed, null-terminated wide path, so long paths work.
pub fn extended_path(path: &Path) -> Vec<u16> {
    let text = path.to_string_lossy();
    let extended = if text.starts_with(r"\\?\") {
        text.into_owned()
    } else if let Some(unc) = text.strip_prefix(r"\\") {
        format!(r"\\?\UNC\{unc}")
    } else {
        format!(r"\\?\{text}")
    };
    OsStr::new(&extended).encode_wide().chain(Some(0)).collect()
}

fn open(path: &Path, access: DWORD, flags: DWORD) -> Result<OwnedHandle, i32> {
    let wide = extended_path(path);
    let handle = unsafe {
        CreateFileW(
            wide.as_ptr(),
            access,
            FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE,
            null_mut(),
            OPEN_EXISTING,
            flags,
            null_mut(),
        )
    };
    if handle == INVALID_HANDLE_VALUE {
        return Err(unsafe { GetLastError() } as i32);
    }

    Ok(OwnedHandle(handle))
}

fn volume_serial(handle: &OwnedHandle) -> u64 {
    let mut info: BY_HANDLE_FILE_INFORMATION = unsafe { zeroed() };
    if unsafe { GetFileInformationByHandle(handle.0, &mut info) } == FALSE {
        return 0;
    }
    u64::from(info.dwVolumeSerialNumber)
}

#[derive(Default)]
pub struct WinDirReader;

impl DirReader for WinDirReader {
    fn read_dir(&self, path: &Path) -> DirListing {
        let access = FILE_LIST_DIRECTORY | SYNCHRONIZE;
        let handle = match open(path, access, FILE_FLAG_BACKUP_SEMANTICS) {
            Ok(handle) => handle,
            Err(code) => return DirListing::failed(path, code),
        };
        let serial = volume_serial(&handle);
        let mut listing = DirListing::default();

        BUFFER.with(|buffer| {
            let mut buffer = buffer.borrow_mut();
            let byte_len = buffer.len() * size_of::<u64>();
            loop {
                let ok = unsafe {
                    GetFileInformationByHandleEx(
                        handle.0,
                        FileIdBothDirectoryInfo,
                        buffer.as_mut_ptr() as LPVOID,
                        byte_len as DWORD,
                    )
                };
                if ok == FALSE {
                    let code = unsafe { GetLastError() };
                    if code != ERROR_NO_MORE_FILES {
                        listing.errors.push((path.to_path_buf(), code as i32));
                    }
                    break;
                }
                let bytes =
                    unsafe { std::slice::from_raw_parts(buffer.as_ptr() as *const u8, byte_len) };
                if !parse_records(bytes, serial, &mut listing.entries) {
                    listing
                        .errors
                        .push((path.to_path_buf(), ERROR_INVALID_DATA as i32));
                    break;
                }
            }
        });

        listing
    }

    /// Attribute-only opens are allowed even on files held open exclusively
    /// (pagefile.sys), and never recall cloud files.
    fn refresh_size(&self, path: &Path) -> Option<(u64, u64)> {
        let flags = FILE_FLAG_BACKUP_SEMANTICS | FILE_FLAG_OPEN_REPARSE_POINT;
        let handle = open(path, FILE_READ_ATTRIBUTES, flags).ok()?;
        let mut info: FILE_STANDARD_INFO = unsafe { zeroed() };
        let ok = unsafe {
            GetFileInformationByHandleEx(
                handle.0,
                FileStandardInfo,
                &mut info as *mut FILE_STANDARD_INFO as LPVOID,
                size_of::<FILE_STANDARD_INFO>() as DWORD,
            )
        };
        if ok == FALSE {
            return None;
        }
        let allocated = unsafe { *info.AllocationSize.QuadPart() };
        let logical = unsafe { *info.EndOfFile.QuadPart() };
        Some((allocated.max(0) as u64, logical.max(0) as u64))
    }
}

/// Parse one buffer of FILE_ID_BOTH_DIR_INFO records, skipping `.` and `..`.
/// False when a record runs past the buffer (never trust the offsets).
pub fn parse_records(bytes: &[u8], serial: u64, entries: &mut Vec<Entry>) -> bool {
    let mut offset = 0usize;
    loop {
        let name_start = offset + NAME_OFFSET;
        if name_start > bytes.len() {
            return false;
        }
        let next = read_u32(
            bytes,
            offset + offset_of!(FILE_ID_BOTH_DIR_INFO, NextEntryOffset),
        );
        let name_len = read_u32(
            bytes,
            offset + offset_of!(FILE_ID_BOTH_DIR_INFO, FileNameLength),
        );
        let name_end = name_start + name_len as usize;
        if name_end > bytes.len() {
            return false;
        }

        let name = utf16_name(&bytes[name_start..name_end]);
        if name != "." && name != ".." {
            entries.push(record_entry(bytes, offset, name, serial));
        }
        if next == 0 {
            return true;
        }
        offset += next as usize;
    }
}

fn record_entry(bytes: &[u8], offset: usize, name: String, serial: u64) -> Entry {
    let field = |at: usize| offset + at;
    let attributes = read_u32(
        bytes,
        field(offset_of!(FILE_ID_BOTH_DIR_INFO, FileAttributes)),
    );
    let is_dir = attributes & FILE_ATTRIBUTE_DIRECTORY != 0;
    // For reparse points the EaSize field holds the reparse tag instead.
    let reparse_tag = if attributes & FILE_ATTRIBUTE_REPARSE_POINT != 0 {
        read_u32(bytes, field(offset_of!(FILE_ID_BOTH_DIR_INFO, EaSize)))
    } else {
        0
    };
    let kind = if !is_dir {
        EntryKind::File
    } else if reparse_tag != 0 && IsReparseTagNameSurrogate(reparse_tag) {
        EntryKind::LinkNotFollowed
    } else if attributes & FILE_ATTRIBUTE_RECALL_ON_OPEN != 0 {
        EntryKind::CloudDir
    } else {
        EntryKind::Dir
    };
    let file_id = read_i64(bytes, field(offset_of!(FILE_ID_BOTH_DIR_INFO, FileId))) as u64;
    let cloud_bits = FILE_ATTRIBUTE_RECALL_ON_DATA_ACCESS | FILE_ATTRIBUTE_OFFLINE;

    Entry {
        name,
        kind,
        allocated: read_size(
            bytes,
            field(offset_of!(FILE_ID_BOTH_DIR_INFO, AllocationSize)),
        ),
        logical: read_size(bytes, field(offset_of!(FILE_ID_BOTH_DIR_INFO, EndOfFile))),
        attributes,
        modified: filetime_to_unix(read_i64(
            bytes,
            field(offset_of!(FILE_ID_BOTH_DIR_INFO, LastWriteTime)),
        )),
        link_key: (!is_dir).then_some((serial, file_id)),
        is_cloud: attributes & cloud_bits != 0,
    }
}

fn read_u32(bytes: &[u8], at: usize) -> u32 {
    u32::from_le_bytes(bytes[at..at + 4].try_into().expect("four bytes"))
}

fn read_i64(bytes: &[u8], at: usize) -> i64 {
    i64::from_le_bytes(bytes[at..at + 8].try_into().expect("eight bytes"))
}

fn read_size(bytes: &[u8], at: usize) -> u64 {
    read_i64(bytes, at).max(0) as u64
}

fn utf16_name(bytes: &[u8]) -> String {
    let units: Vec<u16> = bytes
        .as_chunks::<2>()
        .0
        .iter()
        .map(|pair| u16::from_le_bytes(*pair))
        .collect();
    String::from_utf16_lossy(&units)
}

fn filetime_to_unix(ticks: i64) -> i64 {
    ticks / FILETIME_TICKS_PER_SEC - FILETIME_UNIX_OFFSET_SECS
}
