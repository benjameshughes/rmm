# The agent runs as SYSTEM in session 0, so a bare `logoff` would target the
# service session. Each interactive session is logged off by its ID instead.

$sessionIds = quser 2>$null |
    Select-Object -Skip 1 |
    Where-Object { $_ -match '\s(\d+)\s+(Active|Disc)' } |
    ForEach-Object { $Matches[1] }

if (-not $sessionIds) {
    Write-Output 'No users are logged in.'
    exit 0
}

$sessionIds | ForEach-Object {
    logoff $_
    Write-Output "Logged off session $_"
}
