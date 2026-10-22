<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
            'role' => ['nullable', 'in:'.implode(',', UserRole::values())],
        ]);

        $validated['password'] = Hash::make($validated['password']);
        $validated['role'] = UserRole::HR->value;

        $user = User::create($validated);
        \App\Models\AuditLog::create(['user_id' => $request->user()->id, 'action' => 'user_created', 'entity_type' => User::class, 'entity_id' => $user->id, 'new_values_json' => ['user' => $user->only(['id', 'name', 'email', 'role', 'status']), 'reason' => 'إنشاء حساب عبر التسجيل الإداري'], 'ip_address' => $request->ip()]);
        $token = $user->createToken('saudi-hr-settlement')->plainTextToken;

        return response()->json([
            'message' => 'User registered successfully.',
            'user' => array_merge($user->toArray(), ['effective_permissions' => $user->effectivePermissions()]),
            'token' => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::whereRaw('LOWER(email) = ?', [strtolower(trim($validated['email']))])->first();

        if (! $user || $user->status !== 'active' || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        $token = $user->createToken('saudi-hr-settlement')->plainTextToken;

        return response()->json([
            'message' => 'Authenticated successfully.',
            'user' => array_merge($user->toArray(), ['effective_permissions' => $user->effectivePermissions()]),
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    public function profile(Request $request)
    {
        return response()->json([
            'user' => array_merge($request->user()->toArray(), ['effective_permissions' => $request->user()->effectivePermissions()]),
        ]);
    }
}
