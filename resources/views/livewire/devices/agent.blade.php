<div class="space-y-6">
    <flux:heading size="xl">Agent Installer</flux:heading>
    <flux:separator variant="subtle" />

    <div class="space-y-4">
        <flux:text>
            Use the one-liner below on a Windows device (elevated PowerShell) to install the RMM agent.
        </flux:text>

        <div class="rounded border p-4 text-sm font-mono break-all space-y-2">
            <div>
                <div class="text-xs mb-1 text-muted-foreground">One-liner (PowerShell, Run as Administrator)</div>
                <div>iwr -useb {{ $downloadUrl }} | iex</div>
            </div>
            <div>
                <div class="text-xs mb-1 text-muted-foreground">If TLS 1.2 is required (older Windows)</div>
                <div>[System.Net.ServicePointManager]::SecurityProtocol=[System.Net.SecurityProtocolType]::Tls12; iwr -useb {{ $downloadUrl }} | iex</div>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <flux:button as="a" href="{{ $downloadUrl }}">Download agent-install.ps1</flux:button>
            <flux:text variant="subtle">or copy the one-liner above</flux:text>
        </div>

        <div class="flex items-center gap-3">
            <flux:button as="a" href="{{ route('agent.tauri.download') }}">Download Tauri Tray Scaffold (ZIP)</flux:button>
            <flux:text variant="subtle">Starter project for a Windows tray app</flux:text>
        </div>

        <flux:callout variant="subtle" icon="information-circle" heading="Notes">
            <ul class="list-disc ms-4">
                <li>Requires internet access to download Netdata.</li>
                <li>Creates a scheduled task that runs every 1 minute.</li>
                <li>Agent sends metrics to this panel over HTTPS.</li>
            </ul>
        </flux:callout>
    </div>

    <flux:separator variant="subtle" />

    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <flux:heading size="lg">Linux (monitor only)</flux:heading>
            <flux:badge size="sm" color="zinc" icon="eye">Read-only</flux:badge>
        </div>

        <flux:text>
            Run this as root on an x86_64 Linux server or LXC. It downloads the latest agent, checks its SHA-256 checksum, installs it to /usr/local/bin/rmm and registers it with this panel. Run it again to upgrade.
        </flux:text>

        <flux:input :value="$linuxInstallCommand" readonly copyable input:class="font-mono" aria-label="Linux install command" />

        <flux:callout variant="subtle" icon="eye" heading="Monitor only">
            <flux:callout.text>The Linux agent reports metrics and disk usage and never runs commands. The panel refuses to queue commands, scripts or wake packets for it, whatever the agent reports.</flux:callout.text>
        </flux:callout>
    </div>
</div>
