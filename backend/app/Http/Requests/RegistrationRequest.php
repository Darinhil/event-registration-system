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
            'event_id' => ['required', 'integer', 'exists:events,id'],
            'form_data' => ['nullable', 'array'],
            'full_name' => ['required_without:form_data', 'nullable', 'string', 'min:2', 'max:100'],
            'gender' => ['required_without:form_data', 'nullable', 'in:male,female,other'],
            'age' => ['required_without:form_data', 'nullable', 'integer', 'between:1,120'],
            'phone' => ['required_without:form_data', 'nullable', 'string', 'regex:/^[+]?[0-9][0-9\\s().-]{7,24}$/'],
            'email' => ['required_without:form_data', 'nullable', 'email:rfc', 'max:150'],
            'organization' => ['required_without:form_data', 'nullable', 'string', 'min:2', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'position' => ['nullable', 'string', 'max:150'],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'emergency_contact_name' => ['nullable', 'string', 'max:100'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'disability_type' => ['nullable', 'array'],
            'disability_type.*' => ['string', 'in:C,H,M,R,S'],
            'photo_consent' => ['boolean'],
            'signature' => ['nullable', 'string', 'max:100000'],
        ];

        $event = Event::with('formFields')->find($this->input('event_id'));

        foreach ($event?->formFields ?? [] as $field) {
            $key = "form_data.{$field->id}";
            $rules[$key] = [$field->required ? 'required' : 'nullable'];

            switch ($field->type) {
                case 'email':
                    $rules[$key][] = 'email:rfc';
                    break;
                case 'number':
                    $rules[$key][] = 'numeric';
                    if (isset($field->settings['min_value'])) $rules[$key][] = 'min:' . $field->settings['min_value'];
                    if (isset($field->settings['max_value'])) $rules[$key][] = 'max:' . $field->settings['max_value'];
                    break;
                case 'date':
                    $rules[$key][] = 'date';
                    break;
                case 'time':
                    $rules[$key][] = 'date_format:H:i';
                    break;
                case 'url':
                    $rules[$key][] = 'url';
                    break;
                case 'phone':
                    $rules[$key][] = 'regex:/^[+]?[0-9][0-9\\s().-]{7,24}$/';
                    break;
                case 'file':
                    $rules[$key][] = 'file';
                    $mimes = is_array($field->settings['allowed_types'] ?? null) ? $field->settings['allowed_types'] : ['pdf', 'jpg', 'png'];
                    $rules[$key][] = 'mimes:' . implode(',', $mimes);
                    $maxMb = (int) ($field->settings['max_size_mb'] ?? 5);
                    $rules[$key][] = 'max:' . max(1, min(50, $maxMb)) * 1024;
                    break;
                case 'address':
                case 'country':
                    $rules[$key][] = 'string';
                    if ($field->type === 'country' && is_array($field->options) && $field->options !== []) {
                        $rules[$key][] = Rule::in($field->options);
                    }
                    break;
                case 'heading':
                    array_pop($rules[$key]); // headings carry no answer; drop required/nullable
                    break;
            }

            if (in_array($field->type, ['text', 'textarea', 'select', 'radio', 'autocomplete'], true)) {
                $rules[$key][] = 'string';
            }
            if (in_array($field->type, ['text', 'textarea'], true)) {
                if (isset($field->settings['min_length'])) $rules[$key][] = 'min:' . $field->settings['min_length'];
                if (isset($field->settings['max_length'])) $rules[$key][] = 'max:' . $field->settings['max_length'];
            }
            if (in_array($field->type, ['select', 'radio', 'checkbox', 'yesno', 'autocomplete'], true) && is_array($field->options) && $field->options !== []) {
                // Multi-select dropdowns and multi-checkboxes submit arrays of
                // options; single checkboxes submit a boolean (no option list).
                $expectsArray = (
                    ($field->type === 'select' || $field->type === 'checkbox')
                    && (bool) ($field->settings['multiple'] ?? false)
                );
                if ($expectsArray) {
                    $rules[$key][] = 'array';
                    $rules[$key.'.*'] = ['string', Rule::in($field->options)];
                } elseif ($field->type !== 'checkbox') {
                    $rules[$key][] = Rule::in($field->options);
                }
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
