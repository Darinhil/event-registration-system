<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'username' => $this->username,
            'staff_id' => $this->staff_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'staff_team' => $this->staff_team,
            'staff_position' => $this->staff_position,
            'staff_role_title' => $this->staff_role_title,
            'staff_authorities' => $this->staff_authorities ?? [],
            // The disk URL is absolute when APP_URL is set.
            'profile_photo' => $this->profile_photo ? url(Storage::disk('public')->url($this->profile_photo)) : null,
            'personal_information' => $this->personal_information,
            'professional_qualifications' => $this->professional_qualifications,
            'family_information' => $this->family_information,
            'role' => $this->role,
            'created_at' => $this->created_at,
            'token' => $this->when(isset($this->token), $this->token),
            'registrations' => RegistrationResource::collection($this->whenLoaded('registrations')),
        ];
    }
}
