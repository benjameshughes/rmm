<?php

use App\Http\Controllers\Api\DeviceEnrollmentController;
use App\Http\Controllers\Api\DeviceMetricsController;
use Illuminate\Support\Facades\Route;

Route::post('/enroll', [DeviceEnrollmentController::class, 'store']);
Route::post('/metrics', [DeviceMetricsController::class, 'store']);
Route::match(['GET', 'POST'], '/check', [DeviceEnrollmentController::class, 'check']);
