<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegistrationRequest;
use App\Http\Resources\RegistrationResource;
use App\Models\Registration;
use App\Services\QRCodeService;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class RegistrationController extends Controller
{
    public function __construct(private RegistrationService $registrations, private QRCodeService $qr) {}
    public function store(RegistrationRequest $request): JsonResponse { return response()->json(['data' => new RegistrationResource($this->registrations->create($request->user(), $request->validated()))], 201); }
    public function show(Request $request, Registration $registration): RegistrationResource { abort_unless($request->user()->id === $registration->user_id || $request->user()->role === 'admin', 403); return new RegistrationResource($registration->load('checkIn', 'event')); }
    public function update(RegistrationRequest $request, Registration $registration): RegistrationResource
    {
        abort_unless($request->user()->id === $registration->user_id, 403, 'You can only edit your own registration.');
        $event = $registration->event;
        $settings = $event->form_config['settings'] ?? [];
        if (! (bool) ($settings['allow_user_edit'] ?? false)) {
            abort(403, 'Editing registration is disabled for this event.');
        }
        $deadline = $settings['registration_end'] ?? null;
        if ($deadline && Carbon::parse($deadline)->isPast()) {
            throw ValidationException::withMessages(['registration' => 'The registration editing deadline has passed.']);
        }
        if ((int) $request->validated()['event_id'] !== (int) $registration->event_id) {
            abort(403, 'This registration does not belong to that event.');
        }
        return new RegistrationResource($this->registrations->update($registration, $request->validated())->load('checkIn', 'event'));
    }
    public function qr(Registration $registration): JsonResponse { return response()->json(['payload' => $this->qr->payload($registration)]); }
}
