<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\FormatsMebibytes;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class DeviceMetric extends Model
{
    use FormatsMebibytes;

    /** @use HasFactory<\Database\Factories\DeviceMetricFactory> */
    use HasFactory;

    protected $fillable = [
        'device_id',
        'cpu',
        'cpu_user',
        'cpu_system',
        'cpu_nice',
        'cpu_iowait',
        'cpu_irq',
        'cpu_softirq',
        'cpu_steal',
        'cpu_idle',
        'cpu_cores',
        'cpu_frequency_mhz',
        'ram',
        'load1',
        'load5',
        'load15',
        'uptime_seconds',
        'memory_used_mib',
        'memory_free_mib',
        'memory_total_mib',
        'memory_cached_mib',
        'memory_buffers_mib',
        'memory_available_mib',
        'alerts_normal',
        'alerts_warning',
        'alerts_critical',
        'agent_version',
        'processes_running',
        'processes_blocked',
        'processes_total',
        'cpu_queue_length',
        'swap_used_mib',
        'swap_total_mib',
        'disk_busy_percent',
        'payload',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'cpu' => 'float',
            'ram' => 'float',
            'load1' => 'float',
            'load5' => 'float',
            'load15' => 'float',
            'memory_used_mib' => 'float',
            'memory_total_mib' => 'float',
            'cpu_queue_length' => 'float',
            'swap_used_mib' => 'float',
            'swap_total_mib' => 'float',
            'disk_busy_percent' => 'float',
            'payload' => 'array',
            'recorded_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function diskMetrics(): HasMany
    {
        return $this->hasMany(DeviceDiskMetric::class);
    }

    public function networkMetrics(): HasMany
    {
        return $this->hasMany(DeviceNetworkMetric::class);
    }

    public function appMetrics(): HasMany
    {
        return $this->hasMany(DeviceAppMetric::class)
            ->orderByDesc('cpu_percent')
            ->orderByDesc('memory_mib');
    }

    /** @param array<int, array<string, mixed>>|mixed $disks */
    public function recordDisks(mixed $disks): void
    {
        if (! is_array($disks)) {
            return;
        }

        collect($disks)
            ->filter(fn (mixed $disk): bool => is_array($disk) && isset($disk['mount_point']))
            ->each(fn (array $disk) => $this->diskMetrics()->create([
                'mount_point' => $disk['mount_point'],
                'filesystem' => $disk['filesystem'] ?? null,
                'used_gb' => $disk['used_gb'] ?? null,
                'available_gb' => $disk['available_gb'] ?? null,
                'total_gb' => $disk['total_gb'] ?? null,
                'usage_percent' => $disk['usage_percent'] ?? null,
                'read_kbps' => $disk['read_kbps'] ?? null,
                'write_kbps' => $disk['write_kbps'] ?? null,
                'utilization_percent' => $disk['utilization_percent'] ?? null,
            ]));
    }

    /** @param array<int, array<string, mixed>>|mixed $interfaces */
    public function recordNetworkInterfaces(mixed $interfaces): void
    {
        if (! is_array($interfaces)) {
            return;
        }

        collect($interfaces)
            ->filter(fn (mixed $interface): bool => is_array($interface) && isset($interface['interface']))
            ->each(fn (array $interface) => $this->networkMetrics()->create([
                'interface' => $interface['interface'],
                'received_kbps' => $interface['received_kbps'] ?? null,
                'sent_kbps' => $interface['sent_kbps'] ?? null,
                'received_bytes' => $interface['received_bytes'] ?? null,
                'sent_bytes' => $interface['sent_bytes'] ?? null,
                'errors_inbound' => $interface['errors_inbound'] ?? null,
                'errors_outbound' => $interface['errors_outbound'] ?? null,
                'drops_inbound' => $interface['drops_inbound'] ?? null,
                'drops_outbound' => $interface['drops_outbound'] ?? null,
                'link_speed_kbps' => $interface['link_speed_kbps'] ?? null,
            ]));
    }

    /** @param array<int, array{name: string, cpu_percent: float|null, memory_mib: float|null}> $apps */
    public function recordApps(array $apps): void
    {
        $this->appMetrics()->createMany($apps);
    }

    public function pageFilePercent(): ?float
    {
        if ($this->swap_used_mib === null || ! $this->swap_total_mib) {
            return null;
        }

        return round($this->swap_used_mib / $this->swap_total_mib * 100, 1);
    }

    public function cpuForHumans(): ?string
    {
        return $this->percentForHumans($this->cpu);
    }

    public function ramForHumans(): ?string
    {
        return $this->percentForHumans($this->ram);
    }

    public function memoryUsageForHumans(): ?string
    {
        if (! $this->memory_total_mib) {
            return null;
        }

        return number_format($this->memory_used_mib / 1024, 1).' / '.number_format($this->memory_total_mib / 1024, 1).' GB';
    }

    public function loadForHumans(): ?string
    {
        return $this->load1 === null ? null : number_format($this->load1, 2);
    }

    public function loadTrendForHumans(): ?string
    {
        return $this->load1 === null ? null : number_format($this->load5 ?? 0, 2).' / '.number_format($this->load15 ?? 0, 2);
    }

    public function cpuQueueForHumans(): ?string
    {
        return $this->cpu_queue_length === null ? null : number_format($this->cpu_queue_length, 2);
    }

    public function diskBusyForHumans(): ?string
    {
        return $this->percentForHumans($this->disk_busy_percent);
    }

    public function pageFilePercentForHumans(): ?string
    {
        return $this->percentForHumans($this->pageFilePercent());
    }

    public function pageFileForHumans(): ?string
    {
        if ($this->swap_used_mib === null || $this->swap_total_mib === null) {
            return null;
        }

        return $this->mebibytesForHumans($this->swap_used_mib).' / '.$this->mebibytesForHumans($this->swap_total_mib);
    }

    /**
     * Whole percentages for the device list, where a decimal is noise.
     */
    public function cpuRoundedForHumans(): ?string
    {
        return $this->cpu === null ? null : number_format($this->cpu).'%';
    }

    public function ramRoundedForHumans(): ?string
    {
        return $this->ram === null ? null : number_format($this->ram).'%';
    }

    public function cpuBarColor(): string
    {
        return $this->loadBarColor($this->cpu);
    }

    public function ramBarColor(): string
    {
        return $this->loadBarColor($this->ram);
    }

    /**
     * How busy the processors are beyond a percentage: load average where the OS reports it, otherwise the CPU queue.
     */
    public function processorLoadForHumans(): ?string
    {
        return match (true) {
            $this->load1 !== null => 'Load Average '.$this->loadForHumans().' · '.$this->loadTrendForHumans(),
            $this->cpu_queue_length !== null => 'CPU Queue '.$this->cpuQueueForHumans().' threads waiting',
            default => null,
        };
    }

    public function uptimeForHumans(): ?string
    {
        if ($this->uptime_seconds === null) {
            return null;
        }

        return CarbonInterval::seconds((int) $this->uptime_seconds)
            ->cascade()
            ->forHumans(['short' => true, 'parts' => 2, 'minimumUnit' => 'minute']);
    }

    protected function hasLoadAverage(): Attribute
    {
        return Attribute::get(fn (): bool => $this->load1 !== null);
    }

    protected function hasAlertCounts(): Attribute
    {
        return Attribute::get(fn (): bool => $this->alerts_warning !== null || $this->alerts_critical !== null);
    }

    protected function hasPerformanceData(): Attribute
    {
        return Attribute::get(fn (): bool => $this->cpu_queue_length !== null
            || $this->swap_total_mib !== null
            || $this->disk_busy_percent !== null);
    }

    private function loadBarColor(?float $percent): string
    {
        return match (true) {
            $percent > config('devices.load.critical_percent') => 'bg-red-500',
            $percent > config('devices.load.warning_percent') => 'bg-amber-500',
            default => 'bg-blue-500',
        };
    }

    private function percentForHumans(?float $value): ?string
    {
        return $value === null ? null : number_format($value, 1).'%';
    }
}
