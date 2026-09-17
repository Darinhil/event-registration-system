<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RegistrationResource extends JsonResource
{
    public function toArray(Request $request): array { return ['id' => $this->id, 'event_name' => $this->event_name, 'event_date' => $this->event_date?->toIso8601String(), 'qr_token' => $this->qr_token, 'status' => $this->status, 'checked_in_at' => $this->checkIn?->checked_in_at?->toIso8601String()]; }
}