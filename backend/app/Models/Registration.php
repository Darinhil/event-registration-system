<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Registration extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'event_id', 'form_data', 'registration_code', 'event_name', 'event_date', 'event_location', 'full_name', 'gender', 'age', 'phone', 'email', 'organization', 'profile_photo', 'emergency_contact_name', 'emergency_contact_phone', 'age_group', 'disability_type', 'address', 'business_entity', 'position', 'telephone', 'photo_consent', 'signature', 'qr_token', 'status'];

    protected $appends = [];

    protected function casts(): array
    {
        return ['event_date' => 'datetime', 'photo_consent' => 'boolean', 'age' => 'integer', 'form_data' => 'array'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function event(): BelongsTo { return $this->belongsTo(Event::class); }

    public function checkIn(): HasOne { return $this->hasOne(CheckIn::class); }

    /**
     * Attach "form_values": the answers the user submitted on the registration form,
     * resolved from form_data (keyed by field id) into label => value pairs. Only
     * fields whose answer has content are included. Pass a preloaded collection of
     * FormField models to avoid extra queries when resolving many registrations.
     */
    public function append_form_values($fields = null): self
    {
        $formData = (array) ($this->form_data ?? []);
        if ($formData === []) {
            $this->form_values = [];

            return $this;
        }

        $fields ??= $this->event?->formFields ?? FormField::whereIn('id', array_keys($formData))->get();
        $labels = collect($fields)->pluck('label', 'id');

        $values = [];
        foreach ($formData as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if (is_array($value)) {
                $value = implode(', ', array_map(fn ($item) => (string) $item, $value));
            }
            $label = $labels[$key] ?? $labels[(int) $key] ?? null;
            $values[$label ?? (is_string($key) ? $key : "field_{$key}")] = $value;
        }
        $this->form_values = $values;

        return $this;
    }
}
