<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\RegistrationResource;

class EventController extends Controller
{
    public function index(Request $request) { return Event::query()->where('status', 'published')->where('ends_at', '>', now())->select(['id', 'name', 'category', 'description', 'starts_at', 'ends_at', 'location', 'capacity', 'branding', 'status', 'created_by'])->with('creator:id,name')->withCount('registrations')->latest('starts_at')->get()->filter(fn (Event $event) => $event->registrations_count < $event->capacity)->values(); }
    public function adminIndex(Request $request)
    {
        // Include branding in the admin card list so the banner renders
        // immediately. The endpoint still exists as a fallback for clients
        // that receive a lightweight cached response.
        return Event::visibleTo($request->user())
            ->select(['id', 'name', 'category', 'description', 'starts_at', 'ends_at', 'location', 'capacity', 'branding', 'status', 'created_by'])
            ->withCount('registrations')
            ->latest('starts_at')
            ->get();
    }
    public function banner(Request $request, Event $event): \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
    {
        // Public route: resolve the bearer token explicitly so staff can see their drafts' banners.
        if (! $request->user('sanctum') && $event->status !== 'published') abort(404);
        $image = $event->branding['image'] ?? null;
        if (! $image) return response()->json(['message' => 'This event has no banner.'], 404);
        if (! str_starts_with($image, 'data:')) return response()->json(['image' => $image]);
        if (! preg_match('/^data:(image\/[a-zA-Z0-9.+-]+);base64,(.*)$/s', $image, $matches)) return response()->json(['message' => 'Invalid banner image.'], 422);
        $contents = base64_decode($matches[2], true);
        abort_if($contents === false, 422, 'Invalid banner image.');
        // Organisers can upload SVG; the sandbox CSP stops any script in it from running if the URL is opened directly.
        return response($contents)->header('Content-Type', $matches[1])->header('Cache-Control', 'private, max-age=3600')
            ->header('X-Content-Type-Options', 'nosniff')->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
    }
    public function show(Request $request, Event $event)
    {
        // These routes are public, so the auth middleware never runs; check the token directly
        // so organisers can open their own drafts and closed events for editing.
        $staff = $this->authorizePublicView($request->user('sanctum'), $event);
        $registered = $event->registrations()->count();
        $data = $event->load('creator:id,name')->only(['id', 'name', 'category', 'description', 'starts_at', 'ends_at', 'location', 'capacity', 'branding', 'enabled_fields', 'form_config', 'status', 'creator']);
        if ($staff) {
            $data['check_in_qr_token'] = $event->check_in_qr_token;
        }
        $closedReason = app(\App\Services\RegistrationService::class)->closedReason($event);
        return response()->json(['data' => $data, 'registered' => $registered, 'remaining' => max(0, $event->capacity - $registered), 'registration' => ['open' => $closedReason === null, 'reason' => $closedReason]]);
    }
    /**
     * Attendees and guests only see live events; staff see any event they manage
     * (drafts and closed events included). Returns whether the viewer is staff.
     */
    private function authorizePublicView(?User $user, Event $event): bool
    {
        if ($user?->isStaff()) {
            abort_unless($user->can('view', $event), 403);
            return true;
        }
        abort_unless($event->status === 'published' && $event->ends_at?->isFuture(), 404);
        return false;
    }
    /** Full public form config — the exact fields/steps/settings the admin built. */
    public function form(Request $request, Event $event)
    {
        $this->authorizePublicView($request->user('sanctum'), $event);
        $payload = app(FormBuilderController::class)->publicPayload($event);
        $closedReason = app(\App\Services\RegistrationService::class)->closedReason($event);
        $payload['registration_open'] = $closedReason === null;
        $payload['registration_closed_reason'] = $closedReason;

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
    /**
     * Shared validation for create/update. Drafts only need a name so work can be
     * saved early; publishing (or editing a live event) requires the full schedule.
     */
    private function eventRules(bool $draft, bool $creating): array
    {
        $required = $draft ? ['nullable'] : ($creating ? ['required'] : ['sometimes', 'required']);
        return [
            'status' => ['sometimes', 'string', 'in:draft,published'],
            'name' => $creating ? ['required', 'string', 'max:150'] : ['sometimes', 'required', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'starts_at' => [...$required, 'date', ...($creating && ! $draft ? ['after:now'] : [])],
            'ends_at' => [...$required, 'date', 'after:starts_at'],
            'location' => [...$required, 'string', 'max:200'],
            'capacity' => [...$required, 'integer', 'min:1'],
            'branding' => ['nullable', 'array'], 'branding.image' => ['nullable', 'string', 'max:2000000'],
            'enabled_fields' => ['nullable', 'array'], 'form_config' => ['nullable', 'array'],
            'form_fields' => ['nullable', 'array'], 'form_fields.*.id' => ['nullable'], 'form_fields.*.label' => ['required', 'string', 'max:150'], 'form_fields.*.type' => ['required', 'string', 'max:40'], 'form_fields.*.required' => ['boolean'], 'form_fields.*.description' => ['nullable', 'string', 'max:300'], 'form_fields.*.placeholder' => ['nullable', 'string', 'max:150'], 'form_fields.*.options' => ['nullable', 'array'], 'form_fields.*.settings' => ['nullable', 'array'],
        ];
    }
    private function syncFormFields(Event $event, array $fields): void
    {
        // Update in place (not delete + recreate) so registrations keep their answers.
        app(\App\Services\FormFieldSync::class)->sync($event, $fields);
    }
    public function store(Request $request): Event
    {
        $draft = $request->input('status') === 'draft';
        $data = $request->validate($this->eventRules($draft, true));
        $data['status'] = $draft ? 'draft' : 'published';
        $data['enabled_fields'] ??= [];
        $fields = $request->input('form_fields', []);
        unset($data['form_fields']);
        return DB::transaction(function () use ($data, $fields, $request): Event {
            $event = Event::create([...$data, 'created_by' => $request->user()->id]);
            $this->syncFormFields($event, $fields);
            return $event;
        });
    }
    public function update(Request $request, Event $event): Event
    {
        $target = $request->input('status', $event->status);
        // A live event has registrants and a shared link; it cannot silently go back to draft.
        abort_if($target === 'draft' && $event->status !== 'draft', 422, 'Published events cannot be moved back to draft.');
        $draft = $target === 'draft';
        $publishing = $event->status === 'draft' && $target === 'published';
        $data = $request->validate($this->eventRules($draft, $publishing));
        if ($publishing) $data['status'] = 'published';
        else unset($data['status']);
        $fields = $request->input('form_fields');
        unset($data['form_fields']);
        return DB::transaction(function () use ($event, $data, $fields): Event {
            $event->update($data);
            if (is_array($fields)) $this->syncFormFields($event, $fields);
            return $event->fresh();
        });
    }
}
