use anyhow::{Context, Result};
use std::path::Path;
use tokio::fs;
use tracing::{debug, info};

#[cfg(unix)]
use std::os::unix::fs::PermissionsExt;

#[cfg(windows)]
use winapi::um::dpapi::{CryptProtectData, CryptUnprotectData};
#[cfg(windows)]
use winapi::um::wincrypt::CRYPTOAPI_BLOB;
#[cfg(windows)]
use std::ptr;
#[cfg(windows)]
use base64::{Engine as _, engine::general_purpose};

/// Encrypt data using Windows DPAPI
#[cfg(windows)]
fn encrypt_dpapi(data: &[u8]) -> Result<Vec<u8>> {
    const CRYPTPROTECT_LOCAL_MACHINE: u32 = 0x04;

    unsafe {
        let mut input_blob = CRYPTOAPI_BLOB {
            cbData: data.len() as u32,
            pbData: data.as_ptr() as *mut u8,
        };

        let mut output_blob = CRYPTOAPI_BLOB {
            cbData: 0,
            pbData: ptr::null_mut(),
        };

        let result = CryptProtectData(
            &mut input_blob,
            ptr::null(),      // Description
            ptr::null_mut(),  // Optional entropy
            ptr::null_mut(),  // Reserved
            ptr::null_mut(),  // Prompt struct
            CRYPTPROTECT_LOCAL_MACHINE,  // Flags - use machine-wide encryption
            &mut output_blob,
        );

        if result == 0 {
            anyhow::bail!("DPAPI encryption failed");
        }

        let encrypted = std::slice::from_raw_parts(output_blob.pbData, output_blob.cbData as usize).to_vec();

        // Free the blob allocated by CryptProtectData
        winapi::um::winbase::LocalFree(output_blob.pbData as *mut _);

        Ok(encrypted)
    }
}

/// Decrypt data using Windows DPAPI
#[cfg(windows)]
fn decrypt_dpapi(encrypted_data: &[u8]) -> Result<Vec<u8>> {
    const CRYPTPROTECT_LOCAL_MACHINE: u32 = 0x04;

    unsafe {
        let mut input_blob = CRYPTOAPI_BLOB {
            cbData: encrypted_data.len() as u32,
            pbData: encrypted_data.as_ptr() as *mut u8,
        };

        let mut output_blob = CRYPTOAPI_BLOB {
            cbData: 0,
            pbData: ptr::null_mut(),
        };

        let result = CryptUnprotectData(
            &mut input_blob,
            ptr::null_mut(),  // Description
            ptr::null_mut(),  // Optional entropy
            ptr::null_mut(),  // Reserved
            ptr::null_mut(),  // Prompt struct
            CRYPTPROTECT_LOCAL_MACHINE,  // Flags - use machine-wide decryption
            &mut output_blob,
        );

        if result == 0 {
            anyhow::bail!("DPAPI decryption failed");
        }

        let decrypted = std::slice::from_raw_parts(output_blob.pbData, output_blob.cbData as usize).to_vec();

        // Free the blob allocated by CryptUnprotectData
        winapi::um::winbase::LocalFree(output_blob.pbData as *mut _);

        Ok(decrypted)
    }
}

/// Storage manager for API key
pub struct Storage {
    key_path: std::path::PathBuf,
}

impl Storage {
    /// Create a new storage manager
    pub fn new(key_path: impl AsRef<Path>) -> Self {
        Self {
            key_path: key_path.as_ref().to_path_buf(),
        }
    }

    /// Check if API key exists
    pub async fn has_key(&self) -> bool {
        // Use tokio's async metadata check instead of blocking exists()
        fs::metadata(&self.key_path).await.is_ok()
    }

    /// Read the stored API key
    pub async fn read_key(&self) -> Result<String> {
        debug!("Reading API key from {:?}", self.key_path);

        #[cfg(windows)]
        {
            // On Windows, read base64-encoded encrypted data and decrypt with DPAPI
            let encrypted_b64 = fs::read_to_string(&self.key_path)
                .await
                .context("Failed to read API key file")?;

            let encrypted = general_purpose::STANDARD.decode(encrypted_b64.trim())
                .context("Failed to decode base64 encrypted data")?;

            let decrypted = decrypt_dpapi(&encrypted)
                .context("Failed to decrypt API key with DPAPI")?;

            let key = String::from_utf8(decrypted)
                .context("Decrypted data is not valid UTF-8")?;

            Ok(key.trim().to_string())
        }

        #[cfg(not(windows))]
        {
            // On Unix, read plaintext key
            let key = fs::read_to_string(&self.key_path)
                .await
                .context("Failed to read API key file")?;
            Ok(key.trim().to_string())
        }
    }

    /// Save the API key with secure file permissions
    pub async fn save_key(&self, key: &str) -> Result<()> {
        info!("Saving API key to {:?}", self.key_path);

        // Ensure parent directory exists
        if let Some(parent) = self.key_path.parent() {
            fs::create_dir_all(parent)
                .await
                .context("Failed to create key file directory")?;
        }

        #[cfg(windows)]
        {
            // On Windows, encrypt with DPAPI before writing
            let encrypted = encrypt_dpapi(key.trim().as_bytes())
                .context("Failed to encrypt API key with DPAPI")?;

            let encrypted_b64 = general_purpose::STANDARD.encode(&encrypted);

            fs::write(&self.key_path, encrypted_b64)
                .await
                .context("Failed to write encrypted API key file")?;

            debug!("API key encrypted with DPAPI and saved");
        }

        #[cfg(not(windows))]
        {
            // On Unix, write plaintext key
            fs::write(&self.key_path, key.trim())
                .await
                .context("Failed to write API key file")?;

            // Set secure file permissions (owner read/write only - 0o600)
            let metadata = fs::metadata(&self.key_path)
                .await
                .context("Failed to read key file metadata")?;
            let mut permissions = metadata.permissions();
            permissions.set_mode(0o600);
            fs::set_permissions(&self.key_path, permissions)
                .await
                .context("Failed to set secure permissions on key file")?;
            debug!("Set key file permissions to 0600 (owner read/write only)");
        }

        info!("API key saved successfully");
        Ok(())
    }

    /// Delete the stored API key
    pub async fn delete_key(&self) -> Result<()> {
        if self.has_key().await {
            info!("Deleting API key from {:?}", self.key_path);
            fs::remove_file(&self.key_path)
                .await
                .context("Failed to delete API key file")?;
        }
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use tempfile::NamedTempFile;

    #[tokio::test]
    async fn test_save_and_read_key() {
        let temp_file = NamedTempFile::new().unwrap();
        let storage = Storage::new(temp_file.path());

        let test_key = "test-api-key-12345";
        storage.save_key(test_key).await.unwrap();

        assert!(storage.has_key().await);
        let read_key = storage.read_key().await.unwrap();
        assert_eq!(read_key, test_key);
    }

    #[tokio::test]
    async fn test_delete_key() {
        let temp_file = NamedTempFile::new().unwrap();
        let storage = Storage::new(temp_file.path());

        storage.save_key("test-key").await.unwrap();
        assert!(storage.has_key().await);

        storage.delete_key().await.unwrap();
        assert!(!storage.has_key().await);
    }
}
