<?php

namespace App\Services\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserUpdateService
{
    /**
     * Update an existing user.
     */
    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $payload = $data;

            if (empty($payload['name']) && (isset($payload['first_name']) || isset($payload['last_name']))) {
                $first = $payload['first_name'] ?? $user->first_name;
                $last = $payload['last_name'] ?? $user->last_name;
                $payload['name'] = trim(($first ?? '') . ' ' . ($last ?? ''));
            }

            if (!empty($payload['password'])) {
                $payload['password'] = Hash::make($payload['password']);
                $payload['password_changed_at'] = now();
            } else {
                unset($payload['password']);
            }

            $user->update($payload);

            return $user->fresh();
        });
    }
}
