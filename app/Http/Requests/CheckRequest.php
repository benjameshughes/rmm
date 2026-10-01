<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'hostname' => ['nullable', 'string', 'max:255', 'required_without:hardware_fingerprint'],
            'hardware_fingerprint' => ['nullable', 'string', 'max:255', 'required_without:hostname'],
        ];
    }
}
