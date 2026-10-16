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
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'identifier' => ['required', 'string', 'max:150'], 'password' => ['required', 'min:8']]);
        $identifier = trim($data['identifier']);
        $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;

        if ($isEmail) {
            $request->validate(['identifier' => ['email', 'unique:users,email']]);
            $account = ['email' => $identifier, 'phone' => null];
        } else {
            $request->validate(['identifier' => ['regex:/^[+]?[0-9][0-9\s().-]{7,24}$/', 'unique:users,phone']]);
            $account = ['email' => null, 'phone' => $identifier];
        }

        $user = User::create([...$account, 'name' => $data['name'], 'password' => $data['password']]);
        return response()->json(['data' => new UserResource($user), 'token' => $user->createToken('web')->plainTextToken], 201);
    }

    public function login(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate(['identifier' => ['required', 'string', 'max:150'], 'password' => ['required']]);
        $identifier = trim($data['identifier']);
        $user = User::where('email', $identifier)->orWhere('phone', $identifier)->first();
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
