<?php

namespace App\Services;

use App\Models\Registration;
use App\Models\User;
use App\Models\Event;
use App\Support\FormConditions;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class RegistrationService
{
    public function create(User $user, array $data): Registration
    {
        // Lock the event row so two people can't both take the last spot or
        // register the same email at the same moment.
        return DB::transaction(fn () => $this->createLocked($user, $data));
    }

    private function createLocked(User $user, array $data): Registration
    {
        $event = Event::whereKey($data['event_id'])->lockForUpdate()->firstOrFail();
        $settings = $event->form_config['settings'] ?? [];
        if ($reason = $this->closedReason($event)) {
            throw ValidationException::withMessages(['event_id' => $reason]);
        }

        $fields = $event->formFields()->get();
        $formData = $this->sanitizeFormData($data['form_data'] ?? [], $fields);

        // Mirror labeled answers onto the profile columns so admin tables,
        // exports, and check-in screens work without hardcoded field ids.
        $this->hydrateCoreFields($data, $fields, $formData);
        $data['form_data'] = $formData;

        $enabled = $event->enabled_fields ?: [];
        foreach (['address', 'position', 'profile_photo', 'emergency_contact_name', 'emergency_contact_phone'] as $optional) {
            if (($enabled[$optional] ?? false) !== true) unset($data[$optional]);
        }


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
        $fields = $event->formFields()->get();
        $formData = $this->sanitizeFormData($data['form_data'] ?? [], $fields);

        // Use the same conversion as create(). In particular, age-group choices
        // such as "K: Under 18" must never be written to the integer `age` column.
        $this->hydrateCoreFields($data, $fields, $formData);

        if (!empty($data['email']) && Registration::where('event_id', $event->id)->where('email', $data['email'])->where('id', '!=', $registration->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'Already registered for this event.']);
        }

        $replacedFiles = $this->keepOrReplaceFiles($registration, $fields, $formData);
        $this->storeFieldUploads($formData);
        Storage::disk('public')->delete($replacedFiles);
        $data['form_data'] = $formData;

        unset($data['event_id'], $data['user_id'], $data['registration_code'], $data['qr_token'], $data['status']);
        $data['event_name'] = $event->name;
        $data['event_date'] = $event->starts_at;
        $data['event_location'] = $event->location;
        $data['disability_type'] = json_encode($data['disability_type'] ?? [], JSON_THROW_ON_ERROR);
        $registration->fill($data)->save();

        return $registration->fresh();
    }

    /**
     * An edit doesn't re-send stored files: an empty answer keeps the existing file, and
     * a new upload replaces it. Returns the paths of the files being replaced.
     */
    private function keepOrReplaceFiles(Registration $registration, $fields, array &$formData): array
    {
        $previous = (array) $registration->form_data;
        $byId = FormConditions::index($fields);
        $replaced = [];
        foreach ($fields as $field) {
            $old = $previous[$field->id] ?? null;
            if ($field->type !== 'file' || ! is_string($old) || $old === '') continue;
            if (($formData[$field->id] ?? null) instanceof UploadedFile) $replaced[] = $old;
            elseif (FormConditions::visible($field, $byId, $formData)) $formData[$field->id] = $old;
        }

        return $replaced;
    }

    /**
     * Next code for this event's name. registration_code is unique across all events, so
     * events that share a name share the sequence. Names with no Latin letters (e.g. Khmer)
     * have no slug, so they use the event id.
     */
    private function nextRegistrationCode(Event $event): string
    {
        $base = preg_match('/[a-z0-9]/i', (string) $event->name) ? strtoupper(Str::slug($event->name)) : "EVENT-{$event->id}";
        $last = (string) Registration::where('registration_code', 'like', "{$base}-%")->latest('id')->value('registration_code');
        $n = preg_match('/^'.preg_quote($base, '/').'-(\d+)$/', $last, $match) ? (int) $match[1] + 1 : 1;
        $code = fn () => $base.'-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
        while (Registration::where('registration_code', $code())->exists()) {
            $n++;
        }

        return $code();
    }

    /**
     * Keep only answers for this event's own fields, and reject values that are
     * not scalars/arrays (never trust client JSON blobs).
     */
    private function sanitizeFormData($formData, $fields): array
    {
        if (! is_array($formData)) return [];
        $allowed = collect($fields)->reject(fn ($field) => $field->type === 'heading')->map(fn ($field) => (string) $field->id)->all();
        $formData = array_intersect_key($formData, array_flip($allowed));
        // Drop answers to questions a condition hid (e.g. stale values after changing an earlier answer).
        $byId = FormConditions::index($fields);
        $formData = array_filter($formData, fn ($key) => ! isset($byId[(string) $key]) || FormConditions::visible($byId[(string) $key], $byId, $formData), ARRAY_FILTER_USE_KEY);
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
        $nameParts = ['first' => null, 'last' => null];

        foreach ($fields as $field) {
            $value = $formData[$field->id] ?? null;
            if ($value === null || $value === '' || $value === []) continue;
            // Bilingual labels such as "ឈ្មោះ/Name" retain their English
            // identifier after Khmer characters and punctuation are removed.
            $normalized = trim(strtolower(preg_replace('/[^a-z0-9]+/i', '_', $field->label)), '_');
            $column = $coreMap[$normalized] ?? (in_array($normalized, $fillable, true) ? $normalized : $this->columnForLabel($normalized));

            // Forms that ask for first name and surname separately still get a full name for lists, search and passes.
            if (in_array($normalized, ['first_name', 'given_name'], true) && is_scalar($value)) { $nameParts['first'] = trim((string) $value); continue; }
            if (in_array($normalized, ['surname', 'last_name', 'family_name'], true) && is_scalar($value)) { $nameParts['last'] = trim((string) $value); continue; }

            if ($column === 'gender') {
                $first = strtolower(trim((string) (is_array($value) ? ($value[0] ?? '') : $value)));
                // Answers look like "F: ស្រី/Female", "ស្រី/Female" or "Female": read the code, else the English word.
                $key = str_contains($first, ':') ? trim(explode(':', $first)[0]) : trim(Str::afterLast($first, '/'));
                $data['gender'] = match (true) {
                    in_array($key, ['m', 'male', 'ប្រុស'], true) => 'male',
                    in_array($key, ['f', 'female', 'ស្រី'], true) => 'female',
                    default => 'other',
                };
                continue;
            }

            if ($column === 'photo_consent') {
                $answer = is_array($value) ? ($value[0] ?? '') : (string) $value;
                $answer = strtolower(trim($answer));
                $data['photo_consent'] = str_starts_with($answer, 'yes')
                    || str_starts_with($answer, 'បាទ')
                    || str_starts_with($answer, 'ចាស');
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

            // Looser label matching must not turn e.g. an "email consent: Yes" answer into the email address.
            if ($column === 'email' && ! filter_var($value, FILTER_VALIDATE_EMAIL)) continue;
            if ($column !== null && !isset($data[$column]) && is_scalar($value)) $data[$column] = $value;
        }

        $fullName = trim(implode(' ', array_filter($nameParts)));
        if ($fullName !== '' && empty($data['full_name'])) $data['full_name'] = $fullName;
    }

    /**
     * Longer wordings of the core questions, e.g. "E-mail address", "Name of Your Institution" or
     * "Your Address (Province)". Exact labels and real column names are matched before this.
     */
    private function columnForLabel(string $normalized): ?string
    {
        return match (true) {
            str_contains($normalized, 'email') || str_contains($normalized, 'e_mail') => 'email',
            str_contains($normalized, 'phone') => 'phone',
            str_contains($normalized, 'institution') || str_contains($normalized, 'organi') => 'organization',
            str_contains($normalized, 'address') => 'address',
            default => null,
        };
    }

    /**
     * Why registration is not possible right now, or null when it is open.
     * Shared by registration and the public event/form endpoints so the page
     * and the server always agree.
     */
    public function closedReason(Event $event): ?string
    {
        $settings = $event->form_config['settings'] ?? [];
        // Drafts, closed and cancelled events never accept registrations, and
        // organisers can pause registration with the "Allow registration" switch.
        if ($event->status !== 'published') return 'Registration is not open for this event.';
        if (($settings['allow_registration'] ?? true) === false) return 'Registration is currently closed for this event.';
        if ($event->ends_at && $event->ends_at->isPast()) return 'This event has already ended.';
        if (\App\Http\Controllers\SystemSettingsController::maintenanceMode()) return 'Registration is paused while the system is under maintenance. Please try again later.';
        if ($event->registrationOpensAt()?->isFuture()) return 'Registration for this event has not opened yet.';
        if ($event->registrationClosesAt()?->isPast()) return 'Registration for this event has closed.';
        if ($event->registrationLimit() > 0 && $event->registrations()->count() >= $event->registrationLimit()) return 'This event is full.';
        return null;
    }
}
