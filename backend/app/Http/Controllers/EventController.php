<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\RegistrationResource;

class EventController extends Controller
{
    public function index() { return Event::where('status', 'published')->latest('starts_at')->get(); }
    public function adminIndex() { return Event::withCount('registrations')->latest('starts_at')->get(); }
    public function show(Event $event) { return response()->json(['data' => $event->only(['id', 'name', 'description', 'starts_at', 'ends_at', 'location', 'capacity', 'branding', 'enabled_fields', 'status']), 'registered' => $event->registrations()->count(), 'remaining' => max(0, $event->capacity - $event->registrations()->count())]); }
    public function form(Event $event) { return response()->json(['data' => $event->formFields()->get(['id', 'label', 'type', 'required', 'options', 'sort_order'])]); }
    public function registrants(Event $event) { return RegistrationResource::collection($event->registrations()->with('checkIn')->latest()->paginate(25)); }
    public function close(Event $event) { $event->update(['status' => 'closed']); return response()->json(['data' => $event->fresh()]); }
    public function cancel(Event $event) { $event->update(['status' => 'cancelled']); return response()->json(['data' => $event->fresh()]); }
    public function destroy(Event $event) { $event->delete(); return response()->json(['message' => 'Event deleted.']); }
    public function store(Request $request): Event
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string'], 'starts_at' => ['required', 'date', 'after:now'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'location' => ['required', 'string', 'max:200'], 'capacity' => ['required', 'integer', 'min:1'], 'branding' => ['nullable', 'array'], 'enabled_fields' => ['nullable', 'array'], 'form_fields' => ['nullable', 'array'], 'form_fields.*.label' => ['required', 'string', 'max:150'], 'form_fields.*.type' => ['required', 'string', 'max:40'], 'form_fields.*.required' => ['boolean'], 'form_fields.*.options' => ['nullable', 'array']]);
        $fields = $request->input('form_fields', []);
        $data['status'] = 'published';
        unset($data['form_fields']);
        $event = DB::transaction(function () use ($data, $fields): Event {
            $event = Event::create($data);
            foreach ($fields as $index => $field) $event->formFields()->create(['label' => $field['label'], 'type' => $field['type'] ?? 'text', 'required' => (bool) ($field['required'] ?? false), 'options' => $field['options'] ?? null, 'sort_order' => $index]);
            return $event;
        });
        return $event;
    }
}
