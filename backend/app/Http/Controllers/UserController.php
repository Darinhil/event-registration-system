<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function show(Request $request): UserResource
    {
        // A registration must not remain visible if its event was removed.
        // The event delete flow removes these rows, while this constraint also
        // protects users from seeing legacy orphaned registrations.
        $user = $request->user()->load([
            'registrations' => fn ($query) => $query->whereHas('event')->with('checkIn', 'event'),
        ]);
        $fields = $user->registrations->isNotEmpty()
            ? \App\Models\FormField::whereIn('event_id', $user->registrations->pluck('event_id'))->orderBy('sort_order')->get()
            : collect();
        $user->registrations->each(fn (\App\Models\Registration $registration) => $registration->append_form_values($fields->where('event_id', $registration->event_id)));

        return new UserResource($user);
    }

    public function updateProfile(Request $request): UserResource
    {
        $user = $request->user();
        $request->validate([
            'profile_photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $newPath = $request->file('profile_photo')->store('profile-photos', 'public');
        $oldPath = $user->profile_photo;
        $user->update(['profile_photo' => $newPath]);
        if ($oldPath && $oldPath !== $newPath) Storage::disk('public')->delete($oldPath);

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
