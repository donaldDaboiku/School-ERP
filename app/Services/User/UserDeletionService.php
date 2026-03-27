<?php

namespace App\Services\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserDeletionService
{
    /**
     * Soft delete a user by default.
     */
    public function delete(User $user, bool $force = false): bool
    {
        return DB::transaction(function () use ($user, $force) {
            if ($force) {
                $user->forceDelete();
            } else {
                $user->delete();
            }

            return true;
        });
    }
}
