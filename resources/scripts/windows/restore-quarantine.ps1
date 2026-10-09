# Moves one quarantined file or folder (RMM_Folder, the quarantine folder
# remove-path made) back to where it came from (RMM_Path). Refuses when
# anything exists at that path again, when its parent folder is gone, or
# when the path is protected. A move within the drive is a rename, so links
# go back as links.
#
# Prints OK: or ATTENTION: lines, then one JSON line
# (rmm.restore-quarantine/1).

$folder = "$env:RMM_Folder".Trim()
$path = "$env:RMM_Path".Trim()

if ($path.Length -gt 3) {
    $path = $path.TrimEnd('\')
}

$result = [ordered]@{
    schema = 'rmm.restore-quarantine/1'
    folder = $folder
    path = $path
    restored = $false
    error = $null
}

function Stop-Restore([string] $message) {
    Write-Output "ATTENTION: $message"
    $result.error = $message
    Write-Output (ConvertTo-Json -InputObject $result -Compress)
    exit 1
}

if ($folder -notmatch $quarantineFolderPattern) {
    Stop-Restore "'$folder' is not a quarantine folder name. Nothing was restored"
}

$refusal = Get-PathRefusal $path
if ($refusal) {
    Stop-Restore "$refusal. Nothing was restored"
}

$linkedAncestor = Get-LinkedAncestor $path
if ($linkedAncestor) {
    Stop-Restore "$linkedAncestor is a junction or symlink, so $path is not where it looks. Nothing was restored"
}

$root = Get-Item -LiteralPath $quarantineRoot -Force -ErrorAction SilentlyContinue
if (-not $root -or (Test-IsLink $root)) {
    Stop-Restore "$quarantineRoot is missing or a link. Nothing was restored"
}

$holder = Join-Path $quarantineRoot $folder
$source = Join-Path $holder ([IO.Path]::GetFileName($path))

if (-not (Get-Item -LiteralPath $source -Force -ErrorAction SilentlyContinue)) {
    Stop-Restore "$source is no longer in quarantine. Nothing was restored"
}

if (Get-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue) {
    Stop-Restore "$path exists again. Move or delete what is there first. Nothing was restored"
}

$parent = [IO.Path]::GetDirectoryName($path)
if (-not (Test-Path -LiteralPath $parent -PathType Container)) {
    Stop-Restore "$parent no longer exists. Nothing was restored"
}

$moveErrors = $null
Move-Item -LiteralPath $source -Destination $path -ErrorAction SilentlyContinue -ErrorVariable moveErrors

if ($moveErrors -or -not (Get-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue)) {
    Stop-Restore "could not move $source back to ${path}: $($moveErrors | Select-Object -First 1)"
}

Remove-File (Join-Path $holder 'rmm-quarantine.json') | Out-Null
Remove-EmptyFolder $holder | Out-Null

$result.restored = $true

Write-Output "OK: restored $path from quarantine"
Write-Output (ConvertTo-Json -InputObject $result -Compress)
exit 0
