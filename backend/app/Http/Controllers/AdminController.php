<?php

namespace App\Http\Controllers;

use App\Models\CheckIn;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AdminController extends Controller
{
    public function dashboard(): JsonResponse { return response()->json(['users' => User::count(), 'registrations' => Registration::count(), 'check_ins' => CheckIn::count()]); }
    public function users() { return User::withCount('registrations')->latest()->paginate(); }
    public function checkIns() { return CheckIn::with('registration.user', 'staff')->latest('checked_in_at')->paginate(); }
}