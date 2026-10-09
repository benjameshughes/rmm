# Shared by remove-path, purge-quarantine and restore-quarantine, put in
# front of each by the script sync.
#
# Nothing here ever follows a junction or symlink. Folders are walked one
# level at a time with Get-ChildItem (never -Recurse), a link is removed as
# the link itself with a non-recursive Directory.Delete and never entered,
# and Remove-Item never gets -Recurse: in Windows PowerShell 5.1 that can
# walk through a junction and delete what it points at.
#
# The protected paths arrive from the server as JSON in
# RMM_DeletePathProtected when the agent fetches the command, the same list
# the server checked. Without it nothing is deleted.
#
# Windows PowerShell 5.1, ASCII only, no try/catch: failures are read from
# -ErrorVariable and by looking again.

$ProgressPreference = 'SilentlyContinue'
Remove-TypeData -TypeName System.Array -ErrorAction SilentlyContinue

$rmmDir = Join-Path $env:ProgramData 'RMM'
$quarantineRoot = Join-Path $rmmDir 'Quarantine'
$quarantineFolderPattern = '^\d{8}T\d{6}Z-[0-9a-f]{8}$'
$maxFailuresShown = 20
$trustedOwners = @('S-1-5-18', 'S-1-5-32-544')

function Test-IsLink($item) {
    return [bool]($item.Attributes -band [IO.FileAttributes]::ReparsePoint)
}

function Format-Size([int64] $bytes) {
    if ($bytes -ge 1GB) { return '{0:N1} GB' -f ($bytes / 1GB) }
    if ($bytes -ge 1MB) { return '{0:N1} MB' -f ($bytes / 1MB) }
    return '{0:N0} KB' -f ($bytes / 1KB)
}

# Whether a path below a drive root (such as users\anna) matches a pattern
# from the protected list, where * is one folder name.
function Test-PathPattern([string] $pattern, [string] $fromDrive) {
    $regex = '^' + ([regex]::Escape($pattern.ToLowerInvariant()) -replace '\\\*', '[^\\]*') + '$'
    return $fromDrive.ToLowerInvariant() -match $regex
}

# Why a path must not be deleted, or nothing when it may be. The same rules
# as the server: one plain absolute path on a local drive, outside every
# protected folder, plus a floor of folders refused even if the list were
# ever wrong.
function Get-PathRefusal([string] $path) {
    if ($path -notmatch '^[A-Za-z]:\\') { return "'$path' is not a full path on a local drive" }
    if ($path -match '[*?"<>|\x00-\x1F]' -or $path.Substring(2) -match ':') { return "'$path' contains wildcards or characters a path cannot have" }
    if ($path -match '%[^%]*%' -or $path -match '\$\{?env:') { return "'$path' contains a variable" }

    $fromDrive = $path.Substring(3).TrimEnd('\')
    if ($fromDrive -eq '') { return "$path is a whole drive" }

    $segments = @($fromDrive -split '\\')
    foreach ($segment in $segments) {
        if ($segment -eq '' -or $segment -eq '.' -or $segment -eq '..') { return "'$path' contains empty, . or .. folders" }
        if ($segment -match '~\d') { return "'$path' uses a short 8.3 name" }
        if ($segment.EndsWith('.') -or $segment.EndsWith(' ')) { return "'$path' has a name ending in a dot or space" }
    }

    $floor = @($env:SystemRoot, $env:ProgramFiles, ${env:ProgramFiles(x86)}, $rmmDir) | Where-Object { $_ }
    foreach ($folder in $floor) {
        if ($path -eq $folder -or $path.StartsWith($folder.TrimEnd('\') + '\', [StringComparison]::OrdinalIgnoreCase)) { return "$path is protected" }
    }

    $protected = $null
    if ("$env:RMM_DeletePathProtected".Trim()) { $protected = "$env:RMM_DeletePathProtected" | ConvertFrom-Json }
    if (-not $protected -or @($protected.trees).Count -eq 0) { return 'the protected path list did not arrive from the server' }

    for ($depth = 1; $depth -le $segments.Count; $depth++) {
        $ancestor = $segments[0..($depth - 1)] -join '\'
        foreach ($tree in @($protected.trees)) {
            if (Test-PathPattern $tree $ancestor) { return "$path is protected" }
        }
    }

    foreach ($pattern in @($protected.exact) + @($protected.root_files)) {
        if (Test-PathPattern $pattern $fromDrive) { return "$path is protected" }
    }
}

# The first folder above the path that is a junction or symlink, so the
# path would really point somewhere else. Nothing when there is none.
function Get-LinkedAncestor([string] $path) {
    $segments = @($path.Substring(3).TrimEnd('\') -split '\\')
    for ($depth = 1; $depth -lt $segments.Count; $depth++) {
        $ancestor = $path.Substring(0, 3) + ($segments[0..($depth - 1)] -join '\')
        $item = Get-Item -LiteralPath $ancestor -Force -ErrorAction SilentlyContinue
        if ($item -and (Test-IsLink $item)) { return $ancestor }
    }
}

# Everything below a folder, walked one level at a time. Links are listed,
# never entered. Folders come parents first.
function Get-FolderContents([string] $folder) {
    $contents = [pscustomobject]@{
        Files = New-Object System.Collections.Generic.List[object]
        Links = New-Object System.Collections.Generic.List[object]
        Folders = New-Object System.Collections.Generic.List[string]
        Bytes = [int64]0
    }
    $pending = New-Object System.Collections.Generic.Stack[string]
    $pending.Push($folder)

    while ($pending.Count -gt 0) {
        $current = $pending.Pop()
        $contents.Folders.Add($current)

        foreach ($child in @(Get-ChildItem -LiteralPath $current -Force -ErrorAction SilentlyContinue)) {
            if (Test-IsLink $child) {
                $contents.Links.Add($child)
            }
            elseif ($child.PSIsContainer) {
                $pending.Push($child.FullName)
            }
            else {
                $contents.Files.Add($child)
                $contents.Bytes += $child.Length
            }
        }
    }

    return $contents
}

# Removes an empty folder, or a folder link as the link itself: a
# non-recursive delete never touches what a junction points at.
function Remove-EmptyFolder([string] $path) {
    $ErrorActionPreference = 'SilentlyContinue'
    [System.IO.Directory]::Delete($path, $false)
    return -not (Get-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue)
}

# Removes one file, or a file link as the link itself.
function Remove-File([string] $path) {
    $removeErrors = $null
    Remove-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue -ErrorVariable removeErrors
    return -not $removeErrors -and -not (Get-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue)
}

# Removes a link of either kind without following it.
function Remove-Link($item) {
    if ($item.PSIsContainer) { return Remove-EmptyFolder $item.FullName }
    return Remove-File $item.FullName
}

# Deletes what Get-FolderContents found: files, then links, then folders
# deepest first, so each folder is empty when its turn comes. Returns the
# bytes and files removed and every path that would not go.
function Remove-FolderContents($contents) {
    $removal = [pscustomobject]@{
        Bytes = [int64]0
        Files = 0
        Failed = New-Object System.Collections.Generic.List[string]
    }

    foreach ($file in $contents.Files) {
        if (Remove-File $file.FullName) {
            $removal.Bytes += $file.Length
            $removal.Files++
        }
        else {
            $removal.Failed.Add($file.FullName)
        }
    }

    foreach ($link in $contents.Links) {
        if (-not (Remove-Link $link)) { $removal.Failed.Add($link.FullName) }
    }

    for ($index = $contents.Folders.Count - 1; $index -ge 0; $index--) {
        $folder = $contents.Folders[$index]
        if (-not (Remove-EmptyFolder $folder)) { $removal.Failed.Add($folder) }
    }

    return $removal
}

# Prints the paths that would not go, at most $maxFailuresShown of them.
function Write-Failures($failed) {
    if ($failed.Count -eq 0) { return }
    Write-Output "ATTENTION: $($failed.Count) files or folders could not be deleted (in use or access denied):"
    $failed | Select-Object -First $maxFailuresShown | ForEach-Object { Write-Output "ATTENTION:   $_" }
}

# Gives C:\ProgramData\RMM and its Quarantine folder a fresh ACL owned by
# SYSTEM with full control for SYSTEM and Administrators only, as the
# restic setup does, so quarantined files are out of users' reach and a
# folder or link a user planted there first is never used. Returns why it
# failed, or nothing.
function Lock-QuarantineRoot {
    foreach ($dir in @($rmmDir, $quarantineRoot)) {
        $existing = Get-Item -LiteralPath $dir -Force -ErrorAction SilentlyContinue
        if ($existing -and (Test-IsLink $existing)) { return "$dir is a link, not a folder. Delete the link and run again" }
        if (-not $existing) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }

        $acl = New-Object System.Security.AccessControl.DirectorySecurity
        $acl.SetAccessRuleProtection($true, $false)
        $acl.SetOwner((New-Object Security.Principal.SecurityIdentifier 'S-1-5-18'))
        foreach ($sid in $trustedOwners) {
            $acl.AddAccessRule((New-Object System.Security.AccessControl.FileSystemAccessRule((New-Object Security.Principal.SecurityIdentifier $sid), 'FullControl', 'ContainerInherit, ObjectInherit', 'None', 'Allow')))
        }
        Set-Acl -LiteralPath $dir -AclObject $acl -ErrorAction SilentlyContinue

        $current = Get-Acl -LiteralPath $dir
        $strangers = @($current.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier]) | Where-Object { $trustedOwners -notcontains $_.IdentityReference.Value })
        if (-not $current.AreAccessRulesProtected -or $strangers.Count -gt 0) { return "could not lock $dir to SYSTEM and Administrators" }
    }
}
