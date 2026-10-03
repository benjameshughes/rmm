<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApiKeyState;
use App\Enums\CommandStatus;
use App\Enums\DevicePowerState;
use App\Enums\DeviceStatus;
use App\Enums\ScriptPlatform;
use App\Events\DeviceEnrolled;
use App\Events\DeviceUpdated;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class Device extends Model
{
    use Auditable;

    /** @use HasFactory<\Database\Factories\DeviceFactory> */
    use HasFactory;

    protected $fillable = [
        'hostname',
        'hardware_fingerprint',
        'status',
        'agent_version',
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

    /** @var array<string, class-string> */
    protected $dispatchesEvents = [
        'created' => DeviceEnrolled::class,
        'updated' => DeviceUpdated::class,
    ];

    protected function casts(): array
    {
        return [
            'last_seen' => 'datetime',
            'power_state' => DevicePowerState::class,
            'power_state_changed_at' => 'datetime',
            'disks' => 'array',
            'mac_addresses' => 'array',
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

    /**
     * Devices that have not announced they are powering off, or whose notice has
     * lapsed. Null columns fail a plain comparison in SQL, so they are matched explicitly.
     */
    public function scopeNotPoweringOff(Builder $query): Builder
    {
        return $query->where(fn (Builder $powerQuery): Builder => $powerQuery
            ->whereNull('power_state')
            ->orWhere('power_state', '!=', DevicePowerState::PoweringOff)
            ->orWhereNull('power_state_changed_at')
            ->orWhere('power_state_changed_at', '<=', self::powerStateLapsesBefore(DevicePowerState::PoweringOff)));
    }

    /**
     * Devices still carrying a power state that has outlived its window.
     */
    public function scopeWithLapsedPowerState(Builder $query): Builder
    {
        return $query->where(fn (Builder $lapsedQuery): Builder => collect(DevicePowerState::cases())->reduce(
            fn (Builder $statesQuery, DevicePowerState $powerState): Builder => $statesQuery->orWhere(fn (Builder $stateQuery): Builder => $stateQuery
                ->where('power_state', $powerState)
                ->where(fn (Builder $changedQuery): Builder => $changedQuery
                    ->whereNull('power_state_changed_at')
                    ->orWhere('power_state_changed_at', '<=', self::powerStateLapsesBefore($powerState)))),
            $lapsedQuery,
        ));
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

        DeviceUpdated::dispatch($this);

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

    public function apiKeyStateDetail(): ?string
    {
        return match ($this->apiKeyState()) {
            ApiKeyState::Claimed => $this->api_key_claimed_at->diffForHumans(),
            ApiKeyState::AwaitingAgent => $this->api_key_issued_at ? 'issued '.$this->api_key_issued_at->diffForHumans() : null,
            ApiKeyState::None => null,
        };
    }

    public function isAgentOutdated(?string $latestVersion): bool
    {
        return $this->agent_version !== null
            && $latestVersion !== null
            && version_compare($this->agent_version, $latestVersion, '<');
    }

    /**
     * The agent only says which OS it runs through free-text fields, and only
     * Windows and Linux agents exist, so anything not naming Windows is Linux.
     */
    public function platform(): ScriptPlatform
    {
        $namesWindows = collect([$this->os, $this->os_name, $this->kernel_name])
            ->contains(fn (?string $name): bool => Str::contains((string) $name, 'windows', ignoreCase: true));

        return $namesWindows ? ScriptPlatform::Windows : ScriptPlatform::Linux;
    }

    /**
     * Every check-in proves the device is awake, so it clears a powering off
     * state and a powering on state once it has been held long enough to be seen.
     * A check-in right after a powering off notice was already in flight when
     * sleep began, so it leaves that notice standing.
     *
     * @return array{last_seen: Carbon, last_ip: ?string, power_state?: null, power_state_changed_at?: null}
     */
    public function checkInAttributes(?string $ip): array
    {
        $keepsPowerState = match ($this->power_state) {
            DevicePowerState::PoweringOn => $this->hasPowerStateInForce(DevicePowerState::PoweringOn),
            DevicePowerState::PoweringOff => $this->power_state_changed_at?->greaterThan(now()->subSeconds(config('devices.power.powering_off_check_in_grace_seconds'))) ?? false,
            null => false,
        };

        return [
            'last_seen' => now(),
            'last_ip' => $ip,
            ...($keepsPowerState ? [] : ['power_state' => null, 'power_state_changed_at' => null]),
        ];
    }

    /**
     * A power state only holds for its window: powering on long enough for the
     * badge to be seen, powering off long enough to cover a weekend.
     */
    public function hasPowerStateInForce(DevicePowerState $powerState): bool
    {
        return $this->power_state === $powerState
            && $this->power_state_changed_at?->greaterThan(self::powerStateLapsesBefore($powerState)) === true;
    }

    private static function powerStateLapsesBefore(DevicePowerState $powerState): Carbon
    {
        return match ($powerState) {
            DevicePowerState::PoweringOn => now()->subSeconds(config('devices.power.powering_on_hold_seconds')),
            DevicePowerState::PoweringOff => now()->subHours(config('devices.power.powering_off_max_hours')),
        };
    }

    public function statusLabel(): string
    {
        return match (true) {
            ! $this->status->isApproved() => $this->status->label(),
            $this->isPoweringOff => DevicePowerState::PoweringOff->label().($this->power_state_changed_at ? ' since '.$this->power_state_changed_at->format('H:i') : ''),
            $this->isPoweringOn => DevicePowerState::PoweringOn->label(),
            $this->isOnline => 'Online',
            default => 'Offline',
        };
    }

    public function statusColor(): string
    {
        return match (true) {
            ! $this->status->isApproved() => $this->status->color(),
            $this->isPoweringOff, $this->isPoweringOn => $this->power_state->color(),
            $this->isOnline => 'green',
            default => 'red',
        };
    }

    /**
     * @return Collection<int, array{name: string, mountPoint: ?string, availableGb: ?float, totalGb: ?float, usedPercent: ?float, barColor: string}>
     */
    public function diskUsage(): Collection
    {
        $reportedVolumes = $this->latestMetric?->diskMetrics
            ->map(fn (DeviceDiskMetric $volume): array => $volume->only(['mount_point', 'total_gb', 'available_gb']));

        return collect($reportedVolumes?->isNotEmpty() ? $reportedVolumes : ($this->disks ?? []))->map(function (array $disk): array {
            $totalGb = isset($disk['total_gb']) ? (float) $disk['total_gb'] : null;
            $availableGb = isset($disk['available_gb']) ? (float) $disk['available_gb'] : null;
            $usedPercent = $totalGb > 0 ? (($totalGb - (float) $availableGb) / $totalGb) * 100 : null;

            return [
                'name' => $disk['name'] ?? $disk['mount_point'] ?? '—',
                'mountPoint' => isset($disk['name']) ? ($disk['mount_point'] ?? null) : null,
                'availableGb' => $availableGb,
                'totalGb' => $totalGb,
                'usedPercent' => $usedPercent,
                'barColor' => match (true) {
                    $usedPercent > config('devices.disk.critical_percent') => 'bg-red-500',
                    $usedPercent > config('devices.disk.warning_percent') => 'bg-amber-500',
                    default => 'bg-blue-500',
                },
            ];
        });
    }

    /**
     * A device that announced it is powering off is gone now, not once last_seen ages out.
     */
    protected function isOnline(): Attribute
    {
        return Attribute::get(fn (): bool => ! $this->isPoweringOff
            && $this->last_seen !== null
            && $this->last_seen->greaterThan(now()->subMinutes(config('devices.online.threshold_minutes'))));
    }

    protected function isPoweringOff(): Attribute
    {
        return Attribute::get(fn (): bool => $this->hasPowerStateInForce(DevicePowerState::PoweringOff));
    }

    /**
     * Only for the hold window and only while the device is still reporting: a Modern Standby PC
     * often checks in once and dozes off again, so waiting for a later check-in to clear it never ends.
     */
    protected function isPoweringOn(): Attribute
    {
        return Attribute::get(fn (): bool => $this->hasPowerStateInForce(DevicePowerState::PoweringOn) && $this->isOnline);
    }

    protected function isWakeable(): Attribute
    {
        return Attribute::get(fn (): bool => ! $this->isOnline && ! empty($this->mac_addresses));
    }
}
