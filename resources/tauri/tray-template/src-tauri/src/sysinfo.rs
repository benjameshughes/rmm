use anyhow::Result;
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use sysinfo::{CpuRefreshKind, Disks, Networks, RefreshKind, System};
use tracing::debug;

/// System information for device enrollment
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct SystemInfo {
    pub hostname: String,
    pub os_name: String,
    pub os_version: String,
    pub cpu_model: String,
    pub cpu_cores: usize,
    pub total_ram_bytes: u64,
    pub total_ram_gb: f64,
    pub disks: Vec<DiskInfo>,
    pub network_interfaces: Vec<NetworkInterface>,
    pub hardware_fingerprint: String,
}

/// Disk information
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct DiskInfo {
    pub name: String,
    pub mount_point: String,
    pub total_bytes: u64,
    pub available_bytes: u64,
    pub total_gb: f64,
    pub available_gb: f64,
}

/// Network interface information
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct NetworkInterface {
    pub name: String,
    pub mac_address: String,
    pub ip_addresses: Vec<String>,
}

impl SystemInfo {
    /// Gather system information
    pub fn gather() -> Result<Self> {
        debug!("Gathering system information");

        let mut sys = System::new_with_specifics(
            RefreshKind::new()
                .with_cpu(CpuRefreshKind::everything())
                .with_memory(sysinfo::MemoryRefreshKind::everything()),
        );
        sys.refresh_all();

        // Get hostname
        let hostname = System::host_name().unwrap_or_else(|| "unknown".to_string());

        // Get OS information
        let os_name = System::name().unwrap_or_else(|| "Unknown OS".to_string());
        let os_version = System::os_version().unwrap_or_else(|| "Unknown".to_string());

        // Get CPU information
        let cpu_model = sys
            .cpus()
            .first()
            .map(|cpu| cpu.brand().to_string())
            .unwrap_or_else(|| "Unknown CPU".to_string());
        let cpu_cores = sys.cpus().len();

        // Get memory information
        let total_ram_bytes = sys.total_memory();
        let total_ram_gb = total_ram_bytes as f64 / 1024.0 / 1024.0 / 1024.0;

        // Get disk information
        let disks = Disks::new_with_refreshed_list();
        let disk_info: Vec<DiskInfo> = disks
            .iter()
            .map(|disk| {
                let total_bytes = disk.total_space();
                let available_bytes = disk.available_space();
                DiskInfo {
                    name: disk.name().to_string_lossy().to_string(),
                    mount_point: disk.mount_point().to_string_lossy().to_string(),
                    total_bytes,
                    available_bytes,
                    total_gb: total_bytes as f64 / 1024.0 / 1024.0 / 1024.0,
                    available_gb: available_bytes as f64 / 1024.0 / 1024.0 / 1024.0,
                }
            })
            .collect();

        // Get network interface information
        let networks = Networks::new_with_refreshed_list();
        let network_interfaces: Vec<NetworkInterface> = networks
            .iter()
            .map(|(interface_name, data)| NetworkInterface {
                name: interface_name.clone(),
                mac_address: data.mac_address().to_string(),
                ip_addresses: vec![], // IP addresses not directly available via sysinfo
            })
            .collect();

        // Generate hardware fingerprint
        let hardware_fingerprint = Self::generate_fingerprint(&hostname, &cpu_model, cpu_cores);

        debug!(
            "System info gathered: {} - {} {} - {} cores - {:.2} GB RAM - {} disks - {} network interfaces",
            hostname,
            os_name,
            os_version,
            cpu_cores,
            total_ram_gb,
            disk_info.len(),
            network_interfaces.len()
        );

        Ok(SystemInfo {
            hostname,
            os_name,
            os_version,
            cpu_model,
            cpu_cores,
            total_ram_bytes,
            total_ram_gb,
            disks: disk_info,
            network_interfaces,
            hardware_fingerprint,
        })
    }

    /// Generate a unique hardware fingerprint
    fn generate_fingerprint(hostname: &str, cpu_model: &str, cpu_cores: usize) -> String {
        let mut hasher = Sha256::new();
        hasher.update(hostname.as_bytes());
        hasher.update(cpu_model.as_bytes());
        hasher.update(cpu_cores.to_string().as_bytes());

        // Add MAC address if available
        #[cfg(target_os = "windows")]
        {
            if let Ok(output) = std::process::Command::new("getmac")
                .arg("/fo")
                .arg("csv")
                .arg("/nh")
                .output()
            {
                hasher.update(&output.stdout);
            }
        }

        #[cfg(target_os = "macos")]
        {
            if let Ok(output) = std::process::Command::new("ifconfig")
                .arg("en0")
                .output()
            {
                hasher.update(&output.stdout);
            }
        }

        #[cfg(target_os = "linux")]
        {
            if let Ok(output) = std::process::Command::new("cat")
                .arg("/sys/class/net/eth0/address")
                .output()
            {
                hasher.update(&output.stdout);
            }
        }

        let result = hasher.finalize();
        hex::encode(result)
    }

    /// Get a summary string for display
    pub fn summary(&self) -> String {
        format!(
            "{} - {} {} - {} cores - {:.1} GB RAM - {} interfaces",
            self.hostname, self.os_name, self.os_version, self.cpu_cores, self.total_ram_gb, self.network_interfaces.len()
        )
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_gather_system_info() {
        let info = SystemInfo::gather().unwrap();
        assert!(!info.hostname.is_empty());
        assert!(!info.os_name.is_empty());
        assert!(info.cpu_cores > 0);
        assert!(info.total_ram_bytes > 0);
        assert!(!info.hardware_fingerprint.is_empty());
        assert_eq!(info.hardware_fingerprint.len(), 64); // SHA256 hex = 64 chars
    }

    #[test]
    fn test_fingerprint_consistency() {
        let info1 = SystemInfo::gather().unwrap();
        let info2 = SystemInfo::gather().unwrap();
        assert_eq!(info1.hardware_fingerprint, info2.hardware_fingerprint);
    }
}
