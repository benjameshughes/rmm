<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\DTOs\NetdataAppUsage;
use App\DTOs\NetdataCpuMetric;
use App\DTOs\NetdataNetworkAdapters;
use App\DTOs\NetdataRamMetric;
use App\DTOs\NetdataV3Metrics;
use App\DTOs\TopApps;
use App\Events\DeviceUpdated;
use App\Events\MetricsReceived;
use App\Models\Device;
use App\Models\DeviceMetric;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Metrics are stamped with the time the server received them: an agent's own
 * timestamp follows the PC's clock, which can run minutes fast or slow.
 * Agents from 0.8.0 send Netdata windows of per-second points on Windows and
 * Linux alike; each report keeps their summary and the points as samples.
 */
final class StoreDeviceMetrics
{
    public function __construct(private readonly RecordMetricSamples $recordMetricSamples) {}

    public function __invoke(Device $device, array $input, ?string $ip = null): DeviceMetric
    {
        if ($this->isRawNetdataFormat($input)) {
            return $this->handleRawNetdata($device, $input, $ip);
        }

        return $this->handleStandardMetrics($device, $input, $ip);
    }

    private function isRawNetdataFormat(array $input): bool
    {
        return collect($input)->keys()->contains(fn (int|string $key): bool => Str::startsWith((string) $key, 'netdata_'));
    }

    private function handleStandardMetrics(Device $device, array $input, ?string $ip): DeviceMetric
    {
        $cpu = $this->parseCpuMetric($input['cpu'] ?? null);
        $ram = $this->parseRamMetric($input['memory'] ?? $input['ram'] ?? null);
        $metricData = [
            'device_id' => $device->id,
            'cpu' => $cpu,
            'ram' => $ram,
            'recorded_at' => now(),
            'agent_version' => $input['agent_version'] ?? null,
            'payload' => $this->rawPayload($input),
        ];

        $metricData = $this->mergeCpuDetails($metricData, $input['cpu'] ?? null);
        $metricData = $this->mergeArrayFields($metricData, $input['load'] ?? null, [
            'load1' => 'load1',
            'load5' => 'load5',
            'load15' => 'load15',
        ]);
        $metricData = $this->mergeUptimeFields($metricData, $input['uptime'] ?? null);
        $metricData = $this->mergeArrayFields($metricData, $input['memory'] ?? null, [
            'used_mib' => 'memory_used_mib',
            'free_mib' => 'memory_free_mib',
            'total_mib' => 'memory_total_mib',
            'cached_mib' => 'memory_cached_mib',
            'buffers_mib' => 'memory_buffers_mib',
            'available_mib' => 'memory_available_mib',
        ]);
        $metricData = $this->mergeArrayFields($metricData, $input['alerts'] ?? null, [
            'normal' => 'alerts_normal',
            'warning' => 'alerts_warning',
            'critical' => 'alerts_critical',
        ]);
        $metricData = $this->mergeArrayFields($metricData, $input['processes'] ?? null, [
            'running' => 'processes_running',
            'blocked' => 'processes_blocked',
            'total' => 'processes_total',
        ]);
        $metricData = $this->mergeArrayFields($metricData, $input['swap'] ?? null, [
            'used_mib' => 'swap_used_mib',
            'total_mib' => 'swap_total_mib',
        ]);
        $metricData = [...$metricData, ...$this->linuxHealth($input['linux_health'] ?? null)];

        $volumes = $this->reportedVolumes($input['disks'] ?? null);
        $metricData['disk_busy_percent'] = $this->busiestVolumePercent($volumes);

        $metric = DeviceMetric::create($metricData);

        $metric->recordDisks($volumes);
        $metric->recordNetworkInterfaces($this->reportedAdapters($input['network'] ?? null));
        $metric->recordApps(is_array($input['apps'] ?? null) ? TopApps::fromReport($input['apps'], config('devices.metrics.top_apps')) : []);
        $this->updateDeviceInfo($device, $input['system_info'] ?? null, $ip, $input);

        MetricsReceived::dispatch($device, $metric);
        DeviceUpdated::dispatch($device, true, true);

        return $metric;
    }

    private function handleRawNetdata(Device $device, array $input, ?string $ip): DeviceMetric
    {
        $cpuParser = new NetdataV3Metrics($input['netdata_cpu'] ?? $input['netdata_metrics'] ?? []);
        $ramParser = new NetdataV3Metrics($input['netdata_ram'] ?? $input['netdata_metrics'] ?? []);
        $loadParser = new NetdataV3Metrics($input['netdata_load'] ?? []);
        $uptimeParser = new NetdataV3Metrics($input['netdata_uptime'] ?? []);

        $cpuDetails = $cpuParser->getCpuDetails();
        $memoryDetails = $ramParser->getMemoryDetails();
        $loadAverages = $loadParser->parseLoadAverages();
        $uptime = $uptimeParser->parseUptime();
        $swap = (new NetdataV3Metrics($input['netdata_swap'] ?? []))->parseSwap();
        $processes = (new NetdataV3Metrics($input['netdata_processes'] ?? []))->parseProcesses();

        $metric = DeviceMetric::create([
            'device_id' => $device->id,
            'cpu' => $cpuParser->parseCpuUsage(),
            'ram' => $ramParser->parseRamUsage(),
            'recorded_at' => now(),
            'agent_version' => $input['agent_version'] ?? null,
            'cpu_user' => $cpuDetails['user'],
            'cpu_system' => $cpuDetails['system'],
            'cpu_nice' => $cpuDetails['nice'],
            'cpu_iowait' => $cpuDetails['iowait'],
            'cpu_irq' => $cpuDetails['irq'],
            'cpu_softirq' => $cpuDetails['softirq'],
            'cpu_steal' => $cpuDetails['steal'],
            'cpu_idle' => $cpuDetails['idle'],
            'load1' => $loadAverages['load1'],
            'load5' => $loadAverages['load5'],
            'load15' => $loadAverages['load15'],
            'uptime_seconds' => $uptime !== null ? (int) $uptime : null,
            'memory_used_mib' => $memoryDetails['used_mib'],
            'memory_free_mib' => $memoryDetails['free_mib'],
            'memory_total_mib' => $memoryDetails['total_mib'],
            'memory_cached_mib' => $memoryDetails['cached_mib'],
            'memory_buffers_mib' => $memoryDetails['buffers_mib'],
            'memory_available_mib' => $memoryDetails['available_mib'],
            'cpu_queue_length' => (new NetdataV3Metrics($input['netdata_cpu_queue'] ?? []))->parseCpuQueueLength(),
            'swap_used_mib' => $swap['used_mib'] ?? null,
            'swap_total_mib' => $swap['total_mib'] ?? null,
            'processes_running' => $processes['running'],
            'processes_blocked' => $processes['blocked'],
            'disk_busy_percent' => (new NetdataV3Metrics($input['netdata_disk_util'] ?? []))->parseBusiestDiskPercent(),
            'payload' => $this->rawPayload($input),
            ...$this->linuxHealth($input['linux_health'] ?? null),
        ]);

        $metric->recordDisks($this->netdataVolumes($input));
        $metric->recordNetworkInterfaces(NetdataNetworkAdapters::fromPayload($input)->rows(config('devices.network.ignored_interfaces')));
        $metric->recordApps((new NetdataAppUsage($input['netdata_apps_cpu'] ?? [], $input['netdata_apps_mem'] ?? []))->top(config('devices.metrics.top_apps')));
        $this->updateDeviceInfoFromNetdata($device, $input['netdata_info'] ?? null, $ip, $input);
        ($this->recordMetricSamples)($device, $input);

        MetricsReceived::dispatch($device, $metric);
        DeviceUpdated::dispatch($device, true, true);

        return $metric;
    }

    /**
     * Disk space per volume, with the inode usage Linux reports for the same mount points.
     *
     * @return array<int, array<string, string|float|null>>
     */
    private function netdataVolumes(array $input): array
    {
        $inodes = (new NetdataV3Metrics($input['netdata_disk_inodes'] ?? []))->parseInodeUsage();

        return collect((new NetdataV3Metrics($input['netdata_disk'] ?? []))->parseDiskVolumes(config('devices.disk.ignored_volumes')))
            ->map(fn (array $volume): array => [...$volume, 'inode_usage_percent' => $inodes->get($volume['mount_point'])])
            ->all();
    }

    /**
     * Every useful field is extracted into columns, so the raw request is only kept while debugging the agent.
     */
    private function rawPayload(array $input): ?array
    {
        return config('devices.metrics.store_raw_payload') ? $input : null;
    }

    private function parseCpuMetric(mixed $input): ?float
    {
        if ($input === null) {
            return null;
        }

        if (is_array($input) && isset($input['usage_percent'])) {
            return max(0.0, min(100.0, round((float) $input['usage_percent'], 2)));
        }

        return (new NetdataCpuMetric($input))->getUsagePercent();
    }

    private function parseRamMetric(mixed $input): ?float
    {
        if ($input === null) {
            return null;
        }

        if (is_array($input) && isset($input['usage_percent'])) {
            return max(0.0, min(100.0, round((float) $input['usage_percent'], 2)));
        }

        return (new NetdataRamMetric($input))->getUsagePercent();
    }

    private function mergeCpuDetails(array $metricData, mixed $cpuData): array
    {
        if (! is_array($cpuData)) {
            return $metricData;
        }

        $cpuFields = [
            'user' => 'cpu_user',
            'system' => 'cpu_system',
            'nice' => 'cpu_nice',
            'iowait' => 'cpu_iowait',
            'irq' => 'cpu_irq',
            'softirq' => 'cpu_softirq',
            'steal' => 'cpu_steal',
            'idle' => 'cpu_idle',
            'frequency_mhz' => 'cpu_frequency_mhz',
        ];

        collect($cpuFields)->each(function (string $metricKey, string $inputKey) use (&$metricData, $cpuData): void {
            $metricData[$metricKey] = $cpuData[$inputKey] ?? null;
        });

        $metricData['cpu_cores'] = isset($cpuData['cores']) ? (int) $cpuData['cores'] : null;

        return $metricData;
    }

    /** @param array<string, string> $fieldMap */
    private function mergeArrayFields(array $metricData, mixed $source, array $fieldMap): array
    {
        if (! is_array($source)) {
            return $metricData;
        }

        collect($fieldMap)->each(function (string $metricKey, string $sourceKey) use (&$metricData, $source): void {
            $metricData[$metricKey] = $source[$sourceKey] ?? null;
        });

        return $metricData;
    }

    private function mergeUptimeFields(array $metricData, mixed $uptime): array
    {
        if (is_array($uptime) && isset($uptime['seconds'])) {
            $metricData['uptime_seconds'] = (int) $uptime['seconds'];
        }

        return $metricData;
    }

    /**
     * A Linux host reports every mount, including Docker layers, pseudo
     * filesystems and RAM-backed tmpfs that fill and empty by design.
     */
    private function reportedVolumes(mixed $disks): mixed
    {
        if (! is_array($disks)) {
            return $disks;
        }

        return collect($disks)
            ->reject(fn (mixed $disk): bool => is_array($disk) && (
                Str::is(config('devices.disk.ignored_volumes'), (string) ($disk['mount_point'] ?? ''))
                || Str::is(config('devices.disk.ignored_filesystems'), (string) ($disk['filesystem'] ?? ''))
            ))
            ->values()
            ->all();
    }

    /**
     * The busiest disk's utilisation, the same figure Windows agents report as disk busy.
     */
    private function busiestVolumePercent(mixed $volumes): ?float
    {
        if (! is_array($volumes)) {
            return null;
        }

        $busiest = collect($volumes)->pluck('utilization_percent')->filter(fn (mixed $percent): bool => $percent !== null)->max();

        return $busiest === null ? null : (float) $busiest;
    }

    /**
     * Linux agents name adapter health the way they read it from the kernel; store it in the
     * columns the Windows adapters fill, dropping virtual adapters on the ignore list.
     */
    private function reportedAdapters(mixed $adapters): mixed
    {
        if (! is_array($adapters)) {
            return $adapters;
        }

        return collect($adapters)
            ->filter(fn (mixed $adapter): bool => is_array($adapter))
            ->reject(fn (array $adapter): bool => Str::is(config('devices.network.ignored_interfaces'), (string) ($adapter['interface'] ?? '')))
            ->map(fn (array $adapter): array => [
                ...$adapter,
                'errors_inbound' => $adapter['errors_inbound'] ?? $adapter['received_errors'] ?? null,
                'errors_outbound' => $adapter['errors_outbound'] ?? $adapter['sent_errors'] ?? null,
                'drops_inbound' => $adapter['drops_inbound'] ?? $adapter['received_drops'] ?? null,
                'drops_outbound' => $adapter['drops_outbound'] ?? $adapter['sent_drops'] ?? null,
                'link_speed_kbps' => $adapter['link_speed_kbps'] ?? (isset($adapter['link_speed_mbps']) ? (int) round($adapter['link_speed_mbps'] * 1000) : null),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{failed_units?: array<int, string>, reboot_required?: ?bool, pending_updates?: ?int, pending_security_updates?: ?int, updates_checked_at?: ?Carbon}
     */
    private function linuxHealth(mixed $health): array
    {
        if (! is_array($health)) {
            return [];
        }

        return [
            'failed_units' => array_key_exists('failed_units', $health) && is_array($health['failed_units']) ? array_values($health['failed_units']) : null,
            'reboot_required' => isset($health['reboot_required']) ? (bool) $health['reboot_required'] : null,
            'pending_updates' => isset($health['pending_updates']) ? (int) $health['pending_updates'] : null,
            'pending_security_updates' => isset($health['pending_security_updates']) ? (int) $health['pending_security_updates'] : null,
            'updates_checked_at' => isset($health['checked_updates_at']) ? Carbon::parse($health['checked_updates_at']) : null,
        ];
    }

    private function updateDeviceInfo(Device $device, mixed $systemInfo, ?string $ip, array $input): void
    {
        $updates = $this->connectionUpdates($device, $ip, $input);

        if (is_array($systemInfo)) {
            $allowedFields = [
                'os_name', 'os_version', 'netdata_version',
                'kernel_name', 'kernel_version', 'architecture',
                'virtualization', 'container',
            ];

            collect($allowedFields)
                ->filter(fn (string $field): bool => isset($systemInfo[$field]))
                ->each(function (string $field) use (&$updates, $systemInfo): void {
                    $updates[$field] = $systemInfo[$field];
                });

            if (isset($systemInfo['is_k8s_node'])) {
                $updates['is_k8s_node'] = (bool) $systemInfo['is_k8s_node'];
            }
        }

        $device->forceFill($updates)->save();
    }

    private function updateDeviceInfoFromNetdata(Device $device, mixed $netdataInfo, ?string $ip, array $input): void
    {
        $updates = $this->connectionUpdates($device, $ip, $input);

        if (is_array($netdataInfo)) {
            $agent = $netdataInfo['agents'][0] ?? null;

            if (is_array($agent)) {
                collect(['os_name', 'os_version', 'kernel_name', 'kernel_version', 'architecture'])
                    ->filter(fn (string $field): bool => isset($agent[$field]))
                    ->each(function (string $field) use (&$updates, $agent): void {
                        $updates[$field] = $agent[$field];
                    });
            }

            if (isset($netdataInfo['version'])) {
                $updates['netdata_version'] = $netdataInfo['version'];
            }
        }

        $device->forceFill($updates)->save();
    }

    /**
     * An agent that omits its version or MAC addresses keeps the last ones it reported.
     * Monitor only is a one-way latch: a later report of false or nothing never
     * clears it, so a compromised or replaced agent cannot grant itself commands.
     *
     * @return array{last_seen: Carbon, last_ip: ?string, power_state?: null, power_state_changed_at?: null, agent_version?: string, mac_addresses?: array<int, string>, is_monitor_only?: true}
     */
    private function connectionUpdates(Device $device, ?string $ip, array $input): array
    {
        $agentVersion = $input['agent_version'] ?? null;
        $macAddresses = $input['mac_addresses'] ?? null;
        $reportsMonitorOnly = filter_var($input['monitor_only'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return [
            ...$device->checkInAttributes($ip),
            ...($agentVersion === null ? [] : ['agent_version' => $agentVersion]),
            ...($macAddresses === null ? [] : ['mac_addresses' => $this->normaliseMacAddresses($macAddresses)]),
            ...($reportsMonitorOnly ? ['is_monitor_only' => true] : []),
        ];
    }

    /**
     * Windows reports dash-separated lowercase MACs, sysinfo colon-separated; store one form.
     *
     * @param  array<int, string>  $macAddresses
     * @return array<int, string>
     */
    private function normaliseMacAddresses(array $macAddresses): array
    {
        return collect($macAddresses)
            ->map(fn (string $mac): string => strtoupper(str_replace('-', ':', $mac)))
            ->reject(fn (string $mac): bool => in_array($mac, config('devices.network.ignored_mac_addresses'), true))
            ->unique()
            ->values()
            ->all();
    }
}
