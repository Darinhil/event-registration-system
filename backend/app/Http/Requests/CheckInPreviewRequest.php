<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckInPreviewRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'event_id' => ['nullable', 'integer', 'min:1'],
            'entrance_token' => ['nullable', 'uuid'],
            'qr_token' => [
                'nullable',
                'uuid',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (empty($value) && empty($this->input('event_id')) && empty($this->input('entrance_token'))) {
                        $fail('Provide one of: qr_token, event_id, or entrance_token.');
                    }
                },
            ],
        ];
    }
}
