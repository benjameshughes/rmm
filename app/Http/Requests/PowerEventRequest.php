<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\DevicePowerState;
use App\Enums\PowerEventReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PowerEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A reason only makes sense for its own event: a device does not power off to resume.
     */
    public function rules(): array
    {
        $event = is_string($this->input('event')) ? DevicePowerState::tryFrom($this->input('event')) : null;

        return [
            'event' => ['required', 'string', Rule::enum(DevicePowerState::class)],
            'reason' => ['required', 'string', Rule::enum(PowerEventReason::class)->only($event?->reasons() ?? PowerEventReason::cases())],
        ];
    }

    public function messages(): array
    {
        return [
            'event.required' => 'Say whether the device is powering off or powering on.',
            'event.enum' => 'The event must be powering_off or powering_on.',
            'reason.required' => 'Say why the device is changing power state.',
            'reason.enum' => 'That reason does not match the event: powering_off takes sleep, standby or shutdown; powering_on takes resume or boot.',
        ];
    }

    public function powerState(): DevicePowerState
    {
        return DevicePowerState::from($this->validated('event'));
    }

    public function reason(): PowerEventReason
    {
        return PowerEventReason::from($this->validated('reason'));
    }
}
