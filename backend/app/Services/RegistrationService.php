<?php

namespace App\Services;

use App\Models\Registration;
use App\Models\User;
use App\Models\Event;
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
            $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $field->label));
            if ($value !== null && $value !== '') $formData[$field->id] = $value;
            if (!isset($data[$normalized]) && $value !== null) $data[$normalized] = $value;
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
}
