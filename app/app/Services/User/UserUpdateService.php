<?php

namespace App\Services\User;

use App\Models\User;

class UserUpdateService
{
    public function beforeUpdate(User $user): void
    {
        // logic before updating user
    }

    public function afterUpdate(User $user): void
    {
        // logic after updating user
    }
}
