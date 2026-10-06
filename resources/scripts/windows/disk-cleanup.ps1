# Frees space on the system drive without touching anything a person made:
# temp files older than a week (Windows and every profile), crash dumps, the
# Delivery Optimization cache and superseded Windows components. Recycle bins,
# Downloads and documents are never touched. Reports how much space came back.

$systemDrive = $env:SystemDrive.TrimEnd(':')
$freeBefore = (Get-PSDrive -Name $systemDrive).Free
$cutoff = (Get-Date).AddDays(-7)
$cleaned = [System.Collections.Generic.List[string]]::new()

function Remove-OldFiles([string]$folder) {
    if (-not (Test-Path -Path $folder)) {
        return
    }

    Get-ChildItem -Path $folder -Recurse -Force -File -ErrorAction SilentlyContinue |
        Where-Object { $_.LastWriteTime -lt $cutoff } |
        Remove-Item -Force -ErrorAction SilentlyContinue
}

Remove-OldFiles -folder "$env:SystemRoot\Temp"
$cleaned.Add('Windows temp files older than 7 days')

Get-CimInstance -ClassName Win32_UserProfile -ErrorAction SilentlyContinue |
    Where-Object { -not $_.Special -and $_.LocalPath } |
    ForEach-Object {
        Remove-OldFiles -folder (Join-Path $_.LocalPath 'AppData\Local\Temp')
        Remove-OldFiles -folder (Join-Path $_.LocalPath 'AppData\Local\CrashDumps')
    }
$cleaned.Add('profile temp files and app crash dumps older than 7 days')

@("$env:SystemRoot\Minidump", "$env:SystemRoot\LiveKernelReports") | ForEach-Object { Remove-OldFiles -folder $_ }
Remove-Item -Path "$env:SystemRoot\MEMORY.DMP" -Force -ErrorAction SilentlyContinue
$cleaned.Add('Windows crash dumps')

if (Get-Command Delete-DeliveryOptimizationCache -ErrorAction SilentlyContinue) {
    Delete-DeliveryOptimizationCache -Force -ErrorAction SilentlyContinue
    $cleaned.Add('Delivery Optimization cache')
}

# Removes superseded component versions left behind by Windows updates. Safe,
# but it can take several minutes.
$dism = Start-Process 'dism.exe' -ArgumentList '/Online /Cleanup-Image /StartComponentCleanup /Quiet /NoRestart' -Wait -PassThru -WindowStyle Hidden
if ($dism.ExitCode -eq 0) {
    $cleaned.Add('superseded Windows components')
} else {
    Write-Output "Component cleanup exited $($dism.ExitCode); everything else was cleaned"
}

$freedGb = [math]::Round(((Get-PSDrive -Name $systemDrive).Free - $freeBefore) / 1GB, 2)

Write-Output "Freed $freedGb GB on ${systemDrive}:"
$cleaned | ForEach-Object { Write-Output "  $_" }
exit 0
