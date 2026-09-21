<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RegistrationResource extends JsonResource
{
    public function toArray(Request $request): array { return ['id' => $this->id, 'registration_code' => $this->registration_code, 'event_id' => $this->event_id, 'form_data' => $this->form_data, 'event_name' => $this->event_name, 'event_date' => $this->event_date?->toIso8601String(), 'event_location' => $this->event_location, 'full_name' => $this->full_name, 'gender' => $this->gender, 'age' => $this->age, 'phone' => $this->phone, 'email' => $this->email, 'organization' => $this->organization, 'address' => $this->address, 'business_entity' => $this->business_entity, 'position' => $this->position, 'telephone' => $this->telephone, 'profile_photo' => $this->profile_photo, 'emergency_contact_name' => $this->emergency_contact_name, 'emergency_contact_phone' => $this->emergency_contact_phone, 'age_group' => $this->age_group, 'disability_type' => json_decode($this->disability_type ?: '[]', true), 'photo_consent' => $this->photo_consent, 'signature' => $this->signature, 'qr_token' => $this->qr_token, 'status' => $this->status, 'checked_in_at' => $this->checkIn?->checked_in_at?->toIso8601String()]; }
}
