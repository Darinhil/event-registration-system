<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckInRequest;
use App\Http\Resources\RegistrationResource;
use App\Services\CheckInService;
use Illuminate\Http\Request;

class CheckInController extends Controller
{
    public function __construct(private CheckInService $checkIns) {}
    public function lookup(Request $request): RegistrationResource
    {
        $data = $request->validate(['credential' => ['required', 'string', 'max:2048']]);
        return new RegistrationResource($this->checkIns->findRegistration($data['credential']));
    }

    public function store(CheckInRequest $request): \Illuminate\Http\JsonResponse { $checkIn = $this->checkIns->checkIn($request->string('credential')->toString(), $request->user()); return response()->json(['message' => 'Checked in successfully.', 'check_in' => $checkIn], 201); }
}
