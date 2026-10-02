<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckInRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    protected function prepareForValidation(): void { if (! $this->filled('credential') && $this->filled('qr_token')) $this->merge(['credential' => $this->input('qr_token')]); }
    public function rules(): array { return ['credential' => ['required', 'string', 'max:2048'], 'qr_token' => ['nullable', 'uuid']]; }
}
