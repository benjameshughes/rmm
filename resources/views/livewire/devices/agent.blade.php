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

        <flux:callout variant="subtle" icon="information-circle" heading="Notes">
            <ul class="list-disc ms-4">
                <li>Requires internet access to download Netdata.</li>
                <li>Creates a scheduled task that runs every 1 minute.</li>
                <li>Agent sends metrics to this panel over HTTPS.</li>
            </ul>
        </flux:callout>
    </div>
</div>
