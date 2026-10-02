<?php

use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', fn (User $user, int $id): bool => $user->id === $id);

Broadcast::channel('devices', fn (User $user): bool => $user->can('viewAny', Device::class));

Broadcast::channel('devices.{device}', fn (User $user, Device $device): bool => $user->can('view', $device));
