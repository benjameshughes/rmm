# Deletes one file or folder the RMM was asked to remove, or moves it into
# quarantine under C:\ProgramData\RMM\Quarantine\<UTC time>-<id>, where
# purge-quarantine deletes it some days later. The path (RMM_Path), the mode
# (RMM_Mode: delete or quarantine) and what it must be (RMM_ExpectedKind:
# file, folder or any) arrive as parameters. The path is checked again here
# against the protected list, must exist, must be the expected kind and must
# not sit below a junction or symlink. A link itself is removed as a link.
#
# Prints OK: or ATTENTION: lines, then one JSON line (rmm.remove-path/1)
# with the bytes and files removed and up to 20 paths that would not go.

$path = "$env:RMM_Path".Trim()
$mode = "$env:RMM_Mode".Trim().ToLowerInvariant()
$expectedKind = "$env:RMM_ExpectedKind".Trim().ToLowerInvariant()

if ($path.Length -gt 3) {
    $path = $path.TrimEnd('\')
}

$result = [ordered]@{
    schema = 'rmm.remove-path/1'
    path = $path
    mode = $mode
    kind = $null
    removed = $false
    bytes = 0
    files = 0
    failed = @()
    quarantined_to = $null
    quarantine_folder = $null
    error = $null
}

# Prints the verdict and the JSON line, then ends the run.
function Stop-Removal([string] $message) {
    Write-Output "ATTENTION: $message"
    $result.error = $message
    Write-Output (ConvertTo-Json -InputObject $result -Compress)
    exit 1
}

if (@('delete', 'quarantine') -notcontains $mode) {
    Stop-Removal "mode must be delete or quarantine, not '$mode'. Nothing was deleted"
}

if (@('file', 'folder', 'any') -notcontains $expectedKind) {
    Stop-Removal "expected kind must be file, folder or any, not '$expectedKind'. Nothing was deleted"
}

$refusal = Get-PathRefusal $path
if ($refusal) {
    Stop-Removal "$refusal. Nothing was deleted"
}

$linkedAncestor = Get-LinkedAncestor $path
if ($linkedAncestor) {
    Stop-Removal "$linkedAncestor is a junction or symlink, so $path is not where it looks. Nothing was deleted"
}

$item = Get-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue
if (-not $item) {
    Stop-Removal "$path does not exist on this PC. Nothing was deleted"
}

$kind = if ($item.PSIsContainer) { 'folder' } else { 'file' }
$isLink = Test-IsLink $item
$result.kind = $kind

if ($expectedKind -ne 'any' -and $expectedKind -ne $kind) {
    Stop-Removal "$path is a $kind, not a $expectedKind. Nothing was deleted"
}

$contents = $null
$measuredBytes = [int64]0
$measuredFiles = 0

# A link is removed as a link, so nothing behind it is measured.
if (-not $isLink -and $kind -eq 'folder') {
    $contents = Get-FolderContents $path
    $measuredBytes = $contents.Bytes
    $measuredFiles = $contents.Files.Count
}
elseif (-not $isLink) {
    $measuredBytes = [int64]$item.Length
    $measuredFiles = 1
}

$what = if ($isLink) { "the $kind link $path (not what it points at)" } else { "$path ($(Format-Size $measuredBytes) in $('{0:N0}' -f $measuredFiles) files)" }

if ($mode -eq 'quarantine') {
    $driveOfPath = [IO.Path]::GetPathRoot($path)
    $driveOfQuarantine = [IO.Path]::GetPathRoot($quarantineRoot)

    if ($driveOfPath -ne $driveOfQuarantine) {
        Stop-Removal "quarantine only works on the system drive $driveOfQuarantine and $path is on $driveOfPath. Delete it permanently instead. Nothing was moved"
    }

    $lockError = Lock-QuarantineRoot
    if ($lockError) {
        Stop-Removal "$lockError. Nothing was moved"
    }

    $folderName = '{0}-{1}' -f (Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssZ'), [guid]::NewGuid().ToString('N').Substring(0, 8)
    $destination = Join-Path $quarantineRoot $folderName
    $target = Join-Path $destination $item.Name

    $createErrors = $null
    New-Item -ItemType Directory -Path $destination -ErrorAction SilentlyContinue -ErrorVariable createErrors | Out-Null
    if ($createErrors) {
        Stop-Removal "could not create $destination. Nothing was moved"
    }

    ConvertTo-Json -InputObject ([ordered]@{ path = $path; kind = $kind; bytes = $measuredBytes; files = $measuredFiles; quarantined_at = (Get-Date).ToUniversalTime().ToString('o') }) -Compress |
        Set-Content -LiteralPath (Join-Path $destination 'rmm-quarantine.json') -Encoding ASCII

    # A move within one drive is a rename: all or nothing, links moved as links.
    $moveErrors = $null
    Move-Item -LiteralPath $path -Destination $target -ErrorAction SilentlyContinue -ErrorVariable moveErrors

    if ($moveErrors -or (Get-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue)) {
        Remove-File (Join-Path $destination 'rmm-quarantine.json') | Out-Null
        Remove-EmptyFolder $destination | Out-Null
        Stop-Removal "could not move $path into quarantine, usually because a file in it is in use: $($moveErrors | Select-Object -First 1). Nothing was moved"
    }

    $result.removed = $true
    $result.bytes = $measuredBytes
    $result.files = $measuredFiles
    $result.quarantined_to = $target
    $result.quarantine_folder = $folderName

    Write-Output "OK: quarantined $what"
    Write-Output "OK: moved to $target; the space comes back once it is purged"
    Write-Output (ConvertTo-Json -InputObject $result -Compress)
    exit 0
}

$failed = New-Object System.Collections.Generic.List[string]

if ($isLink) {
    if (-not (Remove-Link $item)) { $failed.Add($path) }
}
elseif ($kind -eq 'folder') {
    $removal = Remove-FolderContents $contents
    $result.bytes = $removal.Bytes
    $result.files = $removal.Files
    $removal.Failed | ForEach-Object { $failed.Add($_) }
}
elseif (Remove-File $path) {
    $result.bytes = $measuredBytes
    $result.files = 1
}
else {
    $failed.Add($path)
}

$result.removed = -not (Get-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue)
$result.failed = @($failed | Select-Object -First $maxFailuresShown)

if ($result.removed) {
    Write-Output "OK: deleted $what"
}
else {
    Write-Output "OK: freed $(Format-Size $result.bytes) in $('{0:N0}' -f $result.files) files from $path"
}

Write-Failures $failed
Write-Output (ConvertTo-Json -InputObject $result -Compress)

if ($failed.Count -gt 0) {
    exit 1
}

exit 0
