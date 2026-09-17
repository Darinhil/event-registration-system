<?php

namespace App\Services;

use App\Models\Registration;

class QRCodeService
{
    public function payload(Registration $registration): string
    {
        return json_encode(['registration_id' => $registration->id, 'qr_token' => $registration->qr_token], JSON_THROW_ON_ERROR);
    }
}