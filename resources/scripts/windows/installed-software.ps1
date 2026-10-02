# Reads the uninstall keys rather than Win32_Product, which is slow and makes
# MSI run a consistency check (sometimes a repair) on every package. Running as
# SYSTEM, per-user installs only show for users whose hive is loaded, which in
# practice means users who are signed in. Hidden system components and patches
# of other products are skipped, as Programs and Features does.

$uninstallPaths = @(
    'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*',
    'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*',
    'Registry::HKEY_USERS\*\Software\Microsoft\Windows\CurrentVersion\Uninstall\*'
)

$applications = @(Get-ItemProperty -Path $uninstallPaths -ErrorAction SilentlyContinue |
    Where-Object { $_.DisplayName -and $_.SystemComponent -ne 1 -and -not $_.ParentKeyName } |
    ForEach-Object {
        $installDate = "$($_.InstallDate)".Trim()
        if ($installDate -match '^(\d{4})(\d{2})(\d{2})$') {
            $installDate = "$($Matches[1])-$($Matches[2])-$($Matches[3])"
        }

        [PSCustomObject]@{
            Name = "$($_.DisplayName)".Trim()
            Version = "$($_.DisplayVersion)".Trim()
            Publisher = "$($_.Publisher)".Trim()
            InstallDate = $installDate
        }
    } |
    Group-Object Name, Version |
    ForEach-Object { $_.Group[0] } |
    Sort-Object Name)

if ($applications.Count -eq 1) {
    Write-Output '1 application installed'
} else {
    Write-Output "$($applications.Count) applications installed"
}

if ($applications.Count -gt 0) {
    Write-Output ''
    Write-Output ($applications | Format-Table Name, Version, Publisher, InstallDate -AutoSize | Out-String -Width 4096).Trim()
}

exit 0
