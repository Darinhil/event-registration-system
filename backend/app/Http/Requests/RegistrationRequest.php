<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            $required = $field->required ? 'required' : 'nullable';

            // Multi-select checkboxes arrive as arrays of option values.
            if ($field->type === 'checkbox' && (bool) ($field->settings['multiple'] ?? false)) {
                $rules[$key] = [$required, 'array'];
                if (is_array($field->options) && $field->options !== []) {
                    $rules["{$key}.*"] = [\Illuminate\Validation\Rule::in($field->options)];
                }
                continue;
            }
            // Single checkboxes arrive as booleans ("1"/"0" in multipart).
            if ($field->type === 'checkbox') {
                $rules[$key] = [$required, 'boolean'];
                continue;
            }

            $rules[$key] = [$required];
            if ($field->type === 'email') $rules[$key][] = 'email:rfc';
            if ($field->type === 'number') $rules[$key][] = 'numeric';
            if ($field->type === 'date') $rules[$key][] = 'date';
            if ($field->type === 'time') $rules[$key][] = 'date_format:H:i';
            if ($field->type === 'url') $rules[$key][] = 'url';
            if (in_array($field->type, ['text', 'textarea', 'select', 'radio', 'phone', 'yesno', 'country'], true)) $rules[$key][] = 'string';
            if (in_array($field->type, ['select', 'radio', 'yesno'], true) && is_array($field->options) && $field->options !== []) {
                $rules[$key][] = \Illuminate\Validation\Rule::in($field->options);
            }
        }
        return $rules;
    }

    protected function prepareForValidation(): void
    {
        // Multipart submissions stringify booleans and drop empty strings for
        // unchecked boxes; coerce "[true]" single-checkbox values to booleans.
        $formData = $this->input('form_data', []);
        if (! is_array($formData)) return;

        foreach ($formData as $key => $value) {
            if ($value === 'true') $formData[$key] = true;
            if ($value === 'false') $formData[$key] = false;
        }
        $this->merge(['form_data' => $formData]);
    }
}
