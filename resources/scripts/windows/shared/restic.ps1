# Shared restic setup, put in front of each backup script by the script sync.
#
# restic lives in C:\ProgramData\RMM\restic, a folder locked to SYSTEM and
# Administrators and owned by SYSTEM. ProgramData lets any user create
# folders, so a folder a user made first is taken over, and a link there is
# refused. Before every run restic.exe must be owned by SYSTEM or
# Administrators and match the pinned sha256, or it is replaced with the
# pinned release: nothing a user plants there is ever run as SYSTEM. The
# download lands in the locked folder too, never in a shared temp folder.
#
# The pin (RMM_ResticVersion, RMM_ResticDownloadUrl, RMM_ResticSha256,
# RMM_ResticExeSha256) and any backup credentials arrive as environment
# variables only when the agent fetches the command. They are never printed.
#
# Windows PowerShell 5.1, ASCII only, no try/catch. Every failure goes through
# Stop-Run, so the last line printed is always one JSON object.

[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$ProgressPreference = 'SilentlyContinue'
Remove-TypeData -TypeName System.Array -ErrorAction SilentlyContinue

$rmmDir = Join-Path $env:ProgramData 'RMM'
$resticDir = Join-Path $rmmDir 'restic'
$restic = Join-Path $resticDir 'restic.exe'
$resticCacheDir = Join-Path $resticDir 'cache'
$resticCaCertPath = Join-Path $resticDir 'ca.pem'
$resticVersion = "$env:RMM_ResticVersion".Trim()
$resticDownloadUrl = "$env:RMM_ResticDownloadUrl".Trim()
$resticZipSha256 = "$env:RMM_ResticSha256".Trim().ToLowerInvariant()
$resticExeSha256 = "$env:RMM_ResticExeSha256".Trim().ToLowerInvariant()
$trustedOwners = @('S-1-5-18', 'S-1-5-32-544')
$resticWasInstalled = $false
$resticArguments = @()

# Prints the verdict and the JSON result line, then ends the run.
function Stop-Run([string] $message, [int] $exitCode = 1) {
    Write-Output "ATTENTION: $message"
    Write-Output (ConvertTo-Json -InputObject @{ status = 'failed'; exit_code = $exitCode; errors = @($message) } -Compress)
    exit $exitCode
}

# Gives the folders a fresh ACL: owned by SYSTEM, inheritance cut, full
# control for SYSTEM and Administrators only, so any rule a user added to a
# folder they created first is gone. Returns why it failed, or nothing.
function Lock-ResticFolder {
    foreach ($dir in @($rmmDir, $resticDir)) {
        if (Test-Path -LiteralPath $dir) {
            if ((Get-Item -LiteralPath $dir -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) {
                return "$dir is a link, not a folder. Nothing was run; delete the link and run again"
            }
        }
        else {
            New-Item -ItemType Directory -Path $dir -Force | Out-Null
        }

        $acl = New-Object System.Security.AccessControl.DirectorySecurity
        $acl.SetAccessRuleProtection($true, $false)
        $acl.SetOwner((New-Object Security.Principal.SecurityIdentifier 'S-1-5-18'))
        foreach ($sid in $trustedOwners) {
            $acl.AddAccessRule((New-Object System.Security.AccessControl.FileSystemAccessRule((New-Object Security.Principal.SecurityIdentifier $sid), 'FullControl', 'ContainerInherit, ObjectInherit', 'None', 'Allow')))
        }
        Set-Acl -LiteralPath $dir -AclObject $acl -ErrorAction SilentlyContinue

        $current = Get-Acl -LiteralPath $dir
        $strangers = @($current.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier]) | Where-Object { $trustedOwners -notcontains $_.IdentityReference.Value })

        if (-not $current.AreAccessRulesProtected -or $strangers.Count -gt 0 -or $trustedOwners -notcontains $current.GetOwner([Security.Principal.SecurityIdentifier]).Value) {
            return "could not lock $dir to SYSTEM and Administrators"
        }
    }
}

function Get-FileSha256([string] $path) {
    return (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()
}

function Test-ResticTrusted {
    if (-not (Test-Path -LiteralPath $restic -PathType Leaf)) {
        return $false
    }

    $owner = (Get-Acl -LiteralPath $restic).GetOwner([Security.Principal.SecurityIdentifier]).Value

    return ($trustedOwners -contains $owner) -and ((Get-FileSha256 $restic) -eq $resticExeSha256)
}

# Downloads the pinned zip into the locked folder, checks both hashes and
# swaps restic.exe in. Returns why it failed, or nothing on success.
function Install-Restic {
    $zip = Join-Path $resticDir 'download.zip'
    $extractDir = Join-Path $resticDir 'download'
    Remove-Item -LiteralPath $zip -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $extractDir -Recurse -Force -ErrorAction SilentlyContinue

    foreach ($attempt in 1..3) {
        Invoke-WebRequest -Uri $resticDownloadUrl -OutFile $zip -UseBasicParsing -ErrorAction SilentlyContinue
        if ((Test-Path -LiteralPath $zip) -and (Get-FileSha256 $zip) -eq $resticZipSha256) {
            break
        }
        Remove-Item -LiteralPath $zip -Force -ErrorAction SilentlyContinue
        Start-Sleep -Seconds (5 * $attempt)
    }

    if (-not (Test-Path -LiteralPath $zip)) {
        return "could not download restic $resticVersion with the pinned sha256 after 3 attempts"
    }

    Expand-Archive -LiteralPath $zip -DestinationPath $extractDir -Force
    $exe = Get-ChildItem -LiteralPath $extractDir -Filter 'restic*.exe' -File | Select-Object -First 1
    $isExeGood = $null -ne $exe -and (Get-FileSha256 $exe.FullName) -eq $resticExeSha256

    if ($isExeGood) {
        Remove-Item -LiteralPath $restic -Force -ErrorAction SilentlyContinue
        Move-Item -LiteralPath $exe.FullName -Destination $restic -Force
    }

    Remove-Item -LiteralPath $zip -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $extractDir -Recurse -Force -ErrorAction SilentlyContinue

    if (-not $isExeGood) {
        return "restic.exe inside the restic $resticVersion zip does not match the pinned exe sha256"
    }

    if (-not (Test-ResticTrusted)) {
        return "restic $resticVersion was installed but does not check out afterwards"
    }
}

# Makes sure the pinned, untampered restic is in place, installing or
# replacing it when needed, or ends the run. Call it as a statement, never
# assigned, so a Stop-Run inside still prints.
function Use-Restic {
    if (-not $resticVersion -or -not $resticDownloadUrl -or $resticZipSha256 -notmatch '^[0-9a-f]{64}$' -or $resticExeSha256 -notmatch '^[0-9a-f]{64}$') {
        Stop-Run 'the server did not send the restic version and sha256 pins. Update the agent and check config/backup.php'
    }

    $lockProblem = Lock-ResticFolder
    if ($lockProblem) {
        Stop-Run $lockProblem
    }

    if (Test-ResticTrusted) {
        return
    }

    $installProblem = Install-Restic
    if ($installProblem) {
        Stop-Run $installProblem
    }

    $script:resticWasInstalled = $true
}

# Points restic at this run's repository from the injected credentials, the
# backup server URL and its CA certificate when one is set, and leaves the
# arguments every restic call needs in $resticArguments. Call it as a
# statement after Use-Restic, which makes the folder the certificate goes in.
function Set-ResticRepository {
    $restUrl = "$env:RMM_RestUrl".Trim().TrimEnd('/')
    $restUser = "$env:RMM_RestUser".Trim()

    if (-not $restUrl) {
        Stop-Run 'no backup server is set in the RMM. Set BACKUP_REST_URL on the server'
    }

    if ($restUser -notmatch '^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$') {
        Stop-Run 'the backup username from the RMM is not valid. Set it again on the Backups tab'
    }

    $env:RESTIC_REPOSITORY = "rest:$restUrl/$restUser/"
    $env:RESTIC_REST_USERNAME = $restUser
    $env:RESTIC_REST_PASSWORD = $env:RMM_RestPassword
    $env:RESTIC_PASSWORD = $env:RMM_ResticPassword
    $env:RESTIC_PROGRESS_FPS = '0.0166'
    $env:GOMAXPROCS = '2'

    $script:resticArguments = @('--cache-dir', $resticCacheDir)

    if ("$env:RMM_RestCaCert".Trim()) {
        [IO.File]::WriteAllText($resticCaCertPath, "$env:RMM_RestCaCert".Trim() + "`n", (New-Object Text.UTF8Encoding $false))
        $script:resticArguments += @('--cacert', $resticCaCertPath)
    }
}

function Test-BackupCredentials {
    return [bool]("$env:RMM_RestUser".Trim() -and "$env:RMM_RestPassword" -and "$env:RMM_ResticPassword")
}

# Runs restic at below-normal priority, keeping its JSON lines (stdout) and
# its error lines (stderr) apart. Progress lines are dropped as they arrive.
function Invoke-Restic([string[]] $arguments) {
    $stdout = New-Object System.Collections.Generic.List[string]
    $stderr = New-Object System.Collections.Generic.List[string]
    (Get-Process -Id $PID).PriorityClass = 'BelowNormal'

    & $restic @arguments 2>&1 | ForEach-Object {
        if ($_ -is [System.Management.Automation.ErrorRecord]) {
            $stderr.Add($_.ToString())
        }
        elseif ("$_" -notmatch '^\{"message_type":"(status|verbose_status)"') {
            $stdout.Add("$_")
        }
    }

    return @{ ExitCode = $LASTEXITCODE; Stdout = $stdout; Stderr = $stderr }
}

# restic's errors as short lines, "path: message" when it names a file.
function Get-ResticErrors($lines, [int] $limit = 20) {
    return @($lines | Where-Object { "$_".Trim() } | Select-Object -First $limit | ForEach-Object {
            $line = "$_".Trim()
            if ($line.StartsWith('{"message_type":"error"')) {
                $parsed = $line | ConvertFrom-Json
                $message = if ($parsed.error.message) { $parsed.error.message } else { "$($parsed.error)" }
                if ($parsed.item) { "$($parsed.item): $message" } else { $message }
            }
            else {
                $line
            }
        })
}

# What a restic exit code means, for the verdict line.
function Get-ResticExitMeaning([int] $exitCode) {
    switch ($exitCode) {
        10 { return "the repository for $env:RMM_RestUser does not exist on the backup server. Onboard this PC on scarif: add its rest-server user and run restic init for it" }
        11 { return 'the repository is locked by another restic run. It clears by itself; run again later' }
        12 { return 'the repository password is wrong. Set it again on the Backups tab' }
        130 { return 'restic was cancelled' }
        default { return "restic failed with exit code $exitCode" }
    }
}
