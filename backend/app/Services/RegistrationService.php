<?php

namespace App\Services;

use App\Models\Registration;
use App\Models\User;
use App\Models\Event;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class RegistrationService
{
    public function create(User $user, array $data): Registration
    {
        $event = Event::findOrFail($data['event_id']);
        $formData = $data['form_data'] ?? [];
        $fields = $event->formFields()->get();
        foreach ($fields as $field) {
            $value = $formData[$field->id] ?? null;
            if ($field->type === 'file' && $value instanceof UploadedFile) {
                $formData[$field->id] = $value->store('registration-files', 'public');
                $value = $formData[$field->id];
            }
            $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $field->label));
            if ($normalized === 'name') $normalized = 'full_name';
            if ($normalized === 'tel') $normalized = 'phone';
            if ($normalized === 'institution') $normalized = 'organization';
            if ($normalized === 'gender') {
                $genderCode = strtoupper(trim(explode(':', (string) $value, 2)[0]));
                $data['gender'] = match ($genderCode) {
                    'M', 'MALE' => 'male',
                    'F', 'FEMALE' => 'female',
                    'P', 'N', 'OTHER' => 'other',
                    default => $data['gender'] ?? null,
                };
                $normalized = '';
            }
            if ($normalized === 'disability' && is_array($value)) {
                $data['disability_type'] = array_map(
                    fn ($answer) => strtoupper(trim(explode(':', (string) $answer, 2)[0])),
                    $value,
                );
                $normalized = '';
            }
            if ($normalized === 'photo_request_and_use') {
                $data['photo_consent'] = strtolower((string) $value) === 'yes';
                $normalized = '';
            }
            if ($normalized === 'age' && ! is_numeric($value)) {
                $data['age_group'] = $value;
                $normalized = '';
            }
            if ($value !== null && $value !== '') $formData[$field->id] = $value;
            if ($normalized !== '' && !isset($data[$normalized]) && $value !== null) $data[$normalized] = $value;
        }
        $data['form_data'] = $formData;
        $enabled = $event->enabled_fields ?: [];
        foreach (['address', 'position', 'profile_photo', 'emergency_contact_name', 'emergency_contact_phone'] as $optional) {
            if (($enabled[$optional] ?? false) !== true) unset($data[$optional]);
        }
        if ($event->registrations()->count() >= $event->capacity) throw ValidationException::withMessages(['event_id' => 'This event is full.']);
        if (!empty($data['email']) && Registration::where('event_id', $event->id)->where('email', $data['email'])->exists()) throw ValidationException::withMessages(['email' => 'Already registered for this event.']);
        if (! empty($data['profile_photo'])) $data['profile_photo'] = $data['profile_photo']->store('profile-photos', 'public');
        $data['event_name'] = $event->name; $data['event_date'] = $event->starts_at; $data['event_location'] = $event->location;
        $data['disability_type'] = json_encode($data['disability_type'] ?? [], JSON_THROW_ON_ERROR);
        $registration = $user->registrations()->create([...$data, 'registration_code' => strtoupper(Str::slug($event->name)).'-'.str_pad((string) ($event->registrations()->count() + 1), 3, '0', STR_PAD_LEFT), 'qr_token' => (string) Str::uuid()]);
        return $registration;
    }

    public function update(Registration $registration, array $data): Registration
    {
        $event = $registration->event;
        $formData = $data['form_data'] ?? [];
        foreach ($event->formFields()->get() as $field) {
            $value = $formData[$field->id] ?? null;
            $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $field->label));
            if ($value !== null && $value !== '') $formData[$field->id] = $value;
            if (!isset($data[$normalized]) && $value !== null) $data[$normalized] = $value;
        }
        if (!empty($data['email']) && Registration::where('event_id', $event->id)->where('email', $data['email'])->where('id', '!=', $registration->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'Already registered for this event.']);
        }
        unset($data['event_id'], $data['user_id'], $data['registration_code'], $data['qr_token'], $data['status']);
        $data['form_data'] = $formData;
        $data['event_name'] = $event->name;
        $data['event_date'] = $event->starts_at;
        $data['event_location'] = $event->location;
        $data['disability_type'] = json_encode($data['disability_type'] ?? [], JSON_THROW_ON_ERROR);
        $registration->fill($data)->save();
        return $registration->fresh();
    }
}
