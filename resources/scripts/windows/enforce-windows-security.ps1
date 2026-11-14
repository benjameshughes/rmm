# Puts the PC's baseline protection back on: UAC, Defender real-time
# protection (plus cloud protection and potentially unwanted app blocking),
# fresh Defender signatures and Windows Firewall on every profile.
#
# Group Policy values that switch Defender off are removed first, because
# Windows ignores Set-MpPreference while they are there. If another antivirus
# is registered, Defender stays passive and the script says which one.
#
# UAC changes only take effect after a restart.
#
# Safe to run on a schedule: it sets the same values every time.
#
# Prints OK: or ATTENTION: first.

$problems = [System.Collections.Generic.List[string]]::new()
$restartNeeded = $false

$uacKey = 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System'
$uacSettings = [ordered]@{ EnableLUA = 1; ConsentPromptBehaviorAdmin = 5; PromptOnSecureDesktop = 1 }

foreach ($name in $uacSettings.Keys) {
    $current = (Get-ItemProperty -Path $uacKey -Name $name -ErrorAction SilentlyContinue).$name

    if ($current -eq $uacSettings[$name]) {
        continue
    }

    Set-ItemProperty -Path $uacKey -Name $name -Value $uacSettings[$name] -Type DWord -ErrorAction SilentlyContinue

    if (-not $?) {
        $problems.Add("could not set UAC $name")
    } elseif ($name -eq 'EnableLUA') {
        $restartNeeded = $true
    }
}

$policyKeys = @(
    @{ Path = 'HKLM:\SOFTWARE\Policies\Microsoft\Windows Defender'; Names = @('DisableAntiSpyware', 'DisableAntiVirus') },
    @{ Path = 'HKLM:\SOFTWARE\Policies\Microsoft\Windows Defender\Real-Time Protection'; Names = @('DisableRealtimeMonitoring', 'DisableBehaviorMonitoring', 'DisableOnAccessProtection', 'DisableIOAVProtection') }
)

foreach ($policy in $policyKeys) {
    $policy.Names | ForEach-Object { Remove-ItemProperty -Path $policy.Path -Name $_ -ErrorAction SilentlyContinue }
}

Set-Service -Name WinDefend -StartupType Automatic -ErrorAction SilentlyContinue
Start-Service -Name WinDefend -ErrorAction SilentlyContinue

Set-MpPreference -DisableRealtimeMonitoring $false -DisableBehaviorMonitoring $false -DisableIOAVProtection $false `
    -MAPSReporting Advanced -SubmitSamplesConsent SendSafeSamples -PUAProtection Enabled -ErrorAction SilentlyContinue

if (-not $?) {
    $problems.Add('Defender refused the settings (Tamper Protection or another antivirus)')
}

Update-MpSignature -ErrorAction SilentlyContinue

if (-not $?) {
    $problems.Add('could not update Defender signatures')
}

$otherAntivirus = @(Get-CimInstance -Namespace root/SecurityCenter2 -ClassName AntiVirusProduct -ErrorAction SilentlyContinue |
    Where-Object { $_.displayName -notmatch 'Windows Defender|Microsoft Defender' } |
    ForEach-Object { $_.displayName })

$status = Get-MpComputerStatus -ErrorAction SilentlyContinue

if (-not $status.RealTimeProtectionEnabled) {
    $reason = if ($otherAntivirus.Count -gt 0) { "another antivirus is installed: $($otherAntivirus -join ', ')" } else { 'still off after enabling' }
    $problems.Add("Defender real-time protection is off ($reason)")
}

Set-NetFirewallProfile -Profile Domain, Private, Public -Enabled True -ErrorAction SilentlyContinue

$firewallOff = @(Get-NetFirewallProfile -ErrorAction SilentlyContinue | Where-Object { -not $_.Enabled } | ForEach-Object { $_.Name })

if ($firewallOff.Count -gt 0) {
    $problems.Add("firewall still off on: $($firewallOff -join ', ')")
}

if ($problems.Count -gt 0) {
    Write-Output "ATTENTION: $($problems -join '; ')"
    exit 1
}

$summary = 'UAC on, Defender real-time protection on with fresh signatures, firewall on for every profile'

if ($restartNeeded) {
    $summary += '. UAC was off: restart to apply it'
}

Write-Output "OK: $summary"
exit 0
