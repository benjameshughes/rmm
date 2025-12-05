<?php

use App\Http\Controllers\AgentInstallerController;
use App\Http\Controllers\AgentTrayController;
use App\Livewire\Devices\Agent as DevicesAgent;
use App\Livewire\Devices\Index as DevicesIndex;
use App\Livewire\Devices\Pending as DevicesPending;
use App\Livewire\Devices\Show as DevicesShow;
use App\Livewire\Settings\Appearance;
use App\Livewire\Settings\Password;
use App\Livewire\Settings\Profile;
use App\Livewire\Settings\TwoFactor;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::view('dashboard', 'dashboard')
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
    Route::get('devices/{device}', DevicesShow::class)->name('devices.show');
});

// Public download for the agent installer script
Route::get('agent/install.ps1', [AgentInstallerController::class, 'download'])
    ->name('agent.download');
Route::get('agent/tauri.zip', [AgentTrayController::class, 'download'])
    ->name('agent.tauri.download');
Route::get('agent/rmm-tray.exe', [AgentTrayController::class, 'downloadExe'])
    ->name('agent.tauri.exe');
