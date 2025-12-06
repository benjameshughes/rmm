use anyhow::{Context, Result};
use std::path::Path;
use tokio::fs;
use tracing::{debug, info};

#[cfg(unix)]
use std::os::unix::fs::PermissionsExt;

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
        let key = fs::read_to_string(&self.key_path)
            .await
            .context("Failed to read API key file")?;
        Ok(key.trim().to_string())
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

        // Write the API key file
        fs::write(&self.key_path, key.trim())
            .await
            .context("Failed to write API key file")?;

        // Set secure file permissions (owner read/write only - 0o600)
        #[cfg(unix)]
        {
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

        // On Windows, file permissions work differently (ACLs)
        // The default behavior is generally secure for user-specific files
        #[cfg(windows)]
        {
            debug!("Windows detected - relying on default user file ACLs for security");
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
