use anyhow::{Context, Result};
use std::path::Path;
use tokio::fs;
use tracing::{debug, info};

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
        self.key_path.exists()
    }

    /// Read the stored API key
    pub async fn read_key(&self) -> Result<String> {
        debug!("Reading API key from {:?}", self.key_path);
        let key = fs::read_to_string(&self.key_path)
            .await
            .context("Failed to read API key file")?;
        Ok(key.trim().to_string())
    }

    /// Save the API key
    pub async fn save_key(&self, key: &str) -> Result<()> {
        info!("Saving API key to {:?}", self.key_path);

        // Ensure parent directory exists
        if let Some(parent) = self.key_path.parent() {
            fs::create_dir_all(parent)
                .await
                .context("Failed to create key file directory")?;
        }

        fs::write(&self.key_path, key.trim())
            .await
            .context("Failed to write API key file")?;

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
