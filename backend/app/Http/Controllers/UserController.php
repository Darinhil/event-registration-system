<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function show(Request $request): UserResource { return new UserResource($request->user()->load('registrations.checkIn')); }

    public function updateProfile(Request $request): UserResource
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'username' => ['nullable', 'string', 'max:80', Rule::unique('users', 'username')->ignore($user->id)],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        if ($request->hasFile('profile_photo')) {
            $data['profile_photo'] = $request->file('profile_photo')->store('profile-photos', 'public');
        }

        $user->update($data);

        return new UserResource($user->fresh());
    }

    public function updatePassword(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (!Hash::check($data['current_password'], $request->user()->password)) {
            return response()->json(['message' => 'The current password is incorrect.'], 422);
        }

        $request->user()->update(['password' => $data['new_password']]);

        return response()->json(['message' => 'Password changed successfully.']);
    }

    public function profilePhoto(Request $request)
    {
        $path = $request->user()->profile_photo;
        abort_unless($path && Storage::disk('public')->exists($path), 404);

        return response()->file(Storage::disk('public')->path($path), [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }
}
