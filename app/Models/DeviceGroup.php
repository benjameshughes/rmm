<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class DeviceGroup extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'color'];

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }
}
