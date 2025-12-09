<?php

use App\Http\Controllers\Api\DeviceEnrollmentController;
use App\Http\Controllers\Api\DeviceMetricsController;
use App\Http\Controllers\Api\HeartbeatController;
use Illuminate\Support\Facades\Route;

Route::post('/enroll', [DeviceEnrollmentController::class, 'store'])
    ->middleware('throttle:api.enroll');

Route::post('/metrics', [DeviceMetricsController::class, 'store'])
    ->middleware('throttle:api.metrics');

Route::post('/heartbeat', [HeartbeatController::class, 'store'])
    ->middleware('throttle:api.heartbeat');

Route::match(['GET', 'POST'], '/check', [DeviceEnrollmentController::class, 'check'])
    ->middleware('throttle:api.check');
