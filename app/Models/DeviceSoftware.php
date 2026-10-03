<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One package installed on one device, from the device's latest winget inventory.
 */
final class DeviceSoftware extends Model
{
    /** @use HasFactory<\Database\Factories\DeviceSoftwareFactory> */
    use HasFactory;

    protected $table = 'device_software';

    protected $fillable = [
        'device_id',
        'package_id',
        'name',
        'installed_version',
        'latest_version',
        'is_update_available',
        'source',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'is_update_available' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * winget can only upgrade by ID what it installed from its own source.
     */
    protected function isUpgradable(): Attribute
    {
        return Attribute::get(fn (): bool => $this->is_update_available && $this->source === config('software.upgradable_source'));
    }
}
