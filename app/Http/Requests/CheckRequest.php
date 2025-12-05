<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>|string>
     */
    public function rules(): array
    {
        return [
            'hostname' => ['nullable', 'string', 'max:255', 'required_without:hardware_fingerprint'],
            'hardware_fingerprint' => ['nullable', 'string', 'max:255', 'required_without:hostname'],
        ];
    }
}

