<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'email' => ['required', 'email', 'unique:users'], 'password' => ['required', 'min:8']]);
        $user = User::create($data);
        return response()->json(['data' => new UserResource($user), 'token' => $user->createToken('web')->plainTextToken], 201);
    }

    public function login(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required']]);
        $user = User::where('email', $data['email'])->first();
        abort_unless($user && Hash::check($data['password'], $user->password), 422, 'Invalid credentials.');
        return response()->json(['data' => new UserResource($user), 'token' => $user->createToken('web')->plainTextToken]);
    }

    public function adminLogin(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required']]);
        $user = User::where('email', $data['email'])->first();
        abort_unless($user && Hash::check($data['password'], $user->password), 422, 'Invalid credentials.');
        abort_unless($user->role === 'admin', 403, 'Admin access is required.');
        return response()->json(['data' => new UserResource($user), 'token' => $user->createToken('admin-web')->plainTextToken]);
    }

    public function logout(Request $request): \Illuminate\Http\JsonResponse { $request->user()->currentAccessToken()?->delete(); return response()->json(['message' => 'Logged out.']); }
}
