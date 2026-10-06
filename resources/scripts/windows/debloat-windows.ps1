# Removes preinstalled consumer apps (and Dell SupportAssist) for every user and
# stops Windows provisioning them for new users, blocks OneDrive sync, sets the machine policies that switch off
# widgets, search highlights, AI features, feedback nags and Edge promotions,
# and turns off the per-user suggestion and silent app install switches in
# every profile plus the Default profile new users are copied from, including
# Bing results in Start search and the Copilot taskbar button.
#
# Safe to run daily: only what differs is changed, so a clean run changes
# nothing. Names are matched exactly, never by wildcard, and anything on the
# protected list is refused even if it is added to the removal list by mistake.

$protectedApps = @(
    'Microsoft.DesktopAppInstaller', 'Microsoft.WindowsStore', 'Microsoft.StorePurchaseApp', 'Microsoft.Winget.Source*',
    'Microsoft.VCLibs*', 'Microsoft.UI.Xaml*', 'Microsoft.NET.Native*', 'Microsoft.WindowsAppRuntime*',
    'Microsoft.SecHealthUI', 'Microsoft.AAD.BrokerPlugin', 'Microsoft.GetHelp',
    'Microsoft.Xbox.TCUI', 'Microsoft.XboxIdentityProvider', 'Microsoft.XboxSpeechToTextOverlay',
    'Microsoft.HEVCVideoExtension', 'Microsoft.HEIFImageExtension', 'Microsoft.WebMediaExtensions',
    'Microsoft.VP9VideoExtensions', 'Microsoft.WebpImageExtension', 'Microsoft.RawImageExtension',
    'Microsoft.Edge*', 'Microsoft.Windows.Photos', 'Microsoft.WindowsCalculator', 'Microsoft.WindowsNotepad',
    'Microsoft.Paint', 'Microsoft.MSPaint', 'Microsoft.ScreenSketch', 'Microsoft.WindowsTerminal'
)

$apps = @(
    'Clipchamp.Clipchamp', 'Microsoft.BingNews', 'Microsoft.News', 'Microsoft.BingWeather',
    'Microsoft.BingFinance', 'Microsoft.BingSports', 'Microsoft.BingTravel', 'Microsoft.BingFoodAndDrink',
    'Microsoft.BingHealthAndFitness', 'Microsoft.BingTranslator', 'Microsoft.MicrosoftSolitaireCollection',
    'Microsoft.WindowsFeedbackHub', 'Microsoft.WindowsMaps', 'Microsoft.Copilot', 'Microsoft.Windows.AIHub',
    'MicrosoftCorporationII.MicrosoftFamily', 'Microsoft.ZuneVideo', 'Microsoft.549981C3F5F10',
    'Microsoft.Messaging', 'Microsoft.OneConnect', 'Microsoft.SkypeApp', 'Microsoft.MixedReality.Portal',
    'Microsoft.3DBuilder', 'Microsoft.Microsoft3DViewer', 'Microsoft.Print3D', 'Microsoft.Windows.DevHome',
    'Microsoft.MicrosoftJournal', 'Microsoft.NetworkSpeedTest', 'Microsoft.Office.Sway', 'Microsoft.PCManager',
    'Microsoft.XboxApp', 'Microsoft.GamingApp', 'Microsoft.windowscommunicationsapps', 'Microsoft.People',
    'Microsoft.Office.OneNote', 'SpotifyAB.SpotifyMusic', 'BytedancePte.Ltd.TikTok', 'king.com.CandyCrushSaga',
    'king.com.CandyCrushSodaSaga', 'king.com.BubbleWitch3Saga', 'Disney.37853FC22B2CE', '4DF9E0F8.Netflix',
    'AmazonVideo.PrimeVideo', 'Amazon.com.Amazon', 'Facebook.Instagram', 'FACEBOOK.FACEBOOK', 'LinkedInforWindows',
    'Duolingo-LearnLanguagesforFree', 'AdobeSystemsIncorporated.AdobePhotoshopExpress', 'DellInc.DellMobileConnect',
    'Microsoft.MicrosoftStickyNotes', 'MicrosoftCorporationII.QuickAssist', 'Microsoft.Todos', 'Microsoft.MicrosoftOfficeHub',
    'Microsoft.OutlookForWindows', 'Microsoft.YourPhone', 'MicrosoftWindows.CrossDevice',
    'DellInc.DellSupportAssistforPCs', 'DellInc.DellDigitalDelivery'
)

# Dell SupportAssist also installs as desktop MSIs (the app, its remediation
# service and the OS recovery plugin). Dell Command | Update is left alone.
$desktopApps = @('Dell SupportAssist*')

$machineSettings = [ordered]@{
    'HKLM:\SOFTWARE\Policies\Microsoft\Dsh' = @{ AllowNewsAndInterests = 0 }
    'HKLM:\SOFTWARE\Policies\Microsoft\Windows\Windows Search' = @{ EnableDynamicContentInWSB = 0; AllowCortana = 0 }
    'HKLM:\SOFTWARE\Policies\Microsoft\Windows\Explorer' = @{ HideTaskViewButton = 1; HideRecommendedPersonalizedSites = 1 }
    'HKLM:\SOFTWARE\Policies\Microsoft\Windows\WindowsAI' = @{ AllowRecallEnablement = 0; DisableAIDataAnalysis = 1; DisableClickToDo = 1 }
    'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\Paint' = @{ DisableCocreator = 1; DisableGenerativeFill = 1; DisableImageCreator = 1 }
    'HKLM:\SOFTWARE\Policies\Microsoft\Windows\DataCollection' = @{ AllowTelemetry = 1; DoNotShowFeedbackNotifications = 1 }
    'HKLM:\SOFTWARE\Policies\Microsoft\Windows\System' = @{ PublishUserActivities = 0; UploadUserActivities = 0 }
    'HKLM:\SOFTWARE\Policies\Microsoft\Windows\AdvertisingInfo' = @{ DisabledByGroupPolicy = 1 }
    'HKLM:\SOFTWARE\Policies\Microsoft\InputPersonalization' = @{ AllowInputPersonalization = 0 }
    'HKLM:\SOFTWARE\Policies\Microsoft\Windows\GameDVR' = @{ AllowGameDVR = 0 }
    'HKLM:\SOFTWARE\Policies\Microsoft\Edge' = @{
        HideFirstRunExperience = 1; DefaultBrowserSettingsCampaignEnabled = 0; ShowRecommendationsEnabled = 0
        SpotlightExperiencesAndRecommendationsEnabled = 0; EdgeShoppingAssistantEnabled = 0; ShowMicrosoftRewards = 0
        WalletDonationEnabled = 0; MicrosoftEdgeInsiderPromotionEnabled = 0; NewTabPageContentEnabled = 0
        UserFeedbackAllowed = 0; PersonalizationReportingEnabled = 0; HubsSidebarEnabled = 0; NewTabPageBingChatEnabled = 0
    }
    'HKLM:\SOFTWARE\Policies\Microsoft\EdgeUpdate' = @{ CreateDesktopShortcutDefault = 0 }
    'HKLM:\SOFTWARE\Policies\Microsoft\Windows\OneDrive' = @{ DisableFileSyncNGSC = 1 }
}

$userSettings = [ordered]@{
    'Software\Microsoft\Windows\CurrentVersion\ContentDeliveryManager' = @{
        SilentInstalledAppsEnabled = 0; PreInstalledAppsEnabled = 0; OemPreInstalledAppsEnabled = 0
        SystemPaneSuggestionsEnabled = 0; SoftLandingEnabled = 0; RotatingLockScreenOverlayEnabled = 0
        'SubscribedContent-310093Enabled' = 0; 'SubscribedContent-338387Enabled' = 0; 'SubscribedContent-338388Enabled' = 0
        'SubscribedContent-338389Enabled' = 0; 'SubscribedContent-338393Enabled' = 0; 'SubscribedContent-353694Enabled' = 0
        'SubscribedContent-353696Enabled' = 0; 'SubscribedContent-353698Enabled' = 0
    }
    'Software\Microsoft\Windows\CurrentVersion\Explorer\Advanced' = @{
        Start_IrisRecommendations = 0; ShowSyncProviderNotifications = 0; Start_AccountNotifications = 0; ShowCopilotButton = 0
    }
    'Software\Microsoft\Windows\CurrentVersion\Search' = @{ BingSearchEnabled = 0 }
    'Software\Policies\Microsoft\Windows\Explorer' = @{ DisableSearchBoxSuggestions = 1 }
    'Software\Microsoft\Windows\CurrentVersion\UserProfileEngagement' = @{ ScoobeSystemSettingEnabled = 0 }
    'Software\Microsoft\Windows\CurrentVersion\Notifications\Settings\Windows.SystemToast.Suggested' = @{ Enabled = 0 }
}

$tasks = @(
    @{ Path = '\Microsoft\Windows\Customer Experience Improvement Program\'; Name = 'Consolidator' }
    @{ Path = '\Microsoft\Windows\Customer Experience Improvement Program\'; Name = 'UsbCeip' }
    @{ Path = '\Microsoft\Windows\DiskDiagnostic\'; Name = 'Microsoft-Windows-DiskDiagnosticDataCollector' }
)

$removed = [System.Collections.Generic.List[string]]::new()
$changed = [System.Collections.Generic.List[string]]::new()
$hives = [System.Collections.Generic.List[string]]::new()
$failed = [System.Collections.Generic.List[string]]::new()

function Test-Protected([string]$name) {
    @($protectedApps | Where-Object { $name -like $_ }).Count -gt 0
}

function Set-DwordValue([string]$path, [string]$name, [int]$value) {
    $current = (Get-ItemProperty -Path $path -Name $name -ErrorAction SilentlyContinue).$name

    if ($current -is [int] -and $current -eq $value) {
        return $false
    }

    if (-not (Test-Path -Path $path)) {
        New-Item -Path $path -Force | Out-Null
    }

    Set-ItemProperty -Path $path -Name $name -Value $value -Type DWord
    return $true
}

function Set-UserSettings([string]$root) {
    $count = 0
    foreach ($key in $userSettings.Keys) {
        foreach ($name in $userSettings[$key].Keys) {
            if (Set-DwordValue -path "$root\$key" -name $name -value $userSettings[$key][$name]) {
                $count++
            }
        }
    }
    $count
}

function Set-MountedHive([string]$label, [string]$id, [string]$file) {
    if (-not (Test-Path -Path $file)) {
        $hives.Add("$label skipped: no NTUSER.DAT")
        return
    }

    $mount = "HKU\RMM_$id"
    reg.exe load $mount $file 2>&1 | Out-Null

    if ($LASTEXITCODE -ne 0) {
        $hives.Add("$label skipped: hive in use or unreadable")
        return
    }

    $count = Set-UserSettings -root "Registry::HKEY_USERS\RMM_$id"

    [gc]::Collect()
    [gc]::WaitForPendingFinalizers()
    reg.exe unload $mount 2>&1 | Out-Null

    if ($LASTEXITCODE -ne 0) {
        Start-Sleep -Seconds 2
        [gc]::Collect()
        reg.exe unload $mount 2>&1 | Out-Null
    }

    if ($LASTEXITCODE -ne 0) {
        $failed.Add("$label hive is still mounted at $mount")
    }

    $hives.Add("$label (not signed in): $count changed")
}

$refused = @($apps | Where-Object { Test-Protected $_ })
$removable = @($apps | Where-Object { -not (Test-Protected $_) })
foreach ($name in $removable) {
    Get-AppxPackage -AllUsers -Name $name -ErrorAction SilentlyContinue | ForEach-Object {
        Remove-AppxPackage -Package $_.PackageFullName -AllUsers -ErrorAction SilentlyContinue
        $removed.Add("app $($_.Name)")
    }
}

# Win32_Product is avoided on purpose: querying it makes Windows Installer
# consistency-check (and sometimes repair) every MSI on the machine.
Get-ItemProperty -Path @(
        'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*',
        'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*'
    ) -ErrorAction SilentlyContinue |
    Where-Object { $displayName = $_.DisplayName; $_.PSChildName -match '^\{[0-9A-Fa-f-]+\}$' -and @($desktopApps | Where-Object { $displayName -like $_ }).Count -gt 0 } |
    ForEach-Object {
        $uninstall = Start-Process 'msiexec.exe' -ArgumentList "/x $($_.PSChildName) /qn /norestart" -Wait -PassThru
        if (@(0, 1605, 3010) -contains $uninstall.ExitCode) {
            $removed.Add("desktop app $($_.DisplayName)")
        } else {
            $failed.Add("$($_.DisplayName) uninstall exited $($uninstall.ExitCode)")
        }
    }

# Removing an app for all users usually deprovisions it too, so the provisioned
# list is read after the removals: only what is genuinely left is touched.
Get-AppxProvisionedPackage -Online -ErrorAction SilentlyContinue |
    Where-Object { $removable -contains $_.DisplayName } |
    ForEach-Object {
        Remove-AppxProvisionedPackage -Online -PackageName $_.PackageName -AllUsers -ErrorAction SilentlyContinue 2>$null | Out-Null
        $removed.Add("provisioned $($_.DisplayName)")
    }

foreach ($path in $machineSettings.Keys) {
    foreach ($name in $machineSettings[$path].Keys) {
        if (Set-DwordValue -path $path -name $name -value $machineSettings[$path][$name]) {
            $changed.Add("$path\$name = $($machineSettings[$path][$name])")
        }
    }
}

foreach ($task in $tasks) {
    $scheduled = Get-ScheduledTask -TaskPath $task.Path -TaskName $task.Name -ErrorAction SilentlyContinue

    if (-not $scheduled -or $scheduled.State -eq 'Disabled') {
        continue
    }

    $result = Disable-ScheduledTask -TaskPath $task.Path -TaskName $task.Name -ErrorAction SilentlyContinue
    if ($result.State -eq 'Disabled') {
        $changed.Add("task $($task.Path)$($task.Name) disabled")
    } else {
        $failed.Add("task $($task.Path)$($task.Name) could not be disabled")
    }
}

$profiles = @(Get-CimInstance -ClassName Win32_UserProfile -ErrorAction SilentlyContinue |
    Where-Object { -not $_.Special -and $_.SID -like 'S-1-5-21-*' })

Get-ChildItem -Path 'Registry::HKEY_USERS' -ErrorAction SilentlyContinue |
    Where-Object { $_.PSChildName -match '^S-1-5-21-[0-9-]+$' } |
    ForEach-Object {
        $sid = $_.PSChildName
        $userProfile = $profiles | Where-Object { $_.SID -eq $sid } | Select-Object -First 1
        $label = if ($userProfile) { Split-Path -Path $userProfile.LocalPath -Leaf } else { $sid }
        $count = Set-UserSettings -root "Registry::HKEY_USERS\$sid"
        $hives.Add("$label (signed in): $count changed")
    }

$profiles |
    Where-Object { -not (Test-Path -Path "Registry::HKEY_USERS\$($_.SID)") } |
    ForEach-Object { Set-MountedHive -label (Split-Path -Path $_.LocalPath -Leaf) -id $_.SID -file (Join-Path $_.LocalPath 'NTUSER.DAT') }

Set-MountedHive -label 'Default' -id 'Default' -file "$env:SystemDrive\Users\Default\NTUSER.DAT"

$installed = @(Get-AppxPackage -AllUsers -ErrorAction SilentlyContinue | Where-Object { $removable -contains $_.Name })
$installed | ForEach-Object { $failed.Add("$($_.Name) is still installed") }

$stillProvisioned = @(Get-AppxProvisionedPackage -Online -ErrorAction SilentlyContinue | Where-Object { $removable -contains $_.DisplayName })
$stillProvisioned | ForEach-Object { $failed.Add("$($_.DisplayName) is still provisioned for new users") }

if ($removed.Count -eq 0 -and $changed.Count -eq 0) {
    Write-Output 'Nothing to do: the apps are gone and every machine setting is in place.'
} else {
    Write-Output 'Removed:'
    $removed | ForEach-Object { Write-Output "  $_" }
    Write-Output 'Changed:'
    $changed | ForEach-Object { Write-Output "  $_" }
}

Write-Output 'User profiles:'
$hives | ForEach-Object { Write-Output "  $_" }

if ($refused.Count -gt 0) {
    Write-Output "Refused, on the protected list: $($refused -join ', ')"
}

if ($failed.Count -gt 0) {
    Write-Output "ATTENTION: $($failed -join '; ')"
    exit 1
}

exit 0
