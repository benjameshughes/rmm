# Shared USMT setup, put after restic.ps1 in front of each USMT script by the
# script sync, so Stop-Run and the locked C:\ProgramData\RMM folder come from
# there.
#
# USMT (scanstate, loadstate, usmtutils) lives in C:\ProgramData\RMM\<folder>,
# copied out of the Windows ADK. Before every run its tools must be owned by
# SYSTEM or Administrators and carry a valid Microsoft signature, or the
# folder is filled again from the ADK, installing the ADK's User State
# Migration Tool feature first when it is missing. adksetup.exe is only run
# once its own Microsoft signature checks out.
#
# The ADK URL, folder name, migration XML list and log level arrive as
# RMM_UsmtAdkUrl, RMM_UsmtFolder, RMM_UsmtXmlFiles (JSON) and
# RMM_UsmtVerbosity when the agent fetches the command.

$usmtAdkUrl = "$env:RMM_UsmtAdkUrl".Trim()
$usmtFolder = "$env:RMM_UsmtFolder".Trim()
$usmtVerbosity = "$env:RMM_UsmtVerbosity".Trim()
# Windows PowerShell 5.1 emits a JSON array as one object: assign it first,
# then wrap it, so the files arrive as separate items.
$parsedUsmtXmlFiles = "$env:RMM_UsmtXmlFiles" | ConvertFrom-Json
$usmtXmlFiles = @($parsedUsmtXmlFiles)
$usmtDir = Join-Path $rmmDir $usmtFolder
$scanstate = Join-Path $usmtDir 'scanstate.exe'
$loadstate = Join-Path $usmtDir 'loadstate.exe'
$usmtutils = Join-Path $usmtDir 'usmtutils.exe'
$usmtLogDir = Join-Path $rmmDir 'usmt-logs'
$usmtStoreRoot = Join-Path $rmmDir 'usmt-stores'
$adkUsmtDir = Join-Path ${env:ProgramFiles(x86)} 'Windows Kits\10\Assessment and Deployment Kit\User State Migration Tool\amd64'
$usmtWasInstalled = $false

# A user as USMT names one: DOMAIN\name, or name alone for a local account.
$migrationUserPattern = '^([A-Za-z0-9][A-Za-z0-9._-]{0,14}\\)?[A-Za-z0-9][A-Za-z0-9._-]{0,19}$'
$localUserPattern = '^[A-Za-z0-9][A-Za-z0-9._-]{0,19}$'

function Test-MicrosoftSigned([string] $path) {
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        return $false
    }

    $signature = Get-AuthenticodeSignature -LiteralPath $path

    return $signature.Status -eq 'Valid' -and "$($signature.SignerCertificate.Subject)" -match 'O=Microsoft Corporation'
}

function Test-UsmtTrusted {
    foreach ($tool in @($scanstate, $loadstate, $usmtutils)) {
        if (-not (Test-MicrosoftSigned $tool)) {
            return $false
        }

        if ($trustedOwners -notcontains (Get-Acl -LiteralPath $tool).GetOwner([Security.Principal.SecurityIdentifier]).Value) {
            return $false
        }
    }

    return $true
}

# Installs the ADK's USMT feature when it is missing, then copies its amd64
# folder over the RMM's. Returns why it failed, or nothing on success.
function Install-Usmt {
    if (-not (Test-Path -LiteralPath (Join-Path $adkUsmtDir 'scanstate.exe'))) {
        $setup = Join-Path $usmtDir 'adksetup.exe'
        Remove-Item -LiteralPath $setup -Force -ErrorAction SilentlyContinue

        foreach ($attempt in 1..3) {
            Invoke-WebRequest -Uri $usmtAdkUrl -OutFile $setup -UseBasicParsing -ErrorAction SilentlyContinue
            if (Test-MicrosoftSigned $setup) {
                break
            }
            Remove-Item -LiteralPath $setup -Force -ErrorAction SilentlyContinue
            Start-Sleep -Seconds (5 * $attempt)
        }

        if (-not (Test-Path -LiteralPath $setup)) {
            return 'could not download a Microsoft-signed adksetup.exe after 3 attempts. Check config/usmt.php'
        }

        $install = Start-Process -FilePath $setup -ArgumentList '/quiet /norestart /ceip off /features OptionId.UserStateMigrationTool' -Wait -PassThru
        Remove-Item -LiteralPath $setup -Force -ErrorAction SilentlyContinue

        if (@(0, 3010) -notcontains $install.ExitCode) {
            return "the Windows ADK setup failed with exit code $($install.ExitCode)"
        }

        if (-not (Test-Path -LiteralPath (Join-Path $adkUsmtDir 'scanstate.exe'))) {
            return "the Windows ADK installed but $adkUsmtDir has no scanstate.exe"
        }
    }

    robocopy.exe $adkUsmtDir $usmtDir /E /R:1 /W:1 /NP /NFL /NDL /NJH /NJS | Out-Null

    if ($LASTEXITCODE -ge 8) {
        return "could not copy USMT from $adkUsmtDir (robocopy exit $LASTEXITCODE)"
    }

    icacls.exe $usmtDir /setowner '*S-1-5-18' /T /C /L /Q | Out-Null

    if (-not (Test-UsmtTrusted)) {
        return 'USMT was copied from the ADK but its tools do not carry a valid Microsoft signature'
    }
}

# Makes sure trusted USMT tools are in place, installing them when needed,
# or ends the run. Call it as a statement, never assigned.
function Use-Usmt {
    if (-not $usmtAdkUrl -or $usmtFolder -notmatch '^[A-Za-z0-9_-]{1,32}$' -or $usmtVerbosity -notmatch '^([0-9]|1[0-3])$') {
        Stop-Run 'the server did not send the USMT settings. Update the agent and check config/usmt.php'
    }

    if ($usmtXmlFiles.Count -eq 0 -or ($usmtXmlFiles | Where-Object { "$_" -notmatch '^[A-Za-z0-9_-]{1,64}\.xml$' })) {
        Stop-Run 'the RMM sent no usable list of USMT migration XML files'
    }

    $lockProblem = Lock-ResticFolder
    if ($lockProblem) {
        Stop-Run $lockProblem
    }

    foreach ($dir in @($usmtDir, $usmtLogDir, $usmtStoreRoot)) {
        if ((Test-Path -LiteralPath $dir) -and ((Get-Item -LiteralPath $dir -Force).Attributes -band [IO.FileAttributes]::ReparsePoint)) {
            Stop-Run "$dir is a link, not a folder. Nothing was run; delete the link and run again"
        }

        New-Item -ItemType Directory -Path $dir -Force | Out-Null
        # Inherit the locked RMM folder's SYSTEM and Administrators only ACL.
        icacls.exe $dir /reset /C /L /Q | Out-Null
    }

    if (Test-UsmtTrusted) {
        return
    }

    $installProblem = Install-Usmt
    if ($installProblem) {
        Stop-Run $installProblem
    }

    $script:usmtWasInstalled = $true
}

# The /i: rule files, each of which must be in the USMT folder.
function Get-UsmtXmlArguments {
    return @($usmtXmlFiles | ForEach-Object {
            $xml = Join-Path $usmtDir "$_"
            if (-not (Test-Path -LiteralPath $xml -PathType Leaf)) {
                Stop-Run "USMT has no $_ in $usmtDir"
            }
            "/i:$xml"
        })
}

# DOMAIN\name as given, or this PC's name in front of a bare local name.
function ConvertTo-MigrationUser([string] $name) {
    if ($name.Contains('\')) {
        return $name
    }

    return "$env:COMPUTERNAME\$name"
}

# SYSTEM sees every session, so the owners of explorer.exe are the people
# signed in at the console or over RDP.
function Test-UserSignedIn([string] $qualifiedName) {
    $signedIn = @(Get-CimInstance -ClassName Win32_Process -Filter "Name = 'explorer.exe'" -ErrorAction SilentlyContinue | ForEach-Object {
            $owner = Invoke-CimMethod -InputObject $_ -MethodName GetOwner -ErrorAction SilentlyContinue
            if ($owner -and $owner.User) {
                "$($owner.Domain)\$($owner.User)"
            }
        })

    $userPart = $qualifiedName.Split('\')[-1]

    return [bool]($signedIn | Where-Object { $_ -ieq $qualifiedName -or ($qualifiedName.StartsWith("$env:COMPUTERNAME\", 'OrdinalIgnoreCase') -and $_.Split('\')[-1] -ieq $userPart) })
}

# The last lines of a USMT log that say something went wrong.
function Get-UsmtLogErrors([string] $log, [int] $limit = 15) {
    if (-not (Test-Path -LiteralPath $log)) {
        return @()
    }

    return @(Get-Content -LiteralPath $log -ErrorAction SilentlyContinue |
            Where-Object { $_ -match '\b(Error|Fatal|failed)\b' } |
            Select-Object -Last $limit |
            ForEach-Object { "$_".Trim() })
}

function Get-UsmtExitMeaning([string] $tool, [int] $exitCode) {
    if ($exitCode -eq 0) {
        return "$tool finished"
    }

    return "$tool failed with exit code $exitCode; its log lines are below"
}
