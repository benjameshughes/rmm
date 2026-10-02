<?php

use App\Models\AuditLog;
use App\Models\DeviceAppMetric;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('devices:check-offline')->everyMinute();
Schedule::command('schedule:run-tasks')->everyMinute();
Schedule::command('commands:expire-stale')->everyFiveMinutes();
Schedule::command('agent:check-version')->hourly();
Schedule::command('model:prune', ['--model' => [DeviceAppMetric::class, AuditLog::class]])->hourly();
