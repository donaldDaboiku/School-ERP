<?php

namespace App\Services\User;

use App\Models\User;

class UserDeletionService
{
    public function beforeDelete(User $user): void
    {
        // validation & audit before delete
    }

    public function afterDelete(User $user): void
    {
        // cleanup after delete
    }

    public function forceDelete(User $user): void
    {
        // permanent deletion
    }
}
