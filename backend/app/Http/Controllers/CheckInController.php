<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckInPreviewRequest;
use App\Http\Requests\CheckInRequest;
use App\Services\CheckInService;

class CheckInController extends Controller
{
    public function __construct(private CheckInService $checkIns) {}
    public function store(CheckInRequest $request): \Illuminate\Http\JsonResponse
    {
        $checkIn = $this->checkIns->checkIn($request->string('qr_token')->toString(), $request->user());

        return response()->json([
            'message' => 'Checked in successfully.',
            'check_in' => $checkIn->load('registration.event'),
        ], 201);
    }

    /**
     * Attendee scanned the event QR: show their own registration for that event
     * (with the answers they submitted) before they confirm attendance.
     */
    public function preview(CheckInPreviewRequest $request): \Illuminate\Http\JsonResponse
    {
        $registration = null;
        if ($request->filled('entrance_token')) {
            $event = $this->checkIns->eventFromEntranceToken($request->string('entrance_token')->toString());
            $registration = $request->user()->registrations()->where('event_id', $event->id)->with(['checkIn', 'event.formFields'])->first();
        } elseif ($request->filled('event_id')) {
            $registration = $request->user()->registrations()->where('event_id', (int) $request->input('event_id'))->with(['checkIn', 'event.formFields'])->first();
        } else {
            $registration = $this->checkIns->preview($request->string('qr_token')->toString());
        }

        if (! $registration) {
            abort(404, 'You are not registered for this event.');
        }
        abort_unless($request->user()->id === $registration->user_id || $request->user()->role === 'admin', 403, 'This QR code belongs to another attendee.');

        $registration->append_form_values($registration->event->formFields);
        $registration->makeHidden(['form_data']);

        return response()->json(['data' => $registration]);
    }

    /** Admin desk search: find an attendee by registration code, name, or email. */
    public function search(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'event_id' => ['required', 'integer', 'min:1'],
            'query' => ['required', 'string', 'max:150'],
        ]);

        $event = \App\Models\Event::findOrFail($data['event_id']);
        $result = $this->checkIns->findByQuery($event, $data['query']);

        if ($registration = $result['registration']) {
            $registration->append_form_values($registration->event->formFields);
            $registration->makeHidden(['form_data']);
        }

        return response()->json(['data' => $result]);
    }

    /** Admin scanned an attendee QR: show the form answers before marking attendance. */
    public function lookup(CheckInRequest $request): \Illuminate\Http\JsonResponse
    {
        $registration = $this->checkIns->preview($request->string('qr_token')->toString());
        $registration->append_form_values($registration->event->formFields);
        $registration->makeHidden(['form_data']);

        return response()->json(['data' => $registration]);
    }
}
