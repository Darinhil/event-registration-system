<?php

namespace App\Http\Requests;

use App\Models\Event;
use App\Support\FormConditions;
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

        // Editing keeps previously uploaded files when no new file is sent.
        $isUpdate = $this->route('registration') !== null;

        $fieldsById = FormConditions::index($event?->formFields ?? []);
        $answers = is_array($this->input('form_data')) ? $this->input('form_data') : [];

        foreach ($event?->formFields ?? [] as $field) {
            if ($field->type === 'heading') continue;
            $key = "form_data.{$field->id}";
            // Questions hidden by a condition were never asked, so they can't be required.
            if (! FormConditions::visible($field, $fieldsById, $answers)) { $rules[$key] = ['exclude']; continue; }
            $required = $field->required ? 'required' : 'nullable';
            $settings = $field->settings ?? [];
            $options = is_array($field->options) && $field->options !== [] ? $field->options : null;
            $multiple = (bool) ($settings['multiple'] ?? false);

            // Multi-choice answers (checkbox groups and multi-select dropdowns) arrive as arrays.
            if (in_array($field->type, ['checkbox', 'select'], true) && $multiple) {
                $rules[$key] = [$required, 'array', 'max:100'];
                $rules["{$key}.*"] = $options ? ['string', Rule::in($options)] : ['string', 'max:500'];
                continue;
            }
            // A single required checkbox (e.g. consent) must actually be ticked.
            if ($field->type === 'checkbox') {
                $rules[$key] = $field->required ? ['accepted'] : ['nullable', 'boolean'];
                continue;
            }
            if ($field->type === 'file') {
                $allowed = array_values(array_filter((array) ($settings['allowed_types'] ?? ['pdf', 'jpg', 'png'])));
                $maxKb = (int) round(((float) ($settings['max_size_mb'] ?? 5)) * 1024);
                $rules[$key] = [$isUpdate ? 'nullable' : $required, 'nullable', 'file', 'max:'.max($maxKb, 1), 'mimes:'.implode(',', $allowed ?: ['pdf', 'jpg', 'png'])];
                continue;
            }

            $rules[$key] = [$required];
            match ($field->type) {
                'email' => array_push($rules[$key], 'string', 'email:rfc', 'max:150'),
                'phone' => array_push($rules[$key], 'string', 'regex:/^[+]?[0-9][0-9\s().-]{7,24}$/'),
                'number' => array_push($rules[$key], 'numeric', ...$this->bounds($settings, 'min_value', 'max_value')),
                'date' => array_push($rules[$key], 'date'),
                'time' => array_push($rules[$key], 'date_format:H:i'),
                'url' => array_push($rules[$key], 'url', 'max:500'),
                'textarea' => array_push($rules[$key], 'string', 'min:'.(int) ($settings['min_length'] ?? 0), 'max:'.(int) ($settings['max_length'] ?? 5000)),
                'select', 'radio', 'yesno', 'autocomplete', 'country' => array_push($rules[$key], 'string', 'max:500'),
                default => array_push($rules[$key], 'string', 'min:'.(int) ($settings['min_length'] ?? 0), 'max:'.(int) ($settings['max_length'] ?? 500)),
            };
            // Choices must be one of the options the organiser configured.
            if (in_array($field->type, ['select', 'radio', 'yesno'], true) && ($options || $field->type === 'yesno')) {
                $rules[$key][] = Rule::in($options ?? ['Yes', 'No']);
            }
        }
        return $rules;
    }

    /** Numeric min/max rules from optional builder settings. */
    private function bounds(array $settings, string $minKey, string $maxKey): array
    {
        $rules = [];
        if (isset($settings[$minKey]) && is_numeric($settings[$minKey])) $rules[] = 'min:'.$settings[$minKey];
        if (isset($settings[$maxKey]) && is_numeric($settings[$maxKey])) $rules[] = 'max:'.$settings[$maxKey];
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
