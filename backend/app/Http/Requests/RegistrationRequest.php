<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegistrationRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return ['event_name' => ['required', 'string', 'max:150'], 'event_date' => ['required', 'date', 'after:now']];
    }
}