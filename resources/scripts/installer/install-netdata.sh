#!/usr/bin/env bash
# ===================================================================
#  Installs the one pinned Netdata release the Linux agent reads its
#  metrics from, so every machine reports the same metric shapes.
#  - Native Debian/Ubuntu package, held so apt never upgrades it
#  - Listens on 127.0.0.1 only, anonymous statistics off
#  - Never claimed to Netdata Cloud
#  A machine already running the pinned version is left alone, keeping
#  its history. The agent installer runs this before installing the agent;
#  it is safe to run again on its own as root.
# ===================================================================

set -euo pipefail

NETDATA_VERSION="2.12.0"
CONFIG_DIR="/etc/netdata"
CONFIG_FILE="${CONFIG_DIR}/netdata.conf"
OPT_OUT_FILE="${CONFIG_DIR}/.opt-out-from-anonymous-statistics"
DESIRED_CONFIG='[web]
    bind to = 127.0.0.1'

fail() {
    echo "Error: $*" >&2
    exit 1
}

[ "$(id -u)" -eq 0 ] || fail "run this as root"
command -v apt-get >/dev/null 2>&1 || fail "only Debian and Ubuntu are supported"

installed="$(dpkg-query -W -f='${db:Status-Abbrev}${Version}' netdata 2>/dev/null || true)"

case "$installed" in
    "ii ${NETDATA_VERSION}"*)
        echo "Netdata ${NETDATA_VERSION} is already installed"
        ;;
    *)
        command -v wget >/dev/null 2>&1 || { apt-get update -qq && apt-get install -y -qq wget; }

        # Pin every netdata* package (core, plugins, dashboard) to the one
        # release; otherwise apt pairs the pinned core with newer plugins that
        # depend on a newer core, and the install fails.
        printf 'Package: netdata*\nPin: version %s*\nPin-Priority: 1001\n' "${NETDATA_VERSION}" > /etc/apt/preferences.d/netdata

        echo "Installing Netdata ${NETDATA_VERSION}..."
        wget -O /tmp/nd-kickstart.sh https://get.netdata.cloud/kickstart.sh && sh /tmp/nd-kickstart.sh --non-interactive --stable-channel --native-only --install-version "${NETDATA_VERSION}" --no-updates --disable-telemetry
        rm -f /tmp/nd-kickstart.sh
        apt-mark hold netdata
        ;;
esac

mkdir -p "$CONFIG_DIR"
touch "$OPT_OUT_FILE"

config_changed=0
if [ ! -f "$CONFIG_FILE" ] || [ "$(cat "$CONFIG_FILE")" != "$DESIRED_CONFIG" ]; then
    printf '%s\n' "$DESIRED_CONFIG" > "$CONFIG_FILE"
    config_changed=1
fi

systemctl enable --now netdata >/dev/null 2>&1 || fail "the netdata service could not be started"

if [ "$config_changed" -eq 1 ]; then
    echo "Restricting Netdata to 127.0.0.1"
    systemctl restart netdata
fi

for _ in $(seq 1 15); do
    if (exec 3<>/dev/tcp/127.0.0.1/19999) 2>/dev/null; then
        echo "Netdata ${NETDATA_VERSION} is running on 127.0.0.1:19999"
        exit 0
    fi
    sleep 1
done

fail "Netdata ${NETDATA_VERSION} is installed but not listening on 127.0.0.1:19999"
