# Measures what fills a drive or folder with the agent's own `rmm du`, run
# from the service's registered path like update-agent: services only see
# PATH changes after a reboot, and a PATH lookup as SYSTEM could be hijacked.
# The folder and depth arrive as parameters, the space-hog folders to keep as
# their own entries from the server (RMM_DiskUsageKeep, split on |). Prints a
# couple of OK: lines, then the scan as one line of JSON for the Storage tab.
# Reads only; changes nothing.

$path = if ([string]::IsNullOrWhiteSpace($env:RMM_Path)) { 'C:\' } else { $env:RMM_Path.Trim() }
$depthText = if ([string]::IsNullOrWhiteSpace($env:RMM_Depth)) { '4' } else { $env:RMM_Depth.Trim() }
$depth = 0

if ($path -notmatch '^[A-Za-z]:\\[^"*?<>|\x00-\x1F]*$') {
    Write-Output "ATTENTION: '$path' is not a drive or folder such as C:\ or C:\Users"
    exit 1
}

if (-not [int]::TryParse($depthText, [ref]$depth) -or $depth -lt 1 -or $depth -gt 6) {
    Write-Output "ATTENTION: depth must be a whole number from 1 to 6, not '$depthText'"
    exit 1
}

# A quoted argument ending in a backslash would escape its closing quote.
if ($path.Length -gt 3) {
    $path = $path.TrimEnd('\')
}

if (-not (Test-Path -LiteralPath $path -PathType Container)) {
    Write-Output "ATTENTION: $path does not exist on this PC"
    exit 1
}

$service = Get-CimInstance Win32_Service -Filter "Name='BenJHRMM'"

if (-not $service) {
    Write-Output 'ATTENTION: the RMM agent service is not installed'
    exit 1
}

$exe = $service.PathName.Trim('"')
$keep = @($env:RMM_DiskUsageKeep -split '\|' | Where-Object { $_ } | ForEach-Object { '--keep'; $_ })
$arguments = @('du', $path, '--json', '--depth', "$depth") + $keep

[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding $false
$lines = @(& $exe @arguments)
$exitCode = $LASTEXITCODE

if ($exitCode -eq 2) {
    Write-Output 'ATTENTION: this agent is too old for disk scans; update the agent'
    exit 1
}

# Exit 1 is a partial scan: some folders could not be read, which is normal
# on a whole drive. Its JSON is still the result.
$json = $lines | Where-Object { "$_".TrimStart().StartsWith('{') } | Select-Object -First 1

if (-not $json -or $exitCode -gt 1) {
    $lines | Write-Output
    Write-Output "ATTENTION: the disk scan of $path failed with exit code $exitCode and printed no result"
    exit 1
}

$seconds = if ($json -match '"duration_ms":(\d+)') { [math]::Round([int64]$Matches[1] / 1000) } else { '?' }
$size = if ($json -match '"totals":\{[^}]*"allocated":(\d+)') { '{0:N1} GB' -f ([int64]$Matches[1] / 1GB) } else { 'an unknown amount' }
$files = if ($json -match '"totals":\{[^}]*"files":(\d+)') { '{0:N0}' -f [int64]$Matches[1] } else { 'unknown' }
$unreadable = if ($json -match '"errors":\{"count":(\d+)') { [int64]$Matches[1] } else { 0 }
$partial = if ($exitCode -eq 1) { ' (partial scan)' } else { '' }

Write-Output "OK: scanned $path to depth $depth in ${seconds}s"
Write-Output "OK: $size in $files files, $unreadable folders could not be read$partial"
Write-Output $json.Trim()
exit 0
