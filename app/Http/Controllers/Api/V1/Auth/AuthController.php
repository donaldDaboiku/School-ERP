<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Events\UserLoggedIn;
use App\Events\UserRegistered;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request)
    {
        if (! Auth::attempt($request->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        $user = User::with(['roles', 'school'])->findOrFail($request->user()->id);
        $token = $user->createToken('school-management-api')->plainTextToken;

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        event(new UserLoggedIn($user, $request->ip(), $request->userAgent()));

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'token' => $token,
            'user' => $this->formatUser($user),
        ]);
    }

    public function register(RegisterRequest $request)
    {
        $data = $request->validated();
        $userType = $data['user_type'] ?? 'admin';

        $user = User::create([
            'name' => $data['name'],
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'middle_name' => $data['middle_name'] ?? null,
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'phone' => $data['phone'] ?? null,
            'gender' => $data['gender'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'address' => $data['address'] ?? null,
            'school_id' => $data['school_id'] ?? null,
            'user_type' => $userType,
            'status' => 'active',
        ]);

        $role = Role::query()
            ->when($user->school_id, fn ($query) => $query->where('school_id', $user->school_id))
            ->where('slug', str_replace('_', '-', $userType))
            ->first();

        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        $user->load(['roles', 'school']);
        $token = $user->createToken('school-management-api')->plainTextToken;

        event(new UserRegistered($user, $data['password']));

        return response()->json([
            'success' => true,
            'message' => 'Registration successful',
            'token' => $token,
            'user' => $this->formatUser($user),
        ], 201);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }

    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'user_type' => $user->user_type,
            'status' => $user->status,
            'school' => $user->school ? [
                'id' => $user->school->id,
                'name' => $user->school->name,
            ] : null,
            'roles' => $user->roles->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
            ])->values(),
        ];
    }
}
