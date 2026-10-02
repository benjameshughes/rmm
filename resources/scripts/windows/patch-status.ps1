# Read-only patching health check, meant to run on a schedule. Exits 1 when:
#   - a restart is pending for longer than $rebootGraceHours. The age is only
#     known when the last update install happened after the last boot; a
#     pending restart of unknown age needs attention straight away.
#   - patches are waiting and the last successful install is older than
#     $staleAfterDays. Patches waiting on a recently patched PC are normal.
#   - the update search fails and the last install is older than $staleAfterDays.
#   - no successful install can be found at all.
# Defender definition updates install daily, so they are ignored: they would
# make every PC look freshly patched. Feature upgrades are listed but never
# count as waiting patches, the windows-update script skips them too.

$staleAfterDays = 14
$rebootGraceHours = 24

$upgradesCategoryId = '3689BDC8-B205-4AF4-8D4A-A63924C5E9D5'
$definitionsCategoryId = 'E0789628-CE08-4437-BE74-2495B842F43B'
$definitionTitlePattern = 'Security Intelligence Update|Definition Update|antimalware platform'

$ErrorActionPreference = 'Stop'

trap {
    Write-Output "ATTENTION: patch status check failed: $($_.Exception.Message)"
    exit 1
}

function Format-Age([TimeSpan] $span) {
    if ($span.TotalDays -ge 1) {
        $days = [int][Math]::Floor($span.TotalDays)
        if ($days -eq 1) { return '1 day' }
        return "$days days"
    }

    $hours = [int][Math]::Floor($span.TotalHours)
    if ($hours -eq 1) { return '1 hour' }
    return "$hours hours"
}

$now = Get-Date
$unchecked = @()

$session = $null
try {
    $session = New-Object -ComObject Microsoft.Update.Session
} catch {
    $unchecked += "Windows Update API unavailable: $($_.Exception.Message)"
}

$lastPatched = $null
$lastPatchTitle = $null
$lastPatchSource = $null

if ($session) {
    try {
        $historySearcher = $session.CreateUpdateSearcher()
        $historyCount = $historySearcher.GetTotalHistoryCount()

        if ($historyCount -gt 0) {
            # Operation 1 = installation, ResultCode 2 = succeeded, 3 = succeeded with errors
            $latest = $historySearcher.QueryHistory(0, $historyCount) |
                Where-Object { $_.Operation -eq 1 -and ($_.ResultCode -in 2, 3) -and $_.Title -notmatch $definitionTitlePattern } |
                Sort-Object Date -Descending |
                Select-Object -First 1

            if ($latest) {
                # History dates come back in UTC
                $lastPatched = [DateTime]::SpecifyKind($latest.Date, [DateTimeKind]::Utc).ToLocalTime()
                $lastPatchTitle = $latest.Title
                $lastPatchSource = 'Windows Update history'
            }
        }
    } catch {
        $unchecked += "Windows Update history: $($_.Exception.Message)"
    }
}

if (-not $lastPatched) {
    try {
        $hotfix = Get-HotFix -ErrorAction SilentlyContinue |
            Where-Object { $_.InstalledOn } |
            Sort-Object InstalledOn -Descending |
            Select-Object -First 1

        if ($hotfix) {
            $lastPatched = $hotfix.InstalledOn
            $lastPatchTitle = "$($hotfix.HotFixID) $($hotfix.Description)"
            $lastPatchSource = 'Get-HotFix'
        }
    } catch {
        $unchecked += "Installed hotfixes: $($_.Exception.Message)"
    }
}

$pendingPatches = @()
$pendingUpgrades = @()
$searchSucceeded = $false

if ($session) {
    try {
        $searchResult = $session.CreateUpdateSearcher().Search("IsInstalled=0 and IsHidden=0 and Type='Software'")

        foreach ($update in $searchResult.Updates) {
            $categoryIds = @($update.Categories | ForEach-Object { $_.CategoryID })

            if ($categoryIds -contains $upgradesCategoryId) {
                $pendingUpgrades += $update.Title
            } elseif ($categoryIds -notcontains $definitionsCategoryId) {
                $pendingPatches += $update.Title
            }
        }

        $searchSucceeded = $true
    } catch {
        $unchecked += "Update search: $($_.Exception.Message)"
    }
}

$rebootReasons = @()

if (Test-Path 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending') {
    $rebootReasons += 'component servicing'
}

if (Test-Path 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired') {
    $rebootReasons += 'Windows Update'
}

$lastBoot = $null
try {
    $lastBoot = (Get-CimInstance -ClassName Win32_OperatingSystem).LastBootUpTime
} catch {
    $unchecked += "Last boot time: $($_.Exception.Message)"
}

$rebootPendingSince = $null
if ($rebootReasons.Count -gt 0 -and $lastPatched -and $lastBoot -and $lastPatched -gt $lastBoot) {
    $rebootPendingSince = $lastPatched
}

$problems = @()
$notes = @()
$isStale = $true

if ($lastPatched) {
    $patchedAgo = Format-Age ($now - $lastPatched)
    $isStale = ($now - $lastPatched).TotalDays -gt $staleAfterDays
} else {
    $problems += 'no successful update install found'
}

if ($rebootReasons.Count -gt 0) {
    if ($rebootPendingSince) {
        $pendingFor = $now - $rebootPendingSince
        if ($pendingFor.TotalHours -ge $rebootGraceHours) {
            $problems += "restart pending for $(Format-Age $pendingFor)"
        } else {
            $notes += "restart pending for $(Format-Age $pendingFor)"
        }
    } else {
        $problems += 'restart pending'
    }
}

if ($searchSucceeded) {
    if ($pendingPatches.Count -gt 0) {
        $waiting = if ($pendingPatches.Count -eq 1) { '1 update waiting' } else { "$($pendingPatches.Count) updates waiting" }
        if ($isStale) {
            $problems += $waiting
        } else {
            $notes += $waiting
        }
    } else {
        $notes += 'nothing pending'
    }
} elseif ($isStale) {
    $problems += 'could not search for updates'
} else {
    $notes += 'could not search for updates'
}

if ($lastPatched) {
    if ($problems.Count -gt 0) {
        $problems += "last patched $patchedAgo ago"
    } else {
        $notes = @("patched $patchedAgo ago") + $notes
    }
}

if ($problems.Count -gt 0) {
    Write-Output "ATTENTION: $($problems -join ', ')"
} else {
    Write-Output "OK: $($notes -join ', ')"
}

Write-Output ''

if ($lastPatched) {
    Write-Output "Last successful install: $($lastPatched.ToString('yyyy-MM-dd HH:mm')) $lastPatchTitle ($lastPatchSource)"
} else {
    Write-Output 'Last successful install: unknown'
}

if ($lastBoot) {
    Write-Output "Last boot: $($lastBoot.ToString('yyyy-MM-dd HH:mm'))"
}

if ($rebootReasons.Count -gt 0) {
    Write-Output "Restart pending: yes ($($rebootReasons -join ', '))"
} else {
    Write-Output 'Restart pending: no'
}

if ($searchSucceeded) {
    Write-Output ''
    Write-Output "Updates waiting: $($pendingPatches.Count)"
    $pendingPatches | ForEach-Object { Write-Output "  $_" }

    if ($pendingUpgrades.Count -gt 0) {
        Write-Output ''
        Write-Output 'Feature upgrades available (not counted as waiting):'
        $pendingUpgrades | ForEach-Object { Write-Output "  $_" }
    }
}

if ($unchecked.Count -gt 0) {
    Write-Output ''
    Write-Output 'Could not check:'
    $unchecked | ForEach-Object { Write-Output "  $_" }
}

if ($problems.Count -gt 0) {
    exit 1
}

exit 0
