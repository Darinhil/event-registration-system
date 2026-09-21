<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegistrationRequest;
use App\Http\Resources\RegistrationResource;
use App\Models\Registration;
use App\Services\QRCodeService;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegistrationController extends Controller
{
    public function __construct(private RegistrationService $registrations, private QRCodeService $qr) {}
    public function store(RegistrationRequest $request): JsonResponse { return response()->json(['data' => new RegistrationResource($this->registrations->create($request->user(), $request->validated()))], 201); }
    public function show(Request $request, Registration $registration): RegistrationResource { abort_unless($request->user()->id === $registration->user_id || $request->user()->role === 'admin', 403); return new RegistrationResource($registration->load('checkIn')); }
    public function qr(Registration $registration): JsonResponse { return response()->json(['payload' => $this->qr->payload($registration)]); }
}