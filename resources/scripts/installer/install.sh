#!/usr/bin/env bash
# ===================================================================
#  BenJH RMM Linux Agent Installer (monitor only)
#  - Installs the pinned Netdata release the agent reads its metrics from
#  - Downloads the latest rmm-linux-x86_64 release and verifies its checksum
#  - Installs it to /usr/local/bin/rmm and registers it with this panel
#  The Linux agent is read-only: it reports metrics and never runs commands.
#  Served by {BASE_URL}/agent/install.sh
#  Usage: curl -fsSL {BASE_URL}/agent/install.sh | sudo bash
#  Re-running it upgrades the agent in place.
# ===================================================================

set -euo pipefail

SERVER_URL="{BASE_URL}"
GITHUB_REPO="benjameshughes/rmm"
ASSET="rmm-linux-x86_64"
INSTALL_PATH="/usr/local/bin/rmm"
DOWNLOAD_BASE="https://github.com/${GITHUB_REPO}/releases/latest/download"

fail() {
    echo "Error: $*" >&2
    exit 1
}

[ "$(id -u)" -eq 0 ] || fail "run this as root, for example: curl -fsSL ${SERVER_URL}/agent/install.sh | sudo bash"

ARCH="$(uname -m)"
[ "$ARCH" = "x86_64" ] || fail "only x86_64 is supported, this machine is ${ARCH}"

command -v curl >/dev/null 2>&1 || fail "curl is required"
command -v sha256sum >/dev/null 2>&1 || fail "sha256sum is required (coreutils)"

WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

# The panel inlines install-netdata.sh here, so there is one copy of it.
cat > "${WORK_DIR}/install-netdata.sh" <<'NETDATA_INSTALLER'
{NETDATA_INSTALLER}
NETDATA_INSTALLER
bash "${WORK_DIR}/install-netdata.sh"

echo "Downloading ${ASSET} from the latest ${GITHUB_REPO} release..."
curl -fsSL -o "${WORK_DIR}/${ASSET}" "${DOWNLOAD_BASE}/${ASSET}"
curl -fsSL -o "${WORK_DIR}/${ASSET}.sha256" "${DOWNLOAD_BASE}/${ASSET}.sha256"

echo "Verifying checksum..."
(cd "$WORK_DIR" && sha256sum -c "${ASSET}.sha256") || fail "checksum verification failed, nothing was installed"

# Renaming into place swaps the binary atomically, so an upgrade never
# leaves a half-written file and works while the old agent is running.
install -o root -g root -m 0755 "${WORK_DIR}/${ASSET}" "${INSTALL_PATH}.new"
mv -f "${INSTALL_PATH}.new" "${INSTALL_PATH}"
echo "Installed ${INSTALL_PATH}"

"${INSTALL_PATH}" --url "${SERVER_URL}" install

# Starting an already running service is a no-op, so an upgrade would leave
# the old agent running until the next reboot; restart onto the new binary.
systemctl restart benjh-rmm

echo
echo "Done. Next steps:"
echo "  1. Open ${SERVER_URL}/devices/pending and approve $(hostname)."
echo "  2. Metrics appear on the device page within a minute of approval."
echo "This agent is monitor only: the panel can never run commands on this machine."
