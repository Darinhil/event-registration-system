<?php

namespace App\Services;

use App\Models\Registration;
use App\Models\User;
use App\Models\Event;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Carbon\Carbon;

class RegistrationService
{
    public function create(User $user, array $data): Registration
    {
        $event = Event::findOrFail($data['event_id']);
        $settings = $event->form_config['settings'] ?? [];
        $this->assertRegistrationWindow($event, $settings);

        $formData = $this->sanitizeFormData($data['form_data'] ?? []);
        $fields = $event->formFields()->get();

        // Mirror labeled answers onto the profile columns so admin tables,
        // exports, and check-in screens work without hardcoded field ids.
        $this->hydrateCoreFields($data, $fields, $formData);
        $data['form_data'] = $formData;

        $enabled = $event->enabled_fields ?: [];
        foreach (['address', 'position', 'profile_photo', 'emergency_contact_name', 'emergency_contact_phone'] as $optional) {
            if (($enabled[$optional] ?? false) !== true) unset($data[$optional]);
        }

        $registeredCount = $event->registrations()->count();
        $max = (int) ($settings['max_participants'] ?? $event->capacity);
        if ($registeredCount >= $max) throw ValidationException::withMessages(['event_id' => 'This event is full.']);

        // Report duplicate email and one-per-account independently — both may apply.
        $conflicts = [];
        if (!empty($data['email']) && Registration::where('event_id', $event->id)->where('email', $data['email'])->exists()) {
            $conflicts['email'] = 'Already registered for this event.';
        }
        if ((bool) ($settings['one_per_account'] ?? true) && $user->registrations()->where('event_id', $event->id)->exists()) {
            $conflicts['event_id'] = 'You are already registered for this event.';
        }
        if ($conflicts !== []) throw ValidationException::withMessages($conflicts);

        $this->storeFieldUploads($formData);
        $data['form_data'] = $formData;

        if (!empty($data['profile_photo']) && $data['profile_photo'] instanceof UploadedFile) {
            $data['profile_photo'] = $data['profile_photo']->store('profile-photos', 'public');
        } else {
            unset($data['profile_photo']);
        }

        $data['event_name'] = $event->name; $data['event_date'] = $event->starts_at; $data['event_location'] = $event->location;
        $data['disability_type'] = json_encode($data['disability_type'] ?? [], JSON_THROW_ON_ERROR);

        return $user->registrations()->create([...$data, 'registration_code' => $this->nextRegistrationCode($event), 'qr_token' => (string) Str::uuid(), 'status' => 'confirmed']);
    }

    public function update(Registration $registration, array $data): Registration
    {
        $event = $registration->event;
        $formData = $this->sanitizeFormData($data['form_data'] ?? []);

        // Use the same conversion as create(). In particular, age-group choices
        // such as "K: Under 18" must never be written to the integer `age` column.
        $this->hydrateCoreFields($data, $event->formFields()->get(), $formData);

        if (!empty($data['email']) && Registration::where('event_id', $event->id)->where('email', $data['email'])->where('id', '!=', $registration->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'Already registered for this event.']);
        }

        $this->storeFieldUploads($formData);
        $data['form_data'] = $formData;

        unset($data['event_id'], $data['user_id'], $data['registration_code'], $data['qr_token'], $data['status']);
        $data['event_name'] = $event->name;
        $data['event_date'] = $event->starts_at;
        $data['event_location'] = $event->location;
        $data['disability_type'] = json_encode($data['disability_type'] ?? [], JSON_THROW_ON_ERROR);
        $registration->fill($data)->save();

        return $registration->fresh();
    }

    /** Next unused per-event registration code (count()+1 collides after deletions/backfills). */
    private function nextRegistrationCode(Event $event): string
    {
        $base = strtoupper(Str::slug($event->name));
        $n = 1;
        while (Registration::where('event_id', $event->id)->where('registration_code', $base.'-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT))->exists()) {
            $n++;
        }

        return $base.'-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }

    /** Reject values that are not scalars/arrays (never trust client JSON blobs). */
    private function sanitizeFormData($formData): array
    {
        if (! is_array($formData)) return [];
        foreach ($formData as $key => $value) {
            if ($value instanceof UploadedFile) continue;
            if (is_array($value)) {
                $formData[$key] = array_map(fn ($item) => is_scalar($item) ? (string) $item : '', array_values($value));
                continue;
            }
            if (! is_scalar($value)) $formData[$key] = null;
        }
        return $formData;
    }

    /** Persist UploadedFile answers (e.g. builder "file" fields) and store their paths. */
    private function storeFieldUploads(array &$formData): void
    {
        foreach ($formData as $key => $value) {
            if ($value instanceof UploadedFile) {
                $formData[$key] = $value->store('form-uploads', 'public');
            }
        }
    }

    /** Copy dynamic form answers to legacy registration columns safely. */
    private function hydrateCoreFields(array &$data, $fields, array $formData): void
    {
        $fillable = (new Registration)->getFillable();
        $coreMap = [
            'full_name' => 'full_name', 'name' => 'full_name',
            'email' => 'email', 'email_address' => 'email',
            'phone' => 'phone', 'phone_number' => 'phone', 'tel' => 'phone', 'telephone' => 'phone',
            'organization' => 'organization', 'organisation' => 'organization', 'institution' => 'organization',
            'position' => 'position', 'address' => 'address',
        ];

        foreach ($fields as $field) {
            $value = $formData[$field->id] ?? null;
            if ($value === null || $value === '' || $value === []) continue;
            $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $field->label));
            $column = $coreMap[$normalized] ?? (in_array($normalized, $fillable, true) ? $normalized : null);

            if ($column === 'gender') {
                $first = is_array($value) ? ($value[0] ?? '') : $value;
                $prefix = strtolower(trim(explode(':', (string) $first)[0]));
                $data['gender'] = match (true) {
                    in_array($prefix, ['m', 'male'], true) => 'male',
                    in_array($prefix, ['f', 'female'], true) => 'female',
                    default => 'other',
                };
                continue;
            }

            if ($column === 'age' || $normalized === 'age_group') {
                if (is_scalar($value) && is_numeric($value)) {
                    $data['age'] = (int) $value;
                } else {
                    $data['age'] = null;
                    $data['age_group'] = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
                }
                continue;
            }

            if ($column === 'disability_type' || str_contains($normalized, 'disability')) {
                $codes = [];
                foreach ((array) $value as $item) {
                    $code = strtoupper(trim(explode(':', (string) $item)[0]));
                    if (preg_match('/^[A-Z]{1,2}$/', $code)) $codes[] = $code;
                }
                if ($codes !== []) $data['disability_type'] = $codes;
                continue;
            }

            if ($column !== null && !isset($data[$column]) && is_scalar($value)) $data[$column] = $value;
        }
    }

    /** Enforce the optional registration window configured in the builder settings. */
    private function assertRegistrationWindow(Event $event, array $settings): void
    {
        $start = $settings['registration_start'] ?? null;
        $end = $settings['registration_end'] ?? null;

        if ($start && Carbon::parse($start)->isAfter(now())) {
            throw ValidationException::withMessages(['event_id' => 'Registration for this event has not opened yet.']);
        }
        if ($end && Carbon::parse($end)->endOfDay()->isPast()) {
            throw ValidationException::withMessages(['event_id' => 'Registration for this event has closed.']);
        }
    }
}
