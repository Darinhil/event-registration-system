<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;

class RegistrationRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $rules = [
            'event_id' => ['required', 'integer', 'exists:events,id'], 'form_data' => ['nullable', 'array'],
            'full_name' => ['required_without:form_data', 'nullable', 'string', 'min:2', 'max:100'], 'gender' => ['required_without:form_data', 'nullable', 'in:male,female,other'], 'age' => ['required_without:form_data', 'nullable', 'integer', 'between:1,120'],
            'phone' => ['required_without:form_data', 'nullable', 'string', 'regex:/^[+]?[0-9][0-9\s().-]{7,24}$/'], 'email' => ['required_without:form_data', 'nullable', 'email:rfc', 'max:150'],
            'organization' => ['required_without:form_data', 'nullable', 'string', 'min:2', 'max:150'], 'address' => ['nullable', 'string', 'max:500'],
            'position' => ['nullable', 'string', 'max:150'], 'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'emergency_contact_name' => ['nullable', 'string', 'max:100'], 'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'disability_type' => ['nullable', 'array'], 'disability_type.*' => ['string', 'in:C,H,M,R,S'], 'photo_consent' => ['boolean'], 'signature' => ['nullable', 'string', 'max:100000'],
        ];
        $event = Event::with('formFields')->find($this->input('event_id'));
        foreach ($event?->formFields ?? [] as $field) {
            $key = "form_data.{$field->id}";
            $rules[$key] = [$field->required ? 'required' : 'nullable'];
            if ($field->type === 'email') $rules[$key][] = 'email';
            if ($field->type === 'number') $rules[$key][] = 'numeric';
            if ($field->type === 'date') $rules[$key][] = 'date';
            if (in_array($field->type, ['text', 'textarea', 'select', 'radio', 'phone'], true)) $rules[$key][] = 'string';
        }
        return $rules;
    }
}
