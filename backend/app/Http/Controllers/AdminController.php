<?php

namespace App\Http\Controllers;

use App\Models\CheckIn;
use App\Models\Event;
use App\Models\FormField;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AdminController extends Controller
{
    public function dashboard(): JsonResponse { return response()->json(['users' => User::count(), 'registrations' => Registration::count(), 'check_ins' => CheckIn::count()]); }

    /**
     * Per-event report: registrations, check-ins, no-shows and attendance rate.
     * Feeds the admin Reports page and its CSV export.
     */
    public function reports(): JsonResponse
    {
        $events = Event::query()
            ->withCount(['registrations', 'registrations as checked_in_count' => fn ($query) => $query->whereHas('checkIn')])
            ->latest('starts_at')
            ->get();

        return response()->json(['data' => $events->map(fn (Event $event) => [
            'id' => $event->id,
            'name' => $event->name,
            'location' => $event->location,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'status' => $event->status,
            'capacity' => $event->capacity,
            'registrations' => $event->registrations_count,
            'checked_in' => $event->checked_in_count,
            'no_show' => max($event->registrations_count - $event->checked_in_count, 0),
            'attendance_rate' => $event->registrations_count > 0
                ? round($event->checked_in_count / $event->registrations_count * 1000) / 10
                : 0.0,
            'capacity_used' => $event->capacity > 0
                ? round($event->registrations_count / $event->capacity * 1000) / 10
                : 0.0,
        ])]);
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
            ->with(['user:id,name,email,phone', 'checkIn.staff:id,name'])
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

    public function users(\Illuminate\Http\Request $request)
    {
        $search = trim((string) $request->query('search'));
        $status = (string) $request->query('status');
        $checkIn = (string) $request->query('check_in');
        $eventId = (int) $request->query('event_id');

        $formFields = $eventId > 0
            ? FormField::where('event_id', $eventId)->orderBy('sort_order')->get()
            : collect();

        $page = User::query()
            ->where('role', '!=', 'admin')
            ->whereHas('registrations', fn ($q) => $q->when($eventId > 0, fn ($query) => $query->where('event_id', $eventId)))
            ->withCount(['registrations' => fn ($q) => $q->when($eventId > 0, fn ($query) => $query->where('event_id', $eventId))])
            ->with(['registrations' => fn ($q) => $q->when($eventId > 0, fn ($query) => $query->where('event_id', $eventId)), 'registrations.checkIn'])
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->when(in_array($status, ['registered', 'pending'], true), fn ($query) => $query->whereHas('registrations', fn ($q) => $q->where('status', $status)))
            ->when($checkIn === 'in', fn ($query) => $query->whereHas('registrations.checkIn'))
            ->when($checkIn === 'out', fn ($query) => $query->whereHas('registrations', fn ($q) => $q->whereDoesntHave('checkIn')))
            ->latest()
            ->paginate();
        $page->getCollection()->each(fn (User $user) => $user->registrations->each(fn (Registration $registration) => $registration->append_form_values($formFields)));

        return $page->appends(array_filter([
            'search' => $search,
            'status' => $status,
            'check_in' => $checkIn,
            'event_id' => $eventId > 0 ? $eventId : null,
        ]));
    }
    public function checkIns() { return CheckIn::with('registration.user', 'staff')->latest('checked_in_at')->paginate(); }

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
}
