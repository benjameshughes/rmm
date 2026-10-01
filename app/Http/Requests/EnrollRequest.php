<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class EnrollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'hostname' => ['required', 'string', 'max:255'],
            'os' => ['nullable', 'string', 'max:255'],
            'os_name' => ['nullable', 'string', 'max:255'],
            'os_version' => ['nullable', 'string', 'max:255'],
            'cpu_model' => ['nullable', 'string', 'max:255'],
            'cpu_cores' => ['nullable', 'integer', 'min:1'],
            'total_ram_gb' => ['nullable', 'numeric', 'min:0'],
            'total_ram_bytes' => ['nullable', 'integer', 'min:0'],
            'disks' => ['nullable', 'array'],
            'disks.*.name' => ['required', 'string'],
            'disks.*.mount_point' => ['nullable', 'string'],
            'disks.*.total_bytes' => ['nullable', 'integer'],
            'disks.*.available_bytes' => ['nullable', 'integer'],
            'disks.*.total_gb' => ['nullable', 'numeric'],
            'disks.*.available_gb' => ['nullable', 'numeric'],
            'hardware_fingerprint' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'hostname.required' => 'Hostname is required for enrollment.',
            'hardware_fingerprint.required' => 'A hardware fingerprint is required for enrollment.',
        ];
    }
}
