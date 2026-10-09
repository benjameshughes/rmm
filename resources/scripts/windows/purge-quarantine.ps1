# Deletes what remove-path put in C:\ProgramData\RMM\Quarantine: every
# quarantine folder older than RMM_Days days by the UTC time in its name, or
# only the one named in RMM_Folder whatever its age. Folders are deleted
# the same way remove-path deletes, never following a junction or symlink.
#
# Prints OK: or ATTENTION: lines, then one JSON line
# (rmm.purge-quarantine/1) naming each folder purged and each that would
# not go.

$daysText = "$env:RMM_Days".Trim()
$only = "$env:RMM_Folder".Trim()
$days = 0

$result = [ordered]@{
    schema = 'rmm.purge-quarantine/1'
    purged = @()
    failed = @()
    kept = 0
}

function Stop-Purge([string] $message) {
    Write-Output "ATTENTION: $message"
    Write-Output (ConvertTo-Json -InputObject $result -Compress)
    exit 1
}

if (-not [int]::TryParse($daysText, [ref]$days) -or $days -lt 0) {
    Stop-Purge "days must be a whole number of 0 or more, not '$daysText'. Nothing was purged"
}

if ($only -and $only -notmatch $quarantineFolderPattern) {
    Stop-Purge "'$only' is not a quarantine folder name. Nothing was purged"
}

$root = Get-Item -LiteralPath $quarantineRoot -Force -ErrorAction SilentlyContinue

if (-not $root) {
    Write-Output 'OK: nothing is quarantined on this PC'
    Write-Output (ConvertTo-Json -InputObject $result -Compress)
    exit 0
}

if (Test-IsLink $root) {
    Stop-Purge "$quarantineRoot is a link, not a folder. Nothing was purged"
}

$cutoff = (Get-Date).ToUniversalTime().AddDays(-$days)
$purged = New-Object System.Collections.Generic.List[object]
$failed = New-Object System.Collections.Generic.List[string]
$freed = [int64]0

foreach ($folder in @(Get-ChildItem -LiteralPath $quarantineRoot -Force -Directory -ErrorAction SilentlyContinue)) {
    if ($folder.Name -notmatch $quarantineFolderPattern -or (Test-IsLink $folder)) {
        continue
    }

    $quarantinedAt = [datetime]::ParseExact($folder.Name.Substring(0, 16), "yyyyMMdd'T'HHmmss'Z'", [Globalization.CultureInfo]::InvariantCulture, [Globalization.DateTimeStyles]'AssumeUniversal, AdjustToUniversal')
    $isDue = if ($only) { $folder.Name -eq $only } else { $quarantinedAt -le $cutoff }

    if (-not $isDue) {
        $result.kept++
        continue
    }

    $removal = Remove-FolderContents (Get-FolderContents $folder.FullName)
    $freed += $removal.Bytes

    if ($removal.Failed.Count -gt 0) {
        $failed.Add($folder.Name)
        Write-Failures $removal.Failed
        continue
    }

    $purged.Add([ordered]@{ folder = $folder.Name; bytes = $removal.Bytes; files = $removal.Files })
}

$result.purged = @($purged)
$result.failed = @($failed)

Write-Output "OK: purged $($purged.Count) quarantined items, freeing $(Format-Size $freed); $($result.kept) kept"

if ($only -and $purged.Count -eq 0 -and $failed.Count -eq 0) {
    Write-Output "ATTENTION: $only is no longer in quarantine"
}

Write-Output (ConvertTo-Json -InputObject $result -Compress -Depth 4)

if ($failed.Count -gt 0) {
    exit 1
}

exit 0
