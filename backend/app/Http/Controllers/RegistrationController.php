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
    public function show(Request $request, Registration $registration): RegistrationResource
    {
        $this->authorizeView($request, $registration);

        $registration->load('checkIn', 'event');
        $registration->append_form_values();

        return new RegistrationResource($registration);
    }
    public function update(RegistrationRequest $request, Registration $registration): RegistrationResource
    {
        abort_unless($request->user()->id === $registration->user_id, 403, 'You can only edit your own registration.');
        $event = $registration->event;
        $settings = $event->form_config['settings'] ?? [];
        if (! (bool) ($settings['allow_user_edit'] ?? false)) {
            abort(403, 'Editing registration is disabled for this event.');
        }
        $deadline = $settings['registration_end'] ?? null;
        if ($deadline && Carbon::parse($deadline)->endOfDay()->isPast()) {
            throw ValidationException::withMessages(['registration' => 'The registration editing deadline has passed.']);
        }
        if ((int) $request->validated()['event_id'] !== (int) $registration->event_id) {
            abort(403, 'This registration does not belong to that event.');
        }
        return new RegistrationResource($this->registrations->update($registration, $request->validated())->load('checkIn', 'event'));
    }
    public function destroy(Request $request, Registration $registration): JsonResponse
    {
        abort_unless($request->user()->id === $registration->user_id, 403, 'You can only delete your own registration.');
        // Keep attendance history intact: a registration that was used to check in,
        // or whose event is over, is part of the organiser's records.
        abort_if($registration->checkIn()->exists(), 422, 'You have already checked in to this event, so this registration can’t be cancelled.');
        abort_if($registration->event?->ends_at?->isPast(), 422, 'This event has ended, so the registration can’t be cancelled.');

        $registration->delete();

        return response()->json(['message' => 'Registration deleted successfully.']);
    }
    public function qr(Request $request, Registration $registration): JsonResponse
    {
        // The payload carries the qr_token, which is the check-in credential.
        $this->authorizeView($request, $registration);

        return response()->json(['payload' => $this->qr->payload($registration)]);
    }

    /** The attendee who owns the registration, or staff who can view its event. */
    private function authorizeView(Request $request, Registration $registration): void
    {
        $user = $request->user();
        $event = $registration->event;
        abort_unless($user->id === $registration->user_id || ($user->isStaff() && $event && $user->can('view', $event)), 403);
    }
}
