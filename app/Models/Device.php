<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApiKeyState;
use App\Enums\CommandStatus;
use App\Enums\DeviceStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

final class Device extends Model
{
    /** @use HasFactory<\Database\Factories\DeviceFactory> */
    use HasFactory;

    protected $fillable = [
        'hostname',
        'hardware_fingerprint',
        'status',
        'os',
        'os_name',
        'os_version',
        'cpu_model',
        'cpu_cores',
        'total_ram_gb',
        'disks',
        'last_ip',
        'last_seen',
        'netdata_version',
        'kernel_name',
        'kernel_version',
        'architecture',
        'virtualization',
        'container',
        'is_k8s_node',
        'device_group_id',
    ];

    protected $hidden = [
        'api_key_hash',
        'pending_api_key',
    ];

    protected function casts(): array
    {
        return [
            'last_seen' => 'datetime',
            'disks' => 'array',
            'status' => DeviceStatus::class,
            'pending_api_key' => 'encrypted',
            'api_key_issued_at' => 'datetime',
            'api_key_claimed_at' => 'datetime',
        ];
    }

    public static function hashApiKey(string $apiKey): string
    {
        return hash('sha256', $apiKey);
    }

    public static function findActiveByApiKey(string $apiKey): ?self
    {
        return self::query()
            ->where('api_key_hash', self::hashApiKey($apiKey))
            ->where('status', DeviceStatus::Active)
            ->first();
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(DeviceMetric::class);
    }

    public function latestMetric(): HasOne
    {
        return $this->hasOne(DeviceMetric::class)->latestOfMany('recorded_at');
    }

    public function commands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class);
    }

    public function pendingCommands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class)->where('status', CommandStatus::Pending);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(DeviceGroup::class, 'device_group_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function unresolvedAlerts(): HasMany
    {
        return $this->hasMany(Alert::class)->unresolved();
    }

    /**
     * Approve the device and generate a fresh key. Only the hash is used for
     * authentication; the plaintext is held encrypted until the agent claims it.
     */
    public function issueApiKey(): void
    {
        $apiKey = Str::random(64);

        $this->forceFill([
            'status' => DeviceStatus::Active,
            'api_key_hash' => self::hashApiKey($apiKey),
            'pending_api_key' => $apiKey,
            'api_key_issued_at' => now(),
            'api_key_claimed_at' => null,
        ])->save();
    }

    /**
     * Hand the pending key to the agent exactly once. The conditional update only
     * succeeds for the first caller, and the hash check guards against a reset and
     * re-issue happening between reading the key and claiming it.
     */
    public function claimPendingApiKey(): ?string
    {
        $apiKey = $this->pending_api_key;

        if ($this->status !== DeviceStatus::Active || $apiKey === null || $this->api_key_claimed_at !== null) {
            return null;
        }

        $claimedAt = now();

        $claimed = self::query()
            ->whereKey($this->getKey())
            ->where('status', DeviceStatus::Active)
            ->where('api_key_hash', self::hashApiKey($apiKey))
            ->whereNull('api_key_claimed_at')
            ->whereNotNull('pending_api_key')
            ->update([
                'api_key_claimed_at' => $claimedAt,
                'pending_api_key' => null,
            ]);

        if ($claimed !== 1) {
            return null;
        }

        $this->forceFill([
            'api_key_claimed_at' => $claimedAt,
            'pending_api_key' => null,
        ])->syncOriginal();

        return $apiKey;
    }

    /**
     * Revoke the current key and send the device back to the approval queue.
     */
    public function resetEnrolment(): void
    {
        $this->forceFill([
            'status' => DeviceStatus::Pending,
            'api_key_hash' => null,
            'pending_api_key' => null,
            'api_key_issued_at' => null,
            'api_key_claimed_at' => null,
        ])->save();
    }

    public function apiKeyState(): ApiKeyState
    {
        return match (true) {
            $this->api_key_hash === null => ApiKeyState::None,
            $this->api_key_claimed_at === null => ApiKeyState::AwaitingAgent,
            default => ApiKeyState::Claimed,
        };
    }

    protected function isOnline(): Attribute
    {
        return Attribute::get(fn (): bool => $this->last_seen !== null && $this->last_seen->greaterThan(now()->subMinutes(5)));
    }
}
