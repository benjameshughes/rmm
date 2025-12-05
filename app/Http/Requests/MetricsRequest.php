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
            'ram' => ['nullable'],
            'payload' => ['nullable', 'array'],
            'recorded_at' => ['nullable', 'date'],
            'timestamp' => ['nullable', 'date'],
        ];
    }
}
