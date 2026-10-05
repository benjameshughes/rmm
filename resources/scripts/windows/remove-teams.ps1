# Removes Microsoft Teams for every user and stops it coming back: the new
# Teams app (and the copy Windows provisions for new users), the classic
# machine-wide installer, and the Outlook meeting add-in. Office reinstalls
# Teams with its updates unless PreventTeamsInstall is set, so it is set here.
#
# The meeting add-in is installed per user, so uninstalling its MSI as
# SYSTEM fails with 1605; its folders are deleted from every profile instead,
# which stops Outlook loading it.

$removed = [System.Collections.Generic.List[string]]::new()

Get-Process -Name 'ms-teams', 'MSTeams', 'Teams' -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue

Get-AppxPackage -AllUsers -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -like 'MSTeams*' -or $_.Name -like 'MicrosoftTeams*' } |
    ForEach-Object {
        Remove-AppxPackage -Package $_.PackageFullName -AllUsers -ErrorAction SilentlyContinue
        $removed.Add("app $($_.Name)")
    }

Get-AppxProvisionedPackage -Online -ErrorAction SilentlyContinue |
    Where-Object { $_.DisplayName -like 'MSTeams*' -or $_.DisplayName -like 'MicrosoftTeams*' } |
    ForEach-Object {
        Remove-AppxProvisionedPackage -Online -PackageName $_.PackageName -ErrorAction SilentlyContinue | Out-Null
        $removed.Add("provisioned $($_.DisplayName)")
    }

# Win32_Product is avoided on purpose: querying it makes Windows Installer
# consistency-check (and sometimes repair) every MSI on the machine.
Get-ItemProperty -Path @(
        'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*',
        'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*'
    ) -ErrorAction SilentlyContinue |
    Where-Object { $_.DisplayName -like 'Teams Machine-Wide Installer*' -and $_.PSChildName -match '^\{[0-9A-Fa-f-]+\}$' } |
    ForEach-Object {
        $uninstall = Start-Process 'msiexec.exe' -ArgumentList "/x $($_.PSChildName) /qn /norestart" -Wait -PassThru
        $removed.Add("$($_.DisplayName) (exit $($uninstall.ExitCode))")
    }

Get-ChildItem -Path "$env:SystemDrive\Users" -Directory -ErrorAction SilentlyContinue |
    ForEach-Object { Join-Path $_.FullName 'AppData\Local\Microsoft\TeamsMeetingAdd-in' } |
    Where-Object { Test-Path $_ } |
    ForEach-Object {
        Remove-Item -Path $_ -Recurse -Force -ErrorAction SilentlyContinue
        $removed.Add("meeting add-in $_")
    }

New-Item -Path 'HKLM:\SOFTWARE\Policies\Microsoft\Office\16.0\Common\OfficeUpdate' -Force | Out-Null
Set-ItemProperty -Path 'HKLM:\SOFTWARE\Policies\Microsoft\Office\16.0\Common\OfficeUpdate' -Name 'PreventTeamsInstall' -Value 1 -Type DWord

New-Item -Path 'HKLM:\SOFTWARE\Policies\Microsoft\Windows\Windows Chat' -Force | Out-Null
Set-ItemProperty -Path 'HKLM:\SOFTWARE\Policies\Microsoft\Windows\Windows Chat' -Name 'ChatIcon' -Value 3 -Type DWord

$leftover = @(Get-AppxPackage -AllUsers -ErrorAction SilentlyContinue | Where-Object { $_.Name -like 'MSTeams*' -or $_.Name -like 'MicrosoftTeams*' })

if ($leftover.Count -gt 0) {
    Write-Output "ATTENTION: Teams is still installed: $($leftover.Name -join ', ')"
    exit 1
}

if ($removed.Count -eq 0) {
    Write-Output 'Teams was not installed. Reinstalling is blocked.'
} else {
    Write-Output 'Removed:'
    $removed | ForEach-Object { Write-Output "  $_" }
    Write-Output 'Reinstalling is blocked.'
}
exit 0
