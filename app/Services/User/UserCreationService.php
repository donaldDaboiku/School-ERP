<?php

namespace App\Services\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserCreationService
{
    /**
     * Create a new user.
     */
    public function create(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $payload = $data;

            if (empty($payload['name'])) {
                $first = $payload['first_name'] ?? '';
                $last = $payload['last_name'] ?? '';
                $payload['name'] = trim($first . ' ' . $last);
            }

            if (empty($payload['status'])) {
                $payload['status'] = 'active';
            }

            if (empty($payload['username'])) {
                $payload['username'] = $this->generateUsername($payload);
            }

            if (!empty($payload['password'])) {
                $payload['password'] = Hash::make($payload['password']);
                $payload['password_changed_at'] = now();
            } else {
                $payload['password'] = Hash::make(Str::random(12));
                $payload['password_changed_at'] = now();
            }

            return User::create($payload);
        });
    }

    private function generateUsername(array $data): string
    {
        $base = '';

        if (!empty($data['email'])) {
            $base = Str::before($data['email'], '@');
        } elseif (!empty($data['first_name']) || !empty($data['last_name'])) {
            $base = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));
            $base = Str::slug($base, '.');
        } elseif (!empty($data['name'])) {
            $base = Str::slug($data['name'], '.');
        } else {
            $base = 'user';
        }

        $base = Str::lower($base);
        $username = $base;
        $suffix = 1;

        while (User::where('username', $username)->exists()) {
            $suffix++;
            $username = $base . $suffix;
        }

        return $username;
    }
}
