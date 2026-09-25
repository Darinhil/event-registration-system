<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckInRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['credential' => ['required', 'string', 'max:2048']]; }
}
