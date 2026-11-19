# Installs the "Daily Lock Up" reminder: a large, calm, always-on-top
# window that appears for whoever is logged in at 16:00 every day.
# Done closes it, Snooze brings it back after 10 minutes.
#
# The window has to run as the logged-in user, because the agent runs as
# SYSTEM in session 0 where nobody can see it. So this writes the popup script
# to ProgramData and registers a Windows scheduled task for the Users group.
#
# Safe to rerun: it overwrites the popup and replaces the task.
#
# Prints OK: or ATTENTION: first.

$folder = 'C:\ProgramData\BenJH'
$popupPath = Join-Path $folder 'daily-lock-up-reminder.ps1'
$taskName = 'BenJH Daily Lock Up Reminder'

$popup = @'
Add-Type -AssemblyName System.Windows.Forms, System.Drawing
[System.Windows.Forms.Application]::EnableVisualStyles()

$accent = [System.Drawing.Color]::FromArgb(43, 91, 168)
$ink = [System.Drawing.Color]::FromArgb(28, 28, 28)
$muted = [System.Drawing.Color]::FromArgb(110, 110, 110)

$form = New-Object System.Windows.Forms.Form
$form.FormBorderStyle = 'None'
$form.StartPosition = 'CenterScreen'
$form.Size = New-Object System.Drawing.Size(640, 300)
$form.BackColor = [System.Drawing.Color]::FromArgb(247, 247, 245)
$form.TopMost = $true
$form.ShowInTaskbar = $true
$form.Text = 'Daily Lock Up'

$strip = New-Object System.Windows.Forms.Panel
$strip.Dock = 'Left'
$strip.Width = 10
$strip.BackColor = $accent
$form.Controls.Add($strip)

$time = New-Object System.Windows.Forms.Label
$time.Text = (Get-Date).ToString('HH:mm')
$time.Font = New-Object System.Drawing.Font('Segoe UI', 11)
$time.ForeColor = $muted
$time.AutoSize = $true
$time.Location = New-Object System.Drawing.Point(44, 32)
$form.Controls.Add($time)

$headline = New-Object System.Windows.Forms.Label
$headline.Text = 'Daily Lock Up'
$headline.Font = New-Object System.Drawing.Font('Segoe UI Semibold', 28)
$headline.ForeColor = $ink
$headline.AutoSize = $true
$headline.Location = New-Object System.Drawing.Point(40, 58)
$form.Controls.Add($headline)

$body = New-Object System.Windows.Forms.Label
$body.Text = "Time to lock up: doors, windows and shutters shut,`nlights off and the alarm set."
$body.Font = New-Object System.Drawing.Font('Segoe UI', 13)
$body.ForeColor = $muted
$body.AutoSize = $true
$body.Location = New-Object System.Drawing.Point(44, 130)
$form.Controls.Add($body)

$done = New-Object System.Windows.Forms.Button
$done.Text = 'Done'
$done.Font = New-Object System.Drawing.Font('Segoe UI Semibold', 12)
$done.Size = New-Object System.Drawing.Size(150, 46)
$done.Location = New-Object System.Drawing.Point(455, 224)
$done.FlatStyle = 'Flat'
$done.FlatAppearance.BorderSize = 0
$done.BackColor = $accent
$done.ForeColor = [System.Drawing.Color]::White
$done.Add_Click({ $form.Close() })
$form.Controls.Add($done)

$snooze = New-Object System.Windows.Forms.Button
$snooze.Text = 'Snooze 10m'
$snooze.Font = New-Object System.Drawing.Font('Segoe UI', 12)
$snooze.Size = New-Object System.Drawing.Size(150, 46)
$snooze.Location = New-Object System.Drawing.Point(290, 224)
$snooze.FlatStyle = 'Flat'
$snooze.FlatAppearance.BorderColor = [System.Drawing.Color]::FromArgb(190, 190, 190)
$snooze.BackColor = $form.BackColor
$snooze.ForeColor = $ink
$form.Controls.Add($snooze)

$timer = New-Object System.Windows.Forms.Timer
$timer.Interval = 10 * 60 * 1000
$timer.Add_Tick({
    $timer.Stop()
    $time.Text = (Get-Date).ToString('HH:mm')
    $form.Show()
    $form.Activate()
})

$snooze.Add_Click({
    $form.Hide()
    $timer.Start()
})

$form.Add_Paint({
    param($sender, $e)
    $pen = New-Object System.Drawing.Pen([System.Drawing.Color]::FromArgb(220, 220, 220))
    $e.Graphics.DrawRectangle($pen, 0, 0, $sender.Width - 1, $sender.Height - 1)
})

[System.Windows.Forms.Application]::Run($form)
'@

New-Item -ItemType Directory -Path $folder -Force | Out-Null
Set-Content -Path $popupPath -Value $popup -Encoding UTF8 -Force

if (-not (Test-Path $popupPath)) {
    Write-Output "ATTENTION: could not write $popupPath"
    exit 1
}

$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$popupPath`""
$triggers = @(
    New-ScheduledTaskTrigger -Daily -At '16:00'
)
$principal = New-ScheduledTaskPrincipal -GroupId 'S-1-5-32-545' -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Hours 2) -MultipleInstances IgnoreNew

Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $triggers -Principal $principal -Settings $settings -Force -ErrorAction SilentlyContinue | Out-Null

if (-not (Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue)) {
    Write-Output "ATTENTION: could not register the '$taskName' scheduled task"
    exit 1
}

Write-Output "OK: daily lock up reminder installed for 16:00 every day"
Write-Output "Popup: $popupPath"
Write-Output "Task: $taskName"
