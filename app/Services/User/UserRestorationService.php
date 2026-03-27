<?php

namespace App\Services\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserRestorationService
{
    /**
     * Restore a soft-deleted user.
     */
    public function restore(User $user): User
    {
        return DB::transaction(function () use ($user) {
            if (method_exists($user, 'restore') && $user->trashed()) {
                $user->restore();
            }

            return $user->fresh();
        });
    }

    /**
     * Restore by id (including trashed).
     */
    public function restoreById(int $userId): User
    {
        return DB::transaction(function () use ($userId) {
            $user = User::withTrashed()->findOrFail($userId);

            if ($user->trashed()) {
                $user->restore();
            }

            return $user->fresh();
        });
    }
}
