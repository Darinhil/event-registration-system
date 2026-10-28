<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckInRequest;
use App\Http\Requests\CheckInPreviewRequest;
use App\Http\Resources\RegistrationResource;
use App\Models\Event;
use App\Models\Registration;
use Illuminate\Validation\ValidationException;
use App\Services\CheckInService;
use Illuminate\Http\Request;

class CheckInController extends Controller
{
    public function __construct(private CheckInService $checkIns) {}
    public function lookup(Request $request): RegistrationResource
    {
        $data = $request->validate(['credential' => ['nullable', 'string', 'max:2048'], 'qr_token' => ['nullable', 'uuid']]);
        $data['credential'] = ($data['credential'] ?? null) ?: ($data['qr_token'] ?? null);
        $registration = $this->checkIns->findRegistration($data['credential'], $request->user());
        $registration->append_form_values();
        return new RegistrationResource($registration);
    }

    public function store(CheckInRequest $request): \Illuminate\Http\JsonResponse
    {
        try { $checkIn = $this->checkIns->checkIn($request->string('credential')->toString(), $request->user(), $request->input('entrance_token')); }
        catch (ValidationException $exception) { if ($request->filled('qr_token')) throw ValidationException::withMessages(['qr_token' => $exception->getMessage()]); throw $exception; }
        return response()->json(['message' => 'Checked in successfully.', 'check_in' => $checkIn], 201);
    }

    public function preview(CheckInPreviewRequest $request): RegistrationResource
    {
        $user = $request->user();
        $registration = null;
        if ($request->filled('qr_token')) $registration = Registration::where('qr_token', $request->input('qr_token'))->first();
        elseif ($request->filled('event_id')) $registration = $user->registrations()->where('event_id', $request->integer('event_id'))->first();
        elseif ($request->filled('entrance_token')) {
            $event = Event::where('check_in_qr_token', $request->input('entrance_token'))->first();
            $registration = $event ? $user->registrations()->where('event_id', $event->id)->first() : null;
        }
        if (! $registration) {
            if ($request->filled('event_id') && ! $user->registrations()->where('event_id', $request->integer('event_id'))->exists()) abort(404);
            throw ValidationException::withMessages(['qr_token' => 'Registration not found.']);
        }
        $canView = $registration->user_id === $user->id || ($user->isStaff() && $registration->event && $user->can('view', $registration->event));
        abort_unless($canView, 403);
        $registration->load('event')->append_form_values();
        return new RegistrationResource($registration);
    }

    public function search(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate(['event_id' => ['required', 'integer', 'exists:events,id'], 'query' => ['required', 'string', 'max:100']]);
        $event = Event::findOrFail($data['event_id']);
        abort_unless($request->user()->can('view', $event), 403);
        $query = trim($data['query']);
        // Phone numbers are matched with spaces/dashes ignored so "012 345 678" finds "012345678".
        $digits = preg_replace('/[^0-9+]/', '', $query);
        $matches = $event->registrations()->where(fn ($q) => $q->where('registration_code', 'like', "%{$query}%")->orWhere('full_name', 'like', "%{$query}%")->orWhere('email', 'like', "%{$query}%")
            ->when(strlen($digits) >= 3, fn ($q) => $q->orWhereRaw("REPLACE(REPLACE(COALESCE(phone, ''), ' ', ''), '-', '') like ?", ["%{$digits}%"])->orWhereRaw("REPLACE(REPLACE(COALESCE(telephone, ''), ' ', ''), '-', '') like ?", ["%{$digits}%"])))
            ->with(['event', 'checkIn'])->limit(10)->get();
        if ($matches->count() === 1) { $registration = $matches->first()->append_form_values(); return response()->json(['data' => ['registration' => (new RegistrationResource($registration))->resolve(), 'suggestions' => []]]); }
        if ($matches->count() > 1) return response()->json(['data' => ['registration' => null, 'suggestions' => $matches->map(fn ($item) => ['id' => $item->id, 'full_name' => $item->full_name, 'registration_code' => $item->registration_code, 'email' => $item->email, 'phone' => $item->phone ?: $item->telephone, 'checked_in_at' => $item->checkIn?->checked_in_at?->toIso8601String()])]]);
        throw ValidationException::withMessages(['query' => 'No registration found for that search.']);
    }
}
