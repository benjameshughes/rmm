<?php

namespace App\Http\Controllers\Api;

use App\DTOs\NetdataCpuMetric;
use App\DTOs\NetdataRamMetric;
use App\Http\Requests\MetricsRequest;
use App\Models\Device;
use App\Models\DeviceMetric;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class DeviceMetricsController extends Controller
{
    public function store(MetricsRequest $request): JsonResponse
    {
        $apiKey = $request->header('X-Device-Key') ?? $request->header('X-Agent-Key');
        if (is_string($apiKey)) {
            $apiKey = trim($apiKey);
        }
        if (! $apiKey) {
            Log::warning('api.metrics.unauthorized', [
                'reason' => 'missing_key',
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Missing device key.'], 401);
        }

        $device = Device::query()
            ->where('api_key', $apiKey)
            ->where('status', Device::STATUS_ACTIVE)
            ->first();

        if ($device === null) {
            Log::warning('api.metrics.unauthorized', [
                'reason' => 'invalid_or_revoked',
                'key_prefix' => is_string($apiKey) ? substr($apiKey, 0, 8) : null,
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Invalid or revoked device key.'], 401);
        }

        $input = $request->all();

        // Parse CPU - handle both v3 format (object with usage_percent) and legacy (netdata response)
        $cpu = $this->parseCpuMetric($input['cpu'] ?? null);

        // Parse RAM - handle both v3 format (object with usage_percent) and legacy (netdata response)
        $ram = $this->parseRamMetric($input['memory'] ?? $input['ram'] ?? null);

        // Parse extended metrics from v3 format
        $cpuData = $input['cpu'] ?? null;
        $load = $input['load'] ?? null;
        $uptime = $input['uptime'] ?? null;
        $alerts = $input['alerts'] ?? null;
        $memory = $input['memory'] ?? null;
        $processes = $input['processes'] ?? null;

        $recordedAt = $input['recorded_at'] ?? ($input['timestamp'] ?? null);
        $recordedAt = $recordedAt ? Carbon::parse($recordedAt) : now();

        // Build metric record
        $metricData = [
            'device_id' => $device->id,
            'cpu' => $cpu,
            'ram' => $ram,
            'recorded_at' => $recordedAt,
            'agent_version' => $input['agent_version'] ?? null,
        ];

        // Add CPU state details if present
        if (is_array($cpuData)) {
            $metricData['cpu_user'] = $cpuData['user'] ?? null;
            $metricData['cpu_system'] = $cpuData['system'] ?? null;
            $metricData['cpu_nice'] = $cpuData['nice'] ?? null;
            $metricData['cpu_iowait'] = $cpuData['iowait'] ?? null;
            $metricData['cpu_irq'] = $cpuData['irq'] ?? null;
            $metricData['cpu_softirq'] = $cpuData['softirq'] ?? null;
            $metricData['cpu_steal'] = $cpuData['steal'] ?? null;
            $metricData['cpu_idle'] = $cpuData['idle'] ?? null;
        }

        // Add load averages if present
        if (is_array($load)) {
            $metricData['load1'] = $load['load1'] ?? null;
            $metricData['load5'] = $load['load5'] ?? null;
            $metricData['load15'] = $load['load15'] ?? null;
        }

        // Add uptime if present
        if (is_array($uptime)) {
            $metricData['uptime_seconds'] = isset($uptime['seconds']) ? (int) $uptime['seconds'] : null;
        }

        // Add memory details if present
        if (is_array($memory)) {
            $metricData['memory_used_mib'] = $memory['used_mib'] ?? null;
            $metricData['memory_free_mib'] = $memory['free_mib'] ?? null;
            $metricData['memory_total_mib'] = $memory['total_mib'] ?? null;
            $metricData['memory_cached_mib'] = $memory['cached_mib'] ?? null;
            $metricData['memory_buffers_mib'] = $memory['buffers_mib'] ?? null;
            $metricData['memory_available_mib'] = $memory['available_mib'] ?? null;
        }

        // Add alerts if present
        if (is_array($alerts)) {
            $metricData['alerts_normal'] = $alerts['normal'] ?? null;
            $metricData['alerts_warning'] = $alerts['warning'] ?? null;
            $metricData['alerts_critical'] = $alerts['critical'] ?? null;
        }

        // Add process metrics if present
        if (is_array($processes)) {
            $metricData['processes_running'] = $processes['running'] ?? null;
            $metricData['processes_blocked'] = $processes['blocked'] ?? null;
            $metricData['processes_total'] = $processes['total'] ?? null;
        }

        // Store full payload for debugging/future use
        $metricData['payload'] = $input;

        $metric = DeviceMetric::create($metricData);

        // Store disk metrics if present
        if (isset($input['disks']) && is_array($input['disks'])) {
            foreach ($input['disks'] as $diskData) {
                if (isset($diskData['mount_point'])) {
                    $metric->diskMetrics()->create([
                        'mount_point' => $diskData['mount_point'],
                        'filesystem' => $diskData['filesystem'] ?? null,
                        'used_gb' => $diskData['used_gb'] ?? null,
                        'available_gb' => $diskData['available_gb'] ?? null,
                        'total_gb' => $diskData['total_gb'] ?? null,
                        'usage_percent' => $diskData['usage_percent'] ?? null,
                        'read_kbps' => $diskData['read_kbps'] ?? null,
                        'write_kbps' => $diskData['write_kbps'] ?? null,
                        'utilization_percent' => $diskData['utilization_percent'] ?? null,
                    ]);
                }
            }
        }

        // Store network metrics if present
        if (isset($input['network']) && is_array($input['network'])) {
            foreach ($input['network'] as $networkData) {
                if (isset($networkData['interface'])) {
                    $metric->networkMetrics()->create([
                        'interface' => $networkData['interface'],
                        'received_kbps' => $networkData['received_kbps'] ?? null,
                        'sent_kbps' => $networkData['sent_kbps'] ?? null,
                        'received_bytes' => $networkData['received_bytes'] ?? null,
                        'sent_bytes' => $networkData['sent_bytes'] ?? null,
                    ]);
                }
            }
        }

        // Update device info from system_info if present
        $systemInfo = $input['system_info'] ?? null;
        $deviceUpdates = [
            'last_seen' => now(),
            'last_ip' => $request->ip(),
        ];

        if (is_array($systemInfo)) {
            if (isset($systemInfo['os_name'])) {
                $deviceUpdates['os_name'] = $systemInfo['os_name'];
            }
            if (isset($systemInfo['os_version'])) {
                $deviceUpdates['os_version'] = $systemInfo['os_version'];
            }
            if (isset($systemInfo['netdata_version'])) {
                $deviceUpdates['netdata_version'] = $systemInfo['netdata_version'];
            }
            if (isset($systemInfo['kernel_name'])) {
                $deviceUpdates['kernel_name'] = $systemInfo['kernel_name'];
            }
            if (isset($systemInfo['kernel_version'])) {
                $deviceUpdates['kernel_version'] = $systemInfo['kernel_version'];
            }
            if (isset($systemInfo['architecture'])) {
                $deviceUpdates['architecture'] = $systemInfo['architecture'];
            }
            if (isset($systemInfo['virtualization'])) {
                $deviceUpdates['virtualization'] = $systemInfo['virtualization'];
            }
            if (isset($systemInfo['container'])) {
                $deviceUpdates['container'] = $systemInfo['container'];
            }
            if (isset($systemInfo['is_k8s_node'])) {
                $deviceUpdates['is_k8s_node'] = (bool) $systemInfo['is_k8s_node'];
            }
        }

        $device->forceFill($deviceUpdates)->save();

        Log::info('api.metrics', [
            'device_id' => $device->id,
            'cpu' => $cpu,
            'ram' => $ram,
            'load1' => $metricData['load1'] ?? null,
            'alerts_critical' => $metricData['alerts_critical'] ?? null,
            'agent_version' => $metricData['agent_version'] ?? null,
            'ip' => $request->ip(),
        ]);

        return response()->json(['message' => 'Metrics accepted.']);
    }

    /**
     * Parse CPU metric from either v3 format or legacy netdata response.
     */
    private function parseCpuMetric(mixed $input): ?float
    {
        if ($input === null) {
            return null;
        }

        // v3 format: object with usage_percent already calculated
        if (is_array($input) && isset($input['usage_percent'])) {
            $value = (float) $input['usage_percent'];

            return max(0.0, min(100.0, round($value, 2)));
        }

        // Legacy format: netdata response with labels/data arrays
        return (new NetdataCpuMetric($input))->getUsagePercent();
    }

    /**
     * Parse RAM metric from either v3 format or legacy netdata response.
     */
    private function parseRamMetric(mixed $input): ?float
    {
        if ($input === null) {
            return null;
        }

        // v3 format: object with usage_percent already calculated
        if (is_array($input) && isset($input['usage_percent'])) {
            $value = (float) $input['usage_percent'];

            return max(0.0, min(100.0, round($value, 2)));
        }

        // Legacy format: netdata response with labels/data arrays
        return (new NetdataRamMetric($input))->getUsagePercent();
    }
}
