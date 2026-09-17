<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckInRequest;
use App\Services\CheckInService;

class CheckInController extends Controller
{
    public function __construct(private CheckInService $checkIns) {}
    public function store(CheckInRequest $request): \Illuminate\Http\JsonResponse { $checkIn = $this->checkIns->checkIn($request->string('qr_token')->toString(), $request->user()); return response()->json(['message' => 'Checked in successfully.', 'check_in' => $checkIn], 201); }
}