<?php

use App\Http\Controllers\AgentInstallerController;
use App\Http\Controllers\AgentTrayController;
use App\Livewire\AlertRules\Index as AlertRulesIndex;
use App\Livewire\Alerts\Index as AlertsIndex;
use App\Livewire\Audit\Index as AuditIndex;
use App\Livewire\Dashboard;
use App\Livewire\DeviceGroups\Index as DeviceGroupsIndex;
use App\Livewire\Devices\Agent as DevicesAgent;
use App\Livewire\Devices\Apps as DeviceApps;
use App\Livewire\Devices\Commands as DeviceCommands;
use App\Livewire\Devices\Details as DeviceDetails;
use App\Livewire\Devices\Index as DevicesIndex;
use App\Livewire\Devices\Metrics as DeviceMetrics;
use App\Livewire\Devices\Overview as DeviceOverview;
use App\Livewire\Devices\Pending as DevicesPending;
use App\Livewire\Devices\System as DeviceSystem;
use App\Livewire\Hardware\Index as HardwareIndex;
use App\Livewire\ScheduledTasks\Index as ScheduledTasksIndex;
use App\Livewire\Scripts\Create as ScriptsCreate;
use App\Livewire\Scripts\Edit as ScriptsEdit;
use App\Livewire\Scripts\Index as ScriptsIndex;
use App\Livewire\Scripts\Show as ScriptsShow;
use App\Livewire\Settings\Appearance;
use App\Livewire\Settings\Password;
use App\Livewire\Settings\Profile;
use App\Livewire\Settings\TwoFactor;
use App\Livewire\Software\Index as SoftwareIndex;
use App\Livewire\Software\Show as SoftwareShow;
use App\Livewire\Tags\Index as TagsIndex;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::get('dashboard', Dashboard::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', Profile::class)->name('profile.edit');
    Route::get('settings/password', Password::class)->name('user-password.edit');
    Route::get('settings/appearance', Appearance::class)->name('appearance.edit');

    Route::get('settings/two-factor', TwoFactor::class)
        ->middleware(
            when(
                Features::canManageTwoFactorAuthentication()
                    && Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword'),
                ['password.confirm'],
                [],
            ),
        )
        ->name('two-factor.show');

    // Devices
    Route::get('devices', DevicesIndex::class)->name('devices.index');
    Route::get('devices/pending', DevicesPending::class)->name('devices.pending');
    Route::get('devices/agent', DevicesAgent::class)->name('devices.agent');

    Route::prefix('devices/{device}')->name('devices.')->group(function () {
        Route::get('/', DeviceOverview::class)->name('show');
        Route::get('metrics', DeviceMetrics::class)->name('metrics');
        Route::get('commands', DeviceCommands::class)->name('commands');
        Route::get('apps', DeviceApps::class)->name('apps');
        Route::get('system', DeviceSystem::class)->name('system');
        Route::get('details', DeviceDetails::class)->name('details');
    });

    // Software
    Route::get('software', SoftwareIndex::class)->name('software.index');
    Route::get('software/package', SoftwareShow::class)->name('software.show');

    // Hardware
    Route::get('hardware', HardwareIndex::class)->name('hardware.index');

    // Scripts
    Route::get('scripts', ScriptsIndex::class)->name('scripts.index');
    Route::get('scripts/create', ScriptsCreate::class)->name('scripts.create');
    Route::get('scripts/{script}', ScriptsShow::class)->name('scripts.show');
    Route::get('scripts/{script}/edit', ScriptsEdit::class)->name('scripts.edit');

    // Device Groups & Tags
    Route::get('device-groups', DeviceGroupsIndex::class)->name('device-groups.index');
    Route::get('tags', TagsIndex::class)->name('tags.index');

    // Alerts
    Route::get('alerts', AlertsIndex::class)->name('alerts.index');
    Route::get('alert-rules', AlertRulesIndex::class)->name('alert-rules.index');

    // Schedules
    Route::get('scheduled-tasks', ScheduledTasksIndex::class)->name('scheduled-tasks.index');

    // Audit
    Route::get('audit', AuditIndex::class)->name('audit.index');
});

// Public downloads for the agent installer scripts
Route::get('agent/install.ps1', [AgentInstallerController::class, 'download'])
    ->name('agent.download');
Route::get('agent/install.sh', [AgentInstallerController::class, 'downloadLinux'])
    ->name('agent.download.linux');
Route::get('agent/tauri.zip', [AgentTrayController::class, 'download'])
    ->name('agent.tauri.download');
Route::get('agent/rmm-tray.exe', [AgentTrayController::class, 'downloadExe'])
    ->name('agent.tauri.exe');
