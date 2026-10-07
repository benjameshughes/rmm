<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTOs\Printers\PrinterSnapshot;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Every print queue on a Windows PC, posted by its agent on each spooler
 * change and once a minute. Status fields are raw Win32 bit flags.
 */
final class PrintersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'hostname' => ['nullable', 'string', 'max:255'],
            'agent_version' => ['nullable', 'string', 'max:50'],
            'trigger' => ['required', 'string', 'in:change,tick'],
            'collected_at' => ['required', 'date'],
            'spooler_available' => ['required', 'boolean'],
            'printers' => ['present', 'array', 'max:'.config('printers.max_printers')],
            'printers.*.name' => ['required', 'string', 'max:255', 'distinct'],
            'printers.*.port_name' => ['nullable', 'string', 'max:255'],
            'printers.*.driver_name' => ['nullable', 'string', 'max:255'],
            'printers.*.status' => ['required', 'integer', 'min:0'],
            'printers.*.attributes' => ['nullable', 'integer', 'min:0'],
            'printers.*.jobs_count' => ['required', 'integer', 'min:0'],
            'printers.*.jobs' => ['present', 'array', 'max:'.config('printers.max_jobs_per_printer')],
            'printers.*.jobs.*.id' => ['required', 'integer', 'min:0'],
            'printers.*.jobs.*.document' => ['nullable', 'string', 'max:1000'],
            'printers.*.jobs.*.user_name' => ['nullable', 'string', 'max:255'],
            'printers.*.jobs.*.status' => ['required', 'integer', 'min:0'],
            'printers.*.jobs.*.status_text' => ['nullable', 'string', 'max:255'],
            'printers.*.jobs.*.submitted' => ['nullable', 'date'],
            'printers.*.jobs.*.total_pages' => ['nullable', 'integer', 'min:0'],
            'printers.*.jobs.*.pages_printed' => ['nullable', 'integer', 'min:0'],
            'printers.*.jobs.*.size' => ['nullable', 'integer', 'min:0'],
            'printers.*.jobs.*.position' => ['nullable', 'integer', 'min:0'],
            'printers.*.jobs.*.priority' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'trigger.in' => 'The trigger must be change or tick.',
            'collected_at.required' => 'Say when the snapshot was taken.',
            'collected_at.date' => 'collected_at must be an ISO-8601 date.',
            'spooler_available.required' => 'Say whether the print spooler answered.',
            'printers.present' => 'Send the printers list, even when it is empty.',
            'printers.max' => 'A snapshot may hold at most :max printers.',
            'printers.*.name.required' => 'Every printer needs its name.',
            'printers.*.name.distinct' => 'Each printer may only appear once.',
            'printers.*.status.required' => 'Every printer needs its raw status.',
            'printers.*.status.integer' => 'A printer status must be the raw PRINTER_STATUS bit flags.',
            'printers.*.jobs.present' => 'Send each printer\'s jobs list, even when it is empty.',
            'printers.*.jobs.max' => 'A printer may report at most :max jobs.',
            'printers.*.jobs.*.id.required' => 'Every job needs its ID.',
            'printers.*.jobs.*.status.integer' => 'A job status must be the raw JOB_STATUS bit flags.',
        ];
    }

    public function snapshot(): PrinterSnapshot
    {
        return PrinterSnapshot::fromArray($this->validated(), config('printers.ignored_names'));
    }
}
