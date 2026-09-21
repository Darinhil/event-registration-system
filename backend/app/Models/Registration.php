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

    protected function casts(): array
    {
        return ['event_date' => 'datetime', 'photo_consent' => 'boolean', 'age' => 'integer', 'form_data' => 'array'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function event(): BelongsTo { return $this->belongsTo(Event::class); }

    public function checkIn(): HasOne { return $this->hasOne(CheckIn::class); }
}
