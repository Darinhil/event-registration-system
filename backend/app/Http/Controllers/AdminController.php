<?php

namespace App\Http\Controllers;

use App\Models\CheckIn;
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

        return User::query()
            ->withCount('registrations')
            ->with('registrations.checkIn')
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->when(in_array($status, ['registered', 'pending'], true), fn ($query) => $query->whereHas('registrations', fn ($q) => $q->where('status', $status)))
            ->when($checkIn === 'in', fn ($query) => $query->whereHas('registrations.checkIn'))
            ->when($checkIn === 'out', fn ($query) => $query->whereHas('registrations', fn ($q) => $q->whereDoesntHave('checkIn')))
            ->latest()
            ->paginate()
            ->appends(array_filter([
                'search' => $search,
                'status' => $status,
                'check_in' => $checkIn,
            ]));
    }
    public function checkIns() { return CheckIn::with('registration.user', 'staff')->latest('checked_in_at')->paginate(); }
}