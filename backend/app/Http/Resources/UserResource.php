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
            'profile_photo' => $this->profile_photo
                ? $request->getSchemeAndHttpHost() . Storage::disk('public')->url($this->profile_photo)
                : null,
            'role' => $this->role,
            'created_at' => $this->created_at,
            'token' => $this->when(isset($this->token), $this->token),
            'registrations' => RegistrationResource::collection($this->whenLoaded('registrations')),
        ];
    }
}
