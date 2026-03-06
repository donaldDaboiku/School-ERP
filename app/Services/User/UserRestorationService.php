<?php

namespace App\Services\User;

use App\Models\User;

class UserRestorationService
{
    public function beforeRestore(User $user): void
    {
        // validation before restore
    }

    public function afterRestore(User $user): void
    {
        // notifications & cache clearing
    }
}
