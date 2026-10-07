# Installs the pinned restic release the file backups use, or replaces a copy
# that is the wrong version or no longer matches its sha256. A PC that already
# has the pinned build is left alone. The backup scripts do the same before
# each run, so this is only needed to get a PC ready ahead of its first one.

Use-Restic

$verdict = if ($resticWasInstalled) { "OK: restic $resticVersion installed" } else { "OK: restic $resticVersion is already installed" }
Write-Output $verdict
Write-Output (ConvertTo-Json -InputObject @{ status = 'ok'; exit_code = 0; restic_version = $resticVersion; installed = $resticWasInstalled } -Compress)
exit 0
