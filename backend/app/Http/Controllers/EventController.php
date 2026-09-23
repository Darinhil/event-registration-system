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
    public function show(Event $event) { return response()->json(['data' => $event->only(['id', 'name', 'description', 'starts_at', 'ends_at', 'location', 'capacity', 'branding', 'enabled_fields', 'form_config', 'status']), 'registered' => $event->registrations()->count(), 'remaining' => max(0, $event->capacity - $event->registrations()->count())]); }
    public function form(Event $event) { return response()->json(['data' => $event->formFields()->get(['id', 'label', 'description', 'placeholder', 'type', 'required', 'options', 'settings', 'sort_order']), 'settings' => $event->form_config['settings'] ?? []]); }
    public function registrants(Event $event) { return RegistrationResource::collection($event->registrations()->with('checkIn')->latest()->paginate(25)); }
    public function close(Event $event) { $event->update(['status' => 'closed']); return response()->json(['data' => $event->fresh()]); }
    public function cancel(Event $event) { $event->update(['status' => 'cancelled']); return response()->json(['data' => $event->fresh()]); }
    public function destroy(Event $event) { $event->delete(); return response()->json(['message' => 'Event deleted.']); }
    public function store(Request $request): Event
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string'], 'starts_at' => ['required', 'date', 'after:now'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'location' => ['required', 'string', 'max:200'], 'capacity' => ['required', 'integer', 'min:1'], 'branding' => ['nullable', 'array'], 'branding.image' => ['nullable', 'string', 'max:2000000'], 'enabled_fields' => ['nullable', 'array'], 'form_config' => ['nullable', 'array'], 'form_fields' => ['nullable', 'array'],'form_fields.*.label' => ['required', 'string', 'max:150'], 'form_fields.*.type' => ['required', 'string', 'max:40'], 'form_fields.*.required' => ['boolean'], 'form_fields.*.description' => ['nullable', 'string', 'max:300'], 'form_fields.*.placeholder' => ['nullable', 'string', 'max:150'], 'form_fields.*.options' => ['nullable', 'array']]);
        $fields = $request->input('form_fields', []);
        $data['status'] = 'published';
        unset($data['form_fields']);
        $event = DB::transaction(function () use ($data, $fields): Event {
            $event = Event::create($data);
            foreach ($fields as $index => $field) $event->formFields()->create(['label' => $field['label'], 'description' => $field['description'] ?? null, 'placeholder' => $field['placeholder'] ?? null, 'type' => $field['type'] ?? 'text', 'required' => (bool) ($field['required'] ?? false), 'options' => $field['options'] ?? null, 'sort_order' => $index]);
            return $event;
        });
        return $event;
    }
    public function update(Request $request, Event $event): Event
    {
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'max:150'], 'description' => ['nullable', 'string'], 'starts_at' => ['sometimes', 'required', 'date'], 'ends_at' => ['sometimes', 'required', 'date', 'after:starts_at'], 'location' => ['sometimes', 'required', 'string', 'max:200'], 'capacity' => ['sometimes', 'required', 'integer', 'min:1'], 'branding' => ['nullable', 'array'], 'branding.image' => ['nullable', 'string', 'max:2000000'], 'enabled_fields' => ['nullable', 'array'], 'form_config' => ['nullable', 'array'], 'form_fields' => ['nullable', 'array'], 'form_fields.*.label' => ['required', 'string', 'max:150'], 'form_fields.*.type' => ['required', 'string', 'max:40'], 'form_fields.*.required' => ['boolean'], 'form_fields.*.description' => ['nullable', 'string', 'max:300'], 'form_fields.*.placeholder' => ['nullable', 'string', 'max:150'], 'form_fields.*.options' => ['nullable', 'array']]);
        $fields = $request->input('form_fields');
        unset($data['form_fields']);
        return DB::transaction(function () use ($event, $data, $fields): Event {
            $event->update($data);
            if (is_array($fields)) {
                $event->formFields()->delete();
                foreach ($fields as $index => $field) $event->formFields()->create(['label' => $field['label'], 'description' => $field['description'] ?? null, 'placeholder' => $field['placeholder'] ?? null, 'type' => $field['type'] ?? 'text', 'required' => (bool) ($field['required'] ?? false), 'options' => $field['options'] ?? null, 'sort_order' => $index]);
            }
            return $event->fresh();
        });
    }
}
