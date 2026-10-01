# Runs the installed agent's own updater, which downloads and installs the
# latest release, then restarts the service about two seconds later from a
# detached process. That restart usually cuts this command off before it can
# report back, so the command often ends as Timed Out: the "Update available"
# badge disappearing is the real confirmation.

$serviceName = 'BenJHRMM'

$service = Get-CimInstance -ClassName Win32_Service -Filter "Name='$serviceName'"

if (-not $service) {
    Write-Output "The $serviceName service is not installed on this computer."
    exit 1
}

# PathName is the quoted or unquoted executable, optionally followed by arguments.
if ($service.PathName -match '^\s*"([^"]+)"') {
    $exe = $Matches[1]
} elseif ($service.PathName -match '^\s*(.+?\.exe)') {
    $exe = $Matches[1]
} else {
    Write-Output "Could not find the agent executable in the service path: $($service.PathName)"
    exit 1
}

if (-not (Test-Path -LiteralPath $exe)) {
    Write-Output "The agent executable does not exist: $exe"
    exit 1
}

Write-Output "Updating the agent at $exe..."
& $exe update
exit $LASTEXITCODE
