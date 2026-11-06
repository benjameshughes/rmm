<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\StaffAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A local Windows account the RMM keeps in step on every PC it targets. The
 * RMM never keeps its password: creating or resetting the account sets the
 * temp password from config/staff.php, which the person must change at
 * their next logon, and a password set from the Staff page lives encrypted
 * in command_secrets only until each PC's agent fetches it.
 */
final class StaffAccount extends Model
{
    use Auditable;

    /** @use HasFactory<StaffAccountFactory> */
    use HasFactory;

    protected $fillable = ['username', 'full_name', 'is_admin', 'is_enabled'];

    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }

    public function devices(): BelongsToMany
    {
        return $this->belongsToMany(Device::class, 'staff_account_device');
    }

    public function deviceGroups(): BelongsToMany
    {
        return $this->belongsToMany(DeviceGroup::class, 'staff_account_device_group');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }
}
