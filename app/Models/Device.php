<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Device extends Model
{
    /** @use HasFactory<\Database\Factories\DeviceFactory> */
    use HasFactory;

    protected $fillable = [
        'hostname',
        'hardware_fingerprint',
        'api_key',
        'status',
        'os',
        'last_ip',
        'last_seen',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen' => 'datetime',
        ];
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(DeviceMetric::class);
    }

    public function latestMetric(): HasOne
    {
        return $this->hasOne(DeviceMetric::class)->latestOfMany('recorded_at');
    }

    public function issueApiKey(): string
    {
        $this->api_key = Str::random(64);
        $this->status = self::STATUS_ACTIVE;
        $this->save();

        return $this->api_key;
    }

    public function isOnline(): bool
    {
        if ($this->last_seen === null) {
            return false;
        }

        return now()->diffInSeconds($this->last_seen) < 120;
    }
}
