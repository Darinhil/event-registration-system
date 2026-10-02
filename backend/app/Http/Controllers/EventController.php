<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\RegistrationResource;

class EventController extends Controller
{
    public function index() { return Event::query()->where('status', 'published')->select(['id', 'name', 'description', 'starts_at', 'ends_at', 'location', 'capacity', 'branding', 'status'])->withCount('registrations')->latest('starts_at')->get(); }
    public function adminIndex(Request $request)
    {
        // Banner images are stored as base64 in branding. Sending every image
        // with the workspace list makes the first request unnecessarily huge.
        // Event details still return branding when it is actually needed.
        return Event::visibleTo($request->user())
            ->select(['id', 'name', 'description', 'starts_at', 'ends_at', 'location', 'capacity', 'status', 'created_by'])
            ->withCount('registrations')
            ->latest('starts_at')
            ->get();
    }
    public function show(Request $request, Event $event) { if (in_array($request->user()?->role, ['admin', 'event_admin'], true)) abort_unless($request->user()->can('view', $event), 403); $registered = $event->registrations()->count(); return response()->json(['data' => $event->only(['id', 'name', 'description', 'starts_at', 'ends_at', 'location', 'capacity', 'branding', 'enabled_fields', 'form_config', 'status']), 'registered' => $registered, 'remaining' => max(0, $event->capacity - $registered)]); }
    /** Full public form config — the exact fields/steps/settings the admin built. */
    public function form(Request $request, Event $event)
    {
        if (in_array($request->user()?->role, ['admin', 'event_admin'], true)) abort_unless($request->user()->can('view', $event), 403);
        $payload = app(FormBuilderController::class)->publicPayload($event);
        $payload['registration_open'] = $event->status === 'published';

        return response()->json(['data' => $payload]);
    }
    public function registrants(Event $event) { return RegistrationResource::collection($event->registrations()->with('checkIn')->latest()->paginate(25)); }
    public function regenerateCheckInQr(Event $event): \Illuminate\Http\JsonResponse { $event->update(['check_in_qr_token' => (string) \Illuminate\Support\Str::uuid()]); return response()->json(['data' => ['check_in_qr_token' => $event->fresh()->check_in_qr_token]]); }
    public function close(Event $event) { $event->update(['status' => 'closed']); return response()->json(['data' => $event->fresh()]); }
    public function cancel(Event $event) { $event->update(['status' => 'cancelled']); return response()->json(['data' => $event->fresh()]); }
    public function destroy(Event $event)
    {
        DB::transaction(function () use ($event): void {
            $registrationIds = $event->registrations()->pluck('id');
            if ($registrationIds->isNotEmpty()) {
                \App\Models\CheckIn::whereIn('registration_id', $registrationIds)->delete();
                $event->registrations()->delete();
            }
            $event->formFields()->delete();
            $event->delete();
        });

        return response()->json(['message' => 'Event and all related registrations deleted.']);
    }
    public function store(Request $request): Event
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string'], 'starts_at' => ['required', 'date', 'after:now'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'location' => ['required', 'string', 'max:200'], 'capacity' => ['required', 'integer', 'min:1'], 'branding' => ['nullable', 'array'], 'branding.image' => ['nullable', 'string', 'max:2000000'], 'enabled_fields' => ['nullable', 'array'], 'form_config' => ['nullable', 'array'], 'form_fields' => ['nullable', 'array'], 'form_fields.*.label' => ['required', 'string', 'max:150'], 'form_fields.*.type' => ['required', 'string', 'max:40'], 'form_fields.*.required' => ['boolean'], 'form_fields.*.description' => ['nullable', 'string', 'max:300'], 'form_fields.*.placeholder' => ['nullable', 'string', 'max:150'], 'form_fields.*.options' => ['nullable', 'array'], 'form_fields.*.settings' => ['nullable', 'array']]);
        $fields = $request->input('form_fields', []);
        $data['status'] = 'published';
        unset($data['form_fields']);
        $event = DB::transaction(function () use ($data, $fields, $request): Event {
            $event = Event::create([...$data, 'created_by' => $request->user()->id]);
            foreach ($fields as $index => $field) $event->formFields()->create(['label' => $field['label'], 'description' => $field['description'] ?? null, 'placeholder' => $field['placeholder'] ?? null, 'type' => $field['type'] ?? 'text', 'required' => (bool) ($field['required'] ?? false), 'options' => $field['options'] ?? null, 'settings' => $field['settings'] ?? null, 'sort_order' => $index]);
            return $event;
        });
        return $event;
    }
    public function update(Request $request, Event $event): Event
    {
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'max:150'], 'description' => ['nullable', 'string'], 'starts_at' => ['sometimes', 'required', 'date'], 'ends_at' => ['sometimes', 'required', 'date', 'after:starts_at'], 'location' => ['sometimes', 'required', 'string', 'max:200'], 'capacity' => ['sometimes', 'required', 'integer', 'min:1'], 'branding' => ['nullable', 'array'], 'branding.image' => ['nullable', 'string', 'max:2000000'], 'enabled_fields' => ['nullable', 'array'], 'form_config' => ['nullable', 'array'], 'form_fields' => ['nullable', 'array'], 'form_fields.*.label' => ['required', 'string', 'max:150'], 'form_fields.*.type' => ['required', 'string', 'max:40'], 'form_fields.*.required' => ['boolean'], 'form_fields.*.description' => ['nullable', 'string', 'max:300'], 'form_fields.*.placeholder' => ['nullable', 'string', 'max:150'], 'form_fields.*.options' => ['nullable', 'array'], 'form_fields.*.settings' => ['nullable', 'array']]);
        $fields = $request->input('form_fields');
        unset($data['form_fields']);
        return DB::transaction(function () use ($event, $data, $fields): Event {
            $event->update($data);
            if (is_array($fields)) {
                $event->formFields()->delete();
                foreach ($fields as $index => $field) $event->formFields()->create(['label' => $field['label'], 'description' => $field['description'] ?? null, 'placeholder' => $field['placeholder'] ?? null, 'type' => $field['type'] ?? 'text', 'required' => (bool) ($field['required'] ?? false), 'options' => $field['options'] ?? null, 'settings' => $field['settings'] ?? null, 'sort_order' => $index]);
            }
            return $event->fresh();
        });
    }
}
