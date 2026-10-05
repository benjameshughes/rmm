<?php

declare(strict_types=1);

namespace App\Models;

use App\DTOs\Inventory\SystemInventory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One system-inventory run on one device: the full JSON plus a few values
 * promoted to columns for fleet queries.
 */
final class DeviceInventory extends Model
{
    /** @use HasFactory<\Database\Factories\DeviceInventoryFactory> */
    use HasFactory;

    protected $fillable = [
        'device_id',
        'collected_at',
        'data',
        'manufacturer',
        'model',
        'serial_number',
        'total_ram_gb',
        'windows_edition',
        'windows_build',
        'is_bitlocker_on',
        'is_secure_boot',
        'local_admin_count',
    ];

    protected function casts(): array
    {
        return [
            'collected_at' => 'datetime',
            'data' => 'array',
            'total_ram_gb' => 'decimal:1',
            'is_bitlocker_on' => 'boolean',
            'is_secure_boot' => 'boolean',
            'local_admin_count' => 'integer',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function snapshot(): SystemInventory
    {
        return new SystemInventory($this->data ?? []);
    }
}
