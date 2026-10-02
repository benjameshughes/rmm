# Scans the System and Security logs for signs of trouble, meant to run on a
# schedule. Exits 1 when, within the last $lookbackHours:
#   - any blue screen, unexpected shutdown, disk or NTFS error was logged, or
#   - failed logons exceed $failedLogonThreshold (a few typos are normal), or
#   - a log could not be read, since a check that cannot see is no check.
# Get-WinEvent throws when nothing matches, so queries are silenced and only
# errors other than "no events found" count as unreadable.

$lookbackHours = 24
$failedLogonThreshold = 10
$recentPerCheck = 5
$maxEventsPerCheck = 5000

$ErrorActionPreference = 'Stop'

trap {
    Write-Output "ATTENTION: event log check failed: $($_.Exception.Message)"
    exit 1
}

function Format-Count([int] $count, [string] $singular, [string] $plural, [bool] $capped) {
    $label = if ($count -eq 1) { $singular } else { $plural }
    $suffix = if ($capped) { '+' } else { '' }
    return "$count$suffix $label"
}

function Get-FirstLine($record) {
    $message = "$($record.Message)".Trim()
    if (-not $message) {
        return "(no message, event ID $($record.Id))"
    }

    $line = ($message -split "`r?`n")[0].Trim()
    if ($line.Length -gt 160) {
        $line = $line.Substring(0, 157) + '...'
    }

    return $line
}

function Get-FailedLogonLine($record) {
    # 4625 properties: 5 = target user, 6 = target domain, 10 = logon type, 19 = source IP
    $user = $record.Properties[5].Value
    $domain = $record.Properties[6].Value
    $logonType = $record.Properties[10].Value
    $address = $record.Properties[19].Value

    if ($domain -and $domain -ne '-') {
        $user = "$domain\$user"
    }

    if (-not $address -or $address -eq '-') {
        $address = 'local'
    }

    return "$user from $address (logon type $logonType)"
}

$since = (Get-Date).AddHours(-$lookbackHours)

$checks = @(
    @{ Singular = 'blue screen'; Plural = 'blue screens'; Log = 'System'; Ids = @(1001); Provider = '^(Microsoft-Windows-WER-SystemErrorReporting|BugCheck)$'; Threshold = 0 },
    @{ Singular = 'unexpected shutdown'; Plural = 'unexpected shutdowns'; Log = 'System'; Ids = @(41); Provider = '^Microsoft-Windows-Kernel-Power$'; Threshold = 0 },
    @{ Singular = 'disk error'; Plural = 'disk errors'; Log = 'System'; Ids = @(7, 51, 153); Provider = '^disk$'; Threshold = 0 },
    @{ Singular = 'NTFS error'; Plural = 'NTFS errors'; Log = 'System'; Ids = @(55); Provider = '^(Microsoft-Windows-)?Ntfs$'; Threshold = 0 },
    @{ Singular = 'failed logon'; Plural = 'failed logons'; Log = 'Security'; Ids = @(4625); Provider = '.'; Threshold = $failedLogonThreshold }
)

$problems = @()
$clean = @()
$unreadable = @()
$unreadableLogs = @()
$sections = @()

foreach ($check in $checks) {
    $queryErrors = $null
    $filter = @{ LogName = $check.Log; Id = $check.Ids; StartTime = $since }

    $events = @(Get-WinEvent -FilterHashtable $filter -MaxEvents $maxEventsPerCheck -ErrorAction SilentlyContinue -ErrorVariable queryErrors |
        Where-Object { $_.ProviderName -match $check.Provider })

    $realErrors = @($queryErrors | Where-Object { $_.FullyQualifiedErrorId -notlike 'NoMatchingEventsFound*' })
    if ($realErrors.Count -gt 0) {
        $unreadable += "$($check.Plural) ($($check.Log) log): $($realErrors[0].Exception.Message)"
        if ($unreadableLogs -notcontains $check.Log) {
            $unreadableLogs += $check.Log
        }
        continue
    }

    $capped = $events.Count -ge $maxEventsPerCheck
    $summary = Format-Count $events.Count $check.Singular $check.Plural $capped

    if ($events.Count -gt $check.Threshold) {
        $problems += $summary
    } else {
        $clean += $summary
    }

    if ($events.Count -gt 0) {
        $lines = @("$($summary.Substring(0, 1).ToUpper())$($summary.Substring(1)), most recent:")

        $events | Select-Object -First $recentPerCheck | ForEach-Object {
            $detail = if ($check.Ids -contains 4625) { Get-FailedLogonLine $_ } else { Get-FirstLine $_ }
            $lines += "  $($_.TimeCreated.ToString('yyyy-MM-dd HH:mm:ss'))  [$($_.Id)] $detail"
        }

        $sections += , $lines
    }
}

$window = "in the last $lookbackHours hours"
$attention = @()

if ($problems.Count -gt 0) {
    $attention += "$($problems -join ', ') $window"
}

foreach ($log in $unreadableLogs) {
    $attention += "could not read the $log log"
}

if ($attention.Count -gt 0) {
    Write-Output "ATTENTION: $($attention -join ', ')"
} elseif ($clean.Count -gt 0) {
    Write-Output "OK: $($clean -join ', ') $window"
}

Write-Output ''
Write-Output "Failed logon threshold: more than $failedLogonThreshold"

foreach ($lines in $sections) {
    Write-Output ''
    $lines | ForEach-Object { Write-Output $_ }
}

if ($unreadable.Count -gt 0) {
    Write-Output ''
    Write-Output 'Could not check:'
    $unreadable | ForEach-Object { Write-Output "  $_" }
}

if ($attention.Count -gt 0) {
    exit 1
}

exit 0
