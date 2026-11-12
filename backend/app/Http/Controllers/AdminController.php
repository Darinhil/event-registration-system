<?php

namespace App\Http\Controllers;

use App\Models\CheckIn;
use App\Models\Event;
use App\Models\FormField;
use App\Models\Registration;
use App\Models\User;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function dashboard(\Illuminate\Http\Request $request): JsonResponse
    {
        $eventIds = Event::visibleTo($request->user())->select('id');
        $registrations = Registration::whereIn('event_id', $eventIds)->count();
        $checkIns = CheckIn::whereHas('registration', fn ($query) => $query->whereIn('event_id', $eventIds))->count();
        $users = Registration::whereIn('event_id', $eventIds)->distinct()->count('user_id');

        return response()->json(['users' => $users, 'registrations' => $registrations, 'check_ins' => $checkIns]);
    }

    /**
     * Recent activity for the topbar bell: new registrations and check-ins on the
     * events this user can see. Read-only; "unread" is tracked by the browser.
     */
    public function notifications(\Illuminate\Http\Request $request): JsonResponse
    {
        $limit = 20;
        $eventIds = Event::visibleTo($request->user())->select('id');
        $name = fn (Registration $registration) => $registration->full_name ?: 'Someone';

        $registrations = Registration::whereIn('event_id', $eventIds)->whereNotNull('event_id')
            ->with('event:id,name')->latest()->latest('id')->limit($limit)
            ->get(['id', 'event_id', 'full_name', 'event_name', 'created_at'])
            ->map(fn (Registration $registration) => [
                'id' => "registration-{$registration->id}",
                'type' => 'registration',
                'title' => "{$name($registration)} registered",
                'event' => $registration->event?->name ?? $registration->event_name,
                'event_id' => $registration->event_id,
                'at' => $registration->created_at?->toIso8601String(),
            ]);

        $checkIns = CheckIn::whereHas('registration', fn ($query) => $query->whereIn('event_id', $eventIds))
            ->with('registration:id,event_id,full_name,event_name', 'registration.event:id,name')
            ->latest('checked_in_at')->latest('id')->limit($limit)->get()
            ->map(fn (CheckIn $checkIn) => [
                'id' => "check-in-{$checkIn->id}",
                'type' => 'check_in',
                'title' => "{$name($checkIn->registration)} checked in",
                'event' => $checkIn->registration->event?->name ?? $checkIn->registration->event_name,
                'event_id' => $checkIn->registration->event_id,
                'at' => ($checkIn->checked_in_at ?? $checkIn->created_at)?->toIso8601String(),
            ]);

        $items = $registrations->concat($checkIns)->sortByDesc('at')->take($limit)->values();

        return response()->json(['data' => $items]);
    }

    /**
     * Per-event report: registrations, check-ins, no-shows and attendance rate.
     * Feeds the admin Reports page and its CSV export.
     */
    public function reports(\Illuminate\Http\Request $request): JsonResponse
    {
        // Only the columns the report uses: banners (up to ~2 MB each) would overflow MySQL's sort buffer here.
        $events = Event::query()
            ->select(['id', 'name', 'location', 'starts_at', 'ends_at', 'status', 'capacity', 'form_config', 'created_by'])
            ->withCount(['registrations', 'registrations as checked_in_count' => fn ($query) => $query->whereHas('checkIn')])
            ->with(['registrations:id,event_id,gender,age,age_group,form_data', 'formFields'])
            ->visibleTo($request->user())
            ->latest('starts_at')
            ->get();

        return response()->json(['data' => $events->map(function (Event $event) {
            $demographics = ['gender' => [], 'age' => [], 'nationality' => []];
            foreach ($event->registrations as $registration) {
                // append_form_values() reads the event's archived field labels; without this it reloads the whole event per registration.
                $registration->setRelation('event', $event);
                $registration->append_form_values($event->formFields);
                $answers = collect($registration->form_values ?? [])->mapWithKeys(fn ($value, $label) => [mb_strtolower(trim((string) $label)) => $value]);
                $increment = function (string $group, $value) use (&$demographics): void {
                    $label = trim((string) $value);
                    if ($label !== '') $demographics[$group][$label] = ($demographics[$group][$label] ?? 0) + 1;
                };
                $gender = strtolower(trim((string) ($registration->gender ?: ($answers->get('gender') ?: $answers->get('sex')))));
                $gender = match (true) {
                    in_array($gender, ['m', 'male'], true) => 'M',
                    in_array($gender, ['f', 'female'], true) => 'F',
                    $gender !== '' => 'Other',
                    default => '',
                };
                $increment('gender', $gender);
                $age = $registration->age_group ?: $answers->get('age group');
                if (! $age && $registration->age !== null) $age = $registration->age < 18 ? 'Under 18' : ($registration->age < 30 ? '18–29' : ($registration->age <= 45 ? '30–45' : ($registration->age < 60 ? '46–59' : '60+')));
                $increment('age', $age);
                $increment('nationality', $answers->get('nationality') ?: ($answers->get('ethnicity') ?: ($answers->get('race') ?: $answers->get('ជនជាតិ'))));
            }

            return [
            'id' => $event->id,
            'name' => $event->name,
            'location' => $event->location,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            'status' => $event->status,
            'capacity' => $event->capacity,
            'registrations' => $event->registrations_count,
            'checked_in' => $event->checked_in_count,
            'not_checked_in' => max($event->registrations_count - $event->checked_in_count, 0),
            // A no-show only exists once the event is over; before that people may still arrive.
            'has_ended' => $this->eventHasEnded($event),
            'no_show' => $this->eventHasEnded($event) ? max($event->registrations_count - $event->checked_in_count, 0) : null,
            'attendance_rate' => $event->registrations_count > 0
                ? round($event->checked_in_count / $event->registrations_count * 1000) / 10
                : 0.0,
            'capacity_used' => $event->capacity > 0
                ? round($event->registrations_count / $event->capacity * 1000) / 10
                : 0.0,
            'demographics' => $demographics,
            ];
        })]);
    }
    private function eventHasEnded(Event $event): bool
    {
        $end = $event->ends_at ?? $event->starts_at;

        return $end !== null && $end->isPast();
    }

    /**
     * Attendee-level report for one event: every registration with its check-in
     * state, filterable by check-in status and search term. Unpaginated so the
     * Reports page can offer complete CSV downloads per segment.
     */
    public function eventReportAttendees(\Illuminate\Http\Request $request, Event $event): JsonResponse
    {
        $search = trim((string) $request->query('search'));
        $checkIn = (string) $request->query('check_in');

        $formFields = FormField::where('event_id', $event->id)->orderBy('sort_order')->get();

        $attendees = $event->registrations()
            ->with(['user:id,name,email,phone', 'checkIn.staff:id,name', 'event:id,form_config'])
            ->when($checkIn === 'in', fn ($query) => $query->whereHas('checkIn'))
            ->when($checkIn === 'out', fn ($query) => $query->whereDoesntHave('checkIn'))
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('full_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('registration_code', 'like', "%{$search}%")
                ->orWhereHas('user', fn ($u) => $u
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"))))
            ->latest()
            ->get()
            ->map(function (Registration $registration) use ($formFields) {
                $registration->append_form_values($formFields);
                return [
                    'id' => $registration->id,
                    'registration_code' => $registration->registration_code,
                    'registered_at' => $registration->created_at?->toIso8601String(),
                    'name' => $registration->full_name ?: $registration->user?->name,
                    'email' => $registration->email ?: $registration->user?->email,
                    'phone' => $registration->phone ?: $registration->user?->phone,
                    'status' => $registration->status,
                    'checked_in_at' => $registration->checkIn?->checked_in_at?->toIso8601String(),
                    'checked_in_by' => $registration->checkIn?->staff?->name,
                    'profile' => array_filter([
                        'gender' => $registration->gender,
                        'age' => $registration->age,
                        'organization' => $registration->organization,
                        'position' => $registration->position,
                        'address' => $registration->address,
                        'emergency_contact' => $registration->emergency_contact_phone
                            ? trim(($registration->emergency_contact_name ? $registration->emergency_contact_name . ' · ' : '') . $registration->emergency_contact_phone)
                            : null,
                    ], fn ($value) => $value !== null && $value !== ''),
                    'form_answers' => (object) ($registration->form_values ?? []),
                ];
            });

        return response()->json(['data' => [
            'event' => ['id' => $event->id, 'name' => $event->name],
            'summary' => [
                'total' => $attendees->count(),
                'checked_in' => $attendees->whereNotNull('checked_in_at')->count(),
                'not_checked_in' => $attendees->whereNull('checked_in_at')->count(),
            ],
            'attendees' => $attendees->values(),
        ]]);
    }

    public function updateRegistrant(\App\Http\Requests\RegistrationRequest $request, Event $event, Registration $registration, RegistrationService $registrations): JsonResponse
    {
        abort_unless((int) $registration->event_id === (int) $event->id, 404);

        $data = $request->validated();
        abort_unless((int) $data['event_id'] === (int) $event->id, 403, 'This registration does not belong to that event.');
        $registrations->update($registration, $data);

        return response()->json(['message' => 'Registrant updated successfully.']);
    }

    public function deleteRegistrant(Event $event, Registration $registration): JsonResponse
    {
        abort_unless((int) $registration->event_id === (int) $event->id, 404);
        abort_if($registration->checkIn()->exists(), 422, 'This attendee has already checked in, so the registration cannot be deleted.');

        $registration->delete();

        return response()->json(['message' => 'Registrant deleted successfully.']);
    }

    public function users(\Illuminate\Http\Request $request)
    {
        $search = trim((string) $request->query('search'));
        $checkIn = (string) $request->query('check_in');
        $eventId = (int) $request->query('event_id');

        $formFields = $eventId > 0
            ? FormField::where('event_id', $eventId)->orderBy('sort_order')->get()
            : collect();

        $visibleEventIds = Event::visibleTo($request->user())->pluck('id');
        if ($eventId > 0 && ! $visibleEventIds->contains($eventId)) abort(403);
        // Every condition looks only at registrations for the events in scope.
        $inScope = fn ($q) => $q->whereIn('event_id', $eventId > 0 ? [$eventId] : $visibleEventIds);
        // Only contact details: organisers must not receive the person's HR record with their attendance.
        $page = User::query()
            ->select(['id', 'name', 'email', 'phone', 'created_at'])
            ->whereHas('registrations', $inScope)
            ->withCount(['registrations' => $inScope])
            ->with(['registrations' => $inScope, 'registrations.checkIn', 'registrations.event:id,form_config'])
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->when($checkIn === 'in', fn ($query) => $query->whereHas('registrations', fn ($q) => $inScope($q)->whereHas('checkIn')))
            ->when($checkIn === 'out', fn ($query) => $query->whereHas('registrations', fn ($q) => $inScope($q)->whereDoesntHave('checkIn')))
            ->latest()
            ->paginate(min(max((int) $request->query('per_page', 25), 1), 100));
        $page->getCollection()->each(fn (User $user) => $user->registrations->each(function (Registration $registration) use ($formFields): void {
            $registration->append_form_values($formFields);
            $registration->makeHidden('event');
        }));

        return $page->appends(array_filter([
            'search' => $search,
            'check_in' => $checkIn,
            'event_id' => $eventId > 0 ? $eventId : null,
        ]));
    }
    public function checkIns(\Illuminate\Http\Request $request) { return CheckIn::query()->whereHas('registration.event', fn ($q) => $q->visibleTo($request->user()))->with(['registration:id,user_id,event_id,registration_code,full_name,email,phone', 'registration.user:id,name,email,phone', 'staff:id,name,email'])->latest('checked_in_at')->paginate(min(max((int) $request->query('per_page', 25), 1), 100)); }

    /** Per-event check-in log + summary for the live check-in desk. */
    public function eventCheckIns(\Illuminate\Http\Request $request, Event $event): \Illuminate\Http\JsonResponse
    {
        $registrations = $event->registrations()
            ->with(['user:id,name,email,phone,profile_photo', 'checkIn.staff:id,name'])
            ->when($request->boolean('checked_in'), fn ($query) => $query->whereHas('checkIn'))
            ->latest()
            ->get();

        $checkIns = $registrations->filter(fn (Registration $registration) => $registration->checkIn)
            ->map(fn (Registration $registration) => [
                'id' => $registration->checkIn->id,
                'checked_in_at' => $registration->checkIn->checked_in_at?->toIso8601String(),
                'registration_id' => $registration->id,
                'registration_code' => $registration->registration_code,
                'name' => $registration->full_name ?: $registration->user?->name,
                'email' => $registration->email ?: $registration->user?->email,
                'phone' => $registration->phone ?: $registration->user?->phone,
                'checked_in_by' => $registration->checkIn->staff?->name,
            ])
            ->sortByDesc('checked_in_at')
            ->values();

        $total = $registrations->count();
        $checkedIn = $checkIns->count();

        return response()->json(['data' => [
            'event' => ['id' => $event->id, 'name' => $event->name, 'starts_at' => $event->starts_at?->toIso8601String(), 'location' => $event->location, 'capacity' => $event->capacity, 'check_in_qr_token' => $event->checkInQrToken()],
            'summary' => ['expected' => $total, 'checked_in' => $checkedIn, 'remaining' => max($total - $checkedIn, 0), 'rate' => $total ? round($checkedIn / $total * 1000) / 10 : 0],
            'check_ins' => $checkIns,
            'recent_registrations' => $registrations->take(8)->map(fn (Registration $registration) => [
                'id' => $registration->id,
                'name' => $registration->full_name ?: $registration->user?->name,
                'email' => $registration->email ?: $registration->user?->email,
                'checked_in_at' => $registration->checkIn?->checked_in_at?->toIso8601String(),
            ]),
        ]]);
    }

    public function adminAccounts(): JsonResponse
    {
        return response()->json(['data' => User::whereIn('role', User::STAFF_ROLES)->latest()->get()]);
    }

    public function createAdminAccount(\Illuminate\Http\Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(User::STAFF_ROLES)],
        ]);
        $user = User::create($data);
        return response()->json(['data' => $user], 201);
    }

    public function updateAdminAccount(\Illuminate\Http\Request $request, User $user): JsonResponse
    {
        abort_unless($user->isStaff(), 404);
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'max:100'], 'role' => ['sometimes', 'required', Rule::in(User::STAFF_ROLES)], 'password' => ['sometimes', 'nullable', 'string', 'min:8']]);
        if (array_key_exists('password', $data) && ! $data['password']) unset($data['password']);
        // Only Master Admins reach this route, so blocking self-demotion also guarantees one always remains.
        abort_if(isset($data['role']) && $data['role'] !== $user->role && $request->user()->is($user), 422, 'You cannot change your own role.');
        $user->update($data);
        // A reset password must lock out whoever had the old one.
        if (isset($data['password']) && ! $request->user()->is($user)) $user->tokens()->delete();
        return response()->json(['data' => $user->fresh()]);
    }

    public function deleteAdminAccount(\Illuminate\Http\Request $request, User $user): JsonResponse
    {
        abort_unless($user->isStaff(), 404);
        abort_if($request->user()->is($user), 422, 'You cannot delete your own account.');
        $user->tokens()->delete();
        $user->delete();
        return response()->json(['message' => 'Admin account deleted.']);
    }
}
