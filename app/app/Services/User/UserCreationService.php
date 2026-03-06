<?php

namespace App\Services\User;

use App\Models\User;

class UserCreationService
{
    public function beforeCreate(User $user): void
    {
        // logic before creating user
    }

    public function afterCreate(User $user): void
    {
        // logic after user is created
    }
}
