# Shared Zebra setup, put in front of install-zebra and capture-zebra-template
# by the script sync.
#
# Files (the driver download, a saved template) live in
# C:\ProgramData\RMM\zebra, a folder locked to SYSTEM and Administrators and
# owned by SYSTEM, never a shared temp folder. ProgramData lets any user
# create folders, so a folder a user made first is taken over, and a link
# there is refused.
#
# Windows PowerShell 5.1, ASCII only, no try/catch. Every failure goes through
# Stop-Run, so the last line printed is always one JSON object.

[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$ProgressPreference = 'SilentlyContinue'
Remove-TypeData -TypeName System.Array -ErrorAction SilentlyContinue

$rmmDir = Join-Path $env:ProgramData 'RMM'
$zebraDir = Join-Path $rmmDir 'zebra'
$zebraDriverPrefix = "$env:RMM_ZebraDriverPrefix".Trim()
$trustedOwners = @('S-1-5-18', 'S-1-5-32-544')

# Prints the verdict and the JSON result line, then ends the run.
function Stop-Run([string] $message, [int] $exitCode = 1) {
    Write-Output "ATTENTION: $message"
    Write-Output (ConvertTo-Json -InputObject @{ status = 'failed'; exit_code = $exitCode; errors = @($message) } -Compress)
    exit $exitCode
}

# Gives the folders a fresh ACL: owned by SYSTEM, inheritance cut, full
# control for SYSTEM and Administrators only. Returns why it failed, or nothing.
function Lock-ZebraFolder {
    foreach ($dir in @($rmmDir, $zebraDir)) {
        if (Test-Path -LiteralPath $dir) {
            if ((Get-Item -LiteralPath $dir -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) {
                return "$dir is a link, not a folder. Nothing was changed; delete the link and run again"
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

# Locks the folder or ends the run. Call it as a statement.
function Use-ZebraFolder {
    $lockProblem = Lock-ZebraFolder
    if ($lockProblem) {
        Stop-Run $lockProblem
    }
}

function Test-ZDesignerDriver([string] $driverName) {
    return [bool]$zebraDriverPrefix -and $driverName -like "$zebraDriverPrefix*"
}

# Runs printui synchronously and quietly, so no error box ever waits for a
# click on the SYSTEM desktop. Returns its exit code.
function Invoke-PrintUi([string[]] $arguments) {
    $quoted = @('printui.dll,PrintUIEntry') + @($arguments | ForEach-Object { if ($_ -match '\s') { "`"$_`"" } else { $_ } })
    $run = Start-Process -FilePath (Join-Path $env:SystemRoot 'System32\rundll32.exe') -ArgumentList ($quoted -join ' ') -Wait -PassThru -WindowStyle Hidden
    return $run.ExitCode
}
