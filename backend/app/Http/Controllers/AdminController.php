<?php

namespace App\Http\Controllers;

use App\Models\CheckIn;
use App\Models\FormField;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AdminController extends Controller
{
    public function dashboard(): JsonResponse { return response()->json(['users' => User::count(), 'registrations' => Registration::count(), 'check_ins' => CheckIn::count()]); }
    public function users(\Illuminate\Http\Request $request)
    {
        $search = trim((string) $request->query('search'));
        $status = (string) $request->query('status');
        $checkIn = (string) $request->query('check_in');
        $eventId = (int) $request->query('event_id');
        $perPage = min(max((int) $request->query('per_page', 15), 1), 10000);

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
            ->paginate($perPage);
        $page->getCollection()->each(fn (User $user) => $user->registrations->each(fn (Registration $registration) => $registration->append_form_values($formFields)));

        return $page->appends(array_filter([
            'search' => $search,
            'status' => $status,
            'check_in' => $checkIn,
            'event_id' => $eventId > 0 ? $eventId : null,
        ]));
    }
    public function checkIns(\Illuminate\Http\Request $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 10000);
        return CheckIn::with('registration.user', 'staff')->latest('checked_in_at')->paginate($perPage);
    }
}
