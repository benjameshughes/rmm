<?php

declare(strict_types=1);

namespace App\Models;

use App\DTOs\InFlightCommands;
use App\Enums\ApiKeyState;
use App\Enums\BackupRunStatus;
use App\Enums\CommandStatus;
use App\Enums\DevicePowerState;
use App\Enums\DeviceStatus;
use App\Enums\ScriptPlatform;
use App\Events\DeviceEnrolled;
use App\Events\DeviceUpdated;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BacksUpFiles;
use App\Models\Concerns\WatchesPrinters;
use App\Models\Concerns\WatchesVirtualPrinter;
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
    use BacksUpFiles;

    /** @use HasFactory<\Database\Factories\DeviceFactory> */
    use HasFactory;

    use WatchesPrinters;
    use WatchesVirtualPrinter;

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
        'backup_repository_password',
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
            'software_inventoried_at' => 'datetime',
            'system_inventoried_at' => 'datetime',
            'virtual_printer_seen_at' => 'datetime',
            'virtual_printer_missing_since' => 'datetime',
            'printers_reported_at' => 'datetime',
            'spooler_down_since' => 'datetime',
            'disks' => 'array',
            'mac_addresses' => 'array',
            'status' => DeviceStatus::class,
            'pending_api_key' => 'encrypted',
            'api_key_issued_at' => 'datetime',
            'api_key_claimed_at' => 'datetime',
            'backup_repository_password' => 'encrypted',
            'backup_configured_at' => 'datetime',
            'backup_master_key_added_at' => 'datetime',
            'last_backup_at' => 'datetime',
            'last_backup_status' => BackupRunStatus::class,
            'last_good_backup_at' => 'datetime',
            'backup_snapshots_listed_at' => 'datetime',
        ];
    }

    /**
     * Devices that reported after this moment are online: a few missed heartbeats, not a flat timeout.
     */
    public static function onlineCutoff(): Carbon
    {
        return now()->subSeconds(config('devices.heartbeat.interval_seconds') * config('devices.online.missed_heartbeats'));
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
     * Approved devices that checked in recently and have not announced powering off: the query twin of isOnline.
     */
    public function scopeOnline(Builder $query): Builder
    {
        return $query->where('status', DeviceStatus::Active)
            ->notPoweringOff()
            ->where('last_seen', '>', self::onlineCutoff());
    }

    /**
     * Approved devices whose powering off notice is still in force: the query twin of isPoweringOff.
     */
    public function scopePoweringOff(Builder $query): Builder
    {
        return $query->where('status', DeviceStatus::Active)
            ->where('power_state', DevicePowerState::PoweringOff)
            ->where('power_state_changed_at', '>', self::powerStateLapsesBefore(DevicePowerState::PoweringOff));
    }

    /**
     * Approved devices that went quiet without a word.
     */
    public function scopeOffline(Builder $query): Builder
    {
        return $query->where('status', DeviceStatus::Active)
            ->notPoweringOff()
            ->where(fn (Builder $seenQuery): Builder => $seenQuery
                ->whereNull('last_seen')
                ->orWhere('last_seen', '<=', self::onlineCutoff()));
    }

    /**
     * Adds a sortable rank matching statusLabel: online (and powering on) first, then
     * powering off, offline, and devices not approved last.
     */
    public function scopeAddStatusRank(Builder $query, string $alias): Builder
    {
        return $query->selectRaw(
            "CASE WHEN devices.status != ? THEN 3 WHEN devices.power_state = ? AND devices.power_state_changed_at > ? THEN 1 WHEN devices.last_seen > ? THEN 0 ELSE 2 END as {$alias}",
            [DeviceStatus::Active->value, DevicePowerState::PoweringOff->value, self::powerStateLapsesBefore(DevicePowerState::PoweringOff), self::onlineCutoff()],
        );
    }

    /**
     * Devices the server will queue commands for: monitor-only devices never take one.
     * Devices that have not reported yet have no flag, so null counts as not monitor only.
     */
    public function scopeAcceptsCommands(Builder $query): Builder
    {
        return $query->where(fn (Builder $flagQuery): Builder => $flagQuery
            ->whereNull('is_monitor_only')
            ->orWhere('is_monitor_only', false));
    }

    /**
     * The query twin of platform(): any OS field naming Windows.
     */
    public function scopeRunsWindows(Builder $query): Builder
    {
        return $query->where(fn (Builder $osQuery): Builder => $osQuery
            ->where('os', 'like', '%windows%')
            ->orWhere('os_name', 'like', '%windows%')
            ->orWhere('kernel_name', 'like', '%windows%'));
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

    public function metricSamples(): HasMany
    {
        return $this->hasMany(MetricSample::class);
    }

    public function latestMetric(): HasOne
    {
        return $this->hasOne(DeviceMetric::class)->latestOfMany('recorded_at');
    }

    public function commands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class);
    }

    public function software(): HasMany
    {
        return $this->hasMany(DeviceSoftware::class);
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(DeviceInventory::class);
    }

    public function latestInventory(): HasOne
    {
        return $this->hasOne(DeviceInventory::class)->latestOfMany('collected_at');
    }

    public function pendingCommands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class)->where('status', CommandStatus::Pending);
    }

    /**
     * Commands queued, handed to the agent or running: everything not yet finished.
     */
    /**
     * What the device is running or about to run, from the loaded in-flight commands.
     */
    public function inFlight(): InFlightCommands
    {
        return InFlightCommands::from($this->inFlightCommands, $this);
    }

    public function inFlightCommands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class)->inFlight();
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

        DeviceUpdated::dispatch($this, true);

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
            $this->isPoweringOff => 'Off'.($this->power_state_changed_at ? ' since '.$this->power_state_changed_at->inDisplayTimezone()->format('H:i') : ''),
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
     * A last-seen value that holds still on a live list: an online device was simply
     * seen now, older ones to the nearest unit.
     */
    public function lastSeenCoarse(): string
    {
        return match (true) {
            $this->last_seen === null => 'Never',
            $this->isOnline => 'Now',
            default => $this->last_seen->diffForHumans(),
        };
    }

    public function lastSeenAt(): ?string
    {
        return $this->last_seen?->inDisplayTimezone()->format('j M Y, H:i');
    }

    public function lastSeenForHumans(): string
    {
        return $this->last_seen === null ? 'Never seen' : 'Seen '.$this->last_seen->diffForHumans();
    }

    public function operatingSystem(): ?string
    {
        return $this->os_name ?? $this->os;
    }

    /**
     * The agent's own figure where it sent one, otherwise what Netdata last measured.
     */
    public function totalRamForHumans(): ?string
    {
        $totalRamGb = $this->total_ram_gb ?: ($this->latestMetric?->memory_total_mib ? $this->latestMetric->memory_total_mib / 1024 : null);

        return $totalRamGb ? number_format((float) $totalRamGb, 1).' GB' : null;
    }

    /**
     * The volume closest to full, which is the one worth a headline.
     *
     * @return array{name: string, mountPoint: ?string, availableGb: ?float, totalGb: ?float, usedPercent: ?float, usedForHumans: ?string, freeForHumans: ?string, barColor: string, usedRoundedForHumans: ?string, usedTextColor: string, inodePercent: ?float, inodeForHumans: ?string, inodeColor: string}|null
     */
    public function fullestDisk(): ?array
    {
        return $this->diskUsage()->sortByDesc('usedPercent')->first();
    }

    /**
     * @return Collection<int, array{name: string, mountPoint: ?string, availableGb: ?float, totalGb: ?float, usedPercent: ?float, usedForHumans: ?string, freeForHumans: ?string, barColor: string, usedRoundedForHumans: ?string, usedTextColor: string, inodePercent: ?float, inodeForHumans: ?string, inodeColor: string}>
     */
    public function diskUsage(): Collection
    {
        $reportedVolumes = $this->latestMetric?->diskMetrics
            ->map(fn (DeviceDiskMetric $volume): array => $volume->only(['mount_point', 'total_gb', 'available_gb', 'inode_usage_percent']));

        return collect($reportedVolumes?->isNotEmpty() ? $reportedVolumes : ($this->disks ?? []))->map(function (array $disk): array {
            $totalGb = isset($disk['total_gb']) ? (float) $disk['total_gb'] : null;
            $availableGb = isset($disk['available_gb']) ? (float) $disk['available_gb'] : null;
            $usedPercent = $totalGb > 0 ? (($totalGb - (float) $availableGb) / $totalGb) * 100 : null;
            $inodePercent = isset($disk['inode_usage_percent']) ? (float) $disk['inode_usage_percent'] : null;

            return [
                'name' => $disk['name'] ?? $disk['mount_point'] ?? '—',
                'mountPoint' => isset($disk['name']) ? ($disk['mount_point'] ?? null) : null,
                'availableGb' => $availableGb,
                'totalGb' => $totalGb,
                'usedPercent' => $usedPercent,
                'usedForHumans' => $usedPercent === null ? null : number_format($usedPercent, 1).'%',
                'freeForHumans' => $totalGb !== null && $availableGb !== null ? number_format($availableGb, 1).' GB free of '.number_format($totalGb, 1).' GB' : null,
                'barColor' => match (true) {
                    $usedPercent >= config('devices.disk.critical_percent') => 'bg-red-500',
                    $usedPercent >= config('devices.disk.warning_percent') => 'bg-amber-500',
                    default => 'bg-blue-500',
                },
                'usedRoundedForHumans' => $usedPercent === null ? null : number_format($usedPercent).'%',
                'usedTextColor' => match (true) {
                    $usedPercent >= config('devices.disk.critical_percent') => 'text-red-600 dark:text-red-400',
                    $usedPercent >= config('devices.disk.warning_percent') => 'text-amber-600 dark:text-amber-400',
                    default => 'text-zinc-600 dark:text-zinc-300',
                },
                'inodePercent' => $inodePercent,
                'inodeForHumans' => $inodePercent === null ? null : number_format($inodePercent, 1).'% of inodes used',
                'inodeColor' => match (true) {
                    $inodePercent >= config('devices.disk.inode_critical_percent') => 'text-red-600 dark:text-red-400',
                    $inodePercent >= config('devices.disk.inode_warning_percent') => 'text-amber-600 dark:text-amber-400',
                    default => 'text-zinc-500 dark:text-zinc-400',
                },
            ];
        });
    }

    /**
     * winget only exists on Windows, and monitor-only devices never run the inventory script.
     */
    public function hasSoftwareInventory(): bool
    {
        return $this->platform() === ScriptPlatform::Windows && ! $this->isMonitorOnly;
    }

    /**
     * The system inventory is a PowerShell script, and monitor-only devices never run scripts.
     */
    public function hasSystemInventory(): bool
    {
        return $this->platform() === ScriptPlatform::Windows && ! $this->isMonitorOnly;
    }

    public function systemInventoryCollectedForHumans(): ?string
    {
        return $this->system_inventoried_at === null ? null : 'Collected '.$this->system_inventoried_at->diffForHumans().'.';
    }

    public function swapLabel(): string
    {
        return $this->platform()->swapLabel();
    }

    /**
     * A device that announced it is powering off is gone now, not once last_seen ages out.
     */
    protected function isOnline(): Attribute
    {
        return Attribute::get(fn (): bool => ! $this->isPoweringOff
            && $this->last_seen !== null
            && $this->last_seen->greaterThan(self::onlineCutoff()));
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

    /**
     * Set once the agent reports it is read-only and never cleared by a later report,
     * so a compromised or swapped agent cannot grant itself commands back.
     */
    protected function isMonitorOnly(): Attribute
    {
        return Attribute::get(fn (): bool => (bool) ($this->attributes['is_monitor_only'] ?? false));
    }
}
