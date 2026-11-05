# Makes a local user the owner of a file or folder and everything under it,
# and grants them full control that inherits down. Handy after restores or
# copies made as SYSTEM, when a person's own files throw access denied.
# Goes by SID, so a renamed account still matches.

$path = "$env:RMM_Path".Trim()
$username = "$env:RMM_Username".Trim()

function Stop-Fix([string] $message) {
    Write-Output "ATTENTION: $message"
    exit 1
}

trap { Stop-Fix "Could not fix ownership: $($_.Exception.Message)" }

if ($path -eq '') { Stop-Fix 'No path was given' }
if ($username -eq '') { Stop-Fix 'No username was given' }
if (-not (Test-Path -LiteralPath $path)) { Stop-Fix "$path does not exist on this PC" }

$user = Get-LocalUser -Name $username -ErrorAction SilentlyContinue
if ($null -eq $user) { Stop-Fix "$username is not a local account on this PC" }

$sid = $user.SID.Value

icacls.exe $path /setowner "*$sid" /T /C /Q | Out-Null
if ($LASTEXITCODE -ne 0) { Stop-Fix "Could not make $username the owner of $path (icacls exit $LASTEXITCODE)" }

icacls.exe $path /grant "*${sid}:(OI)(CI)F" /T /C /Q | Out-Null
if ($LASTEXITCODE -ne 0) { Stop-Fix "Made $username the owner of $path but could not grant full control (icacls exit $LASTEXITCODE)" }

Write-Output "OK: $username now owns $path and everything in it, with full control"
