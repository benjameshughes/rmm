<?php

namespace App\Policies;

use App\Models\User;

class Device
{

    protected User $user;
    /**
     * Create a new policy instance.
     */
    public function __construct()
    {
        // Can I just get the user in this construct?
        $this->user = auth()->user();
    }

    /**
     * Accept pending device
     */
    public function create(User $user)
    {
        return $user->can('create-device');
    }

    /**
     * Can delete device
     */
    public function delete(User $user, Device $device)
    {
        return $user->can('delete-device')
    }
}
