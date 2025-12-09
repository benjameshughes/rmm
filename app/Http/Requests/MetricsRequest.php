<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MetricsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>|string>
     */
    public function rules(): array
    {
        return [
            'cpu' => ['nullable'],
            'cpu.usage_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cpu.user' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cpu.system' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cpu.nice' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cpu.iowait' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cpu.irq' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cpu.softirq' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cpu.steal' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cpu.idle' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'ram' => ['nullable'],
            'memory' => ['nullable', 'array'],
            'memory.usage_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'memory.used_mib' => ['nullable', 'numeric', 'min:0'],
            'memory.free_mib' => ['nullable', 'numeric', 'min:0'],
            'memory.total_mib' => ['nullable', 'numeric', 'min:0'],
            'memory.cached_mib' => ['nullable', 'numeric', 'min:0'],
            'memory.buffers_mib' => ['nullable', 'numeric', 'min:0'],
            'memory.available_mib' => ['nullable', 'numeric', 'min:0'],
            'load' => ['nullable', 'array'],
            'load.load1' => ['nullable', 'numeric', 'min:0'],
            'load.load5' => ['nullable', 'numeric', 'min:0'],
            'load.load15' => ['nullable', 'numeric', 'min:0'],
            'uptime' => ['nullable', 'array'],
            'uptime.seconds' => ['nullable', 'integer', 'min:0'],
            'alerts' => ['nullable', 'array'],
            'alerts.normal' => ['nullable', 'integer', 'min:0'],
            'alerts.warning' => ['nullable', 'integer', 'min:0'],
            'alerts.critical' => ['nullable', 'integer', 'min:0'],
            'processes' => ['nullable', 'array'],
            'processes.running' => ['nullable', 'integer', 'min:0'],
            'processes.blocked' => ['nullable', 'integer', 'min:0'],
            'processes.total' => ['nullable', 'integer', 'min:0'],
            'disks' => ['nullable', 'array'],
            'disks.*.mount_point' => ['required_with:disks', 'string'],
            'disks.*.filesystem' => ['nullable', 'string'],
            'disks.*.used_gb' => ['nullable', 'numeric', 'min:0'],
            'disks.*.available_gb' => ['nullable', 'numeric', 'min:0'],
            'disks.*.total_gb' => ['nullable', 'numeric', 'min:0'],
            'disks.*.usage_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'disks.*.read_kbps' => ['nullable', 'numeric', 'min:0'],
            'disks.*.write_kbps' => ['nullable', 'numeric', 'min:0'],
            'disks.*.utilization_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'network' => ['nullable', 'array'],
            'network.*.interface' => ['required_with:network', 'string'],
            'network.*.received_kbps' => ['nullable', 'numeric', 'min:0'],
            'network.*.sent_kbps' => ['nullable', 'numeric', 'min:0'],
            'network.*.received_bytes' => ['nullable', 'integer', 'min:0'],
            'network.*.sent_bytes' => ['nullable', 'integer', 'min:0'],
            'system_info' => ['nullable', 'array'],
            'system_info.netdata_version' => ['nullable', 'string', 'max:50'],
            'system_info.os_name' => ['nullable', 'string', 'max:100'],
            'system_info.os_version' => ['nullable', 'string', 'max:100'],
            'system_info.kernel_name' => ['nullable', 'string', 'max:100'],
            'system_info.kernel_version' => ['nullable', 'string', 'max:100'],
            'system_info.architecture' => ['nullable', 'string', 'max:50'],
            'system_info.virtualization' => ['nullable', 'string', 'max:100'],
            'system_info.container' => ['nullable', 'string', 'max:100'],
            'system_info.is_k8s_node' => ['nullable', 'boolean'],
            'agent_version' => ['nullable', 'string', 'max:20'],
            'payload' => ['nullable', 'array'],
            'recorded_at' => ['nullable', 'date'],
            'timestamp' => ['nullable', 'date'],
        ];
    }
}
