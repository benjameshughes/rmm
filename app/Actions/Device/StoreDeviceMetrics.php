<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Actions\Alert\EvaluateAlertRules;
use App\DTOs\NetdataCpuMetric;
use App\DTOs\NetdataRamMetric;
use App\DTOs\NetdataV3Metrics;
use App\Models\Device;
use App\Models\DeviceMetric;
use Illuminate\Support\Carbon;

final class StoreDeviceMetrics
{
    public function __invoke(Device $device, array $input, ?string $ip = null): DeviceMetric
    {
        if ($this->isRawNetdataFormat($input)) {
            return $this->handleRawNetdata($device, $input, $ip);
        }

        return $this->handleStandardMetrics($device, $input, $ip);
    }

    private function isRawNetdataFormat(array $input): bool
    {
        return isset($input['netdata_cpu']) || isset($input['netdata_ram']) || isset($input['netdata_metrics']);
    }

    private function handleStandardMetrics(Device $device, array $input, ?string $ip): DeviceMetric
    {
        $cpu = $this->parseCpuMetric($input['cpu'] ?? null);
        $ram = $this->parseRamMetric($input['memory'] ?? $input['ram'] ?? null);
        $recordedAt = Carbon::parse($input['recorded_at'] ?? $input['timestamp'] ?? now());

        $metricData = [
            'device_id' => $device->id,
            'cpu' => $cpu,
            'ram' => $ram,
            'recorded_at' => $recordedAt,
            'agent_version' => $input['agent_version'] ?? null,
            'payload' => $input,
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

        $metric = DeviceMetric::create($metricData);

        $this->storeDiskMetrics($metric, $input['disks'] ?? null);
        $this->storeNetworkMetrics($metric, $input['network'] ?? null);
        $this->updateDeviceInfo($device, $input['system_info'] ?? null, $ip);

        (new EvaluateAlertRules)($device, $metric);

        return $metric;
    }

    private function handleRawNetdata(Device $device, array $input, ?string $ip): DeviceMetric
    {
        $recordedAt = isset($input['timestamp']) ? Carbon::parse($input['timestamp']) : now();

        $cpuParser = new NetdataV3Metrics($input['netdata_cpu'] ?? $input['netdata_metrics'] ?? []);
        $ramParser = new NetdataV3Metrics($input['netdata_ram'] ?? $input['netdata_metrics'] ?? []);
        $loadParser = new NetdataV3Metrics($input['netdata_load'] ?? []);
        $uptimeParser = new NetdataV3Metrics($input['netdata_uptime'] ?? []);

        $cpuDetails = $cpuParser->getCpuDetails();
        $memoryDetails = $ramParser->getMemoryDetails();
        $loadAverages = $loadParser->parseLoadAverages();
        $uptime = $uptimeParser->parseUptime();

        $metric = DeviceMetric::create([
            'device_id' => $device->id,
            'cpu' => $cpuParser->parseCpuUsage(),
            'ram' => $ramParser->parseRamUsage(),
            'recorded_at' => $recordedAt,
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
            'payload' => $input,
        ]);

        $this->updateDeviceInfoFromNetdata($device, $input['netdata_info'] ?? null, $ip);

        (new EvaluateAlertRules)($device, $metric);

        return $metric;
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

    private function storeDiskMetrics(DeviceMetric $metric, mixed $disks): void
    {
        if (! is_array($disks)) {
            return;
        }

        collect($disks)
            ->filter(fn (array $disk): bool => isset($disk['mount_point']))
            ->each(fn (array $disk) => $metric->diskMetrics()->create([
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

    private function storeNetworkMetrics(DeviceMetric $metric, mixed $network): void
    {
        if (! is_array($network)) {
            return;
        }

        collect($network)
            ->filter(fn (array $iface): bool => isset($iface['interface']))
            ->each(fn (array $iface) => $metric->networkMetrics()->create([
                'interface' => $iface['interface'],
                'received_kbps' => $iface['received_kbps'] ?? null,
                'sent_kbps' => $iface['sent_kbps'] ?? null,
                'received_bytes' => $iface['received_bytes'] ?? null,
                'sent_bytes' => $iface['sent_bytes'] ?? null,
            ]));
    }

    private function updateDeviceInfo(Device $device, mixed $systemInfo, ?string $ip): void
    {
        $updates = ['last_seen' => now(), 'last_ip' => $ip];

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

    private function updateDeviceInfoFromNetdata(Device $device, mixed $netdataInfo, ?string $ip): void
    {
        $updates = ['last_seen' => now(), 'last_ip' => $ip];

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
}
