<?php

namespace App\Listeners;

use App\Events\UserRegistered;

class CreateUserProfile
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(UserRegistered $event): void
    {
        $user = $event->user ?? null;
        if (!$user) {
            \Illuminate\Support\Facades\Log::warning('CreateUserProfile: missing user');
            return;
        }

        if (!$user->profile) {
            $user->profile()->create([
                'phone' => $user->phone,
                'address' => $user->address,
                'avatar' => $user->avatar,
                'date_of_birth' => $user->date_of_birth,
                'gender' => $user->gender,
            ]);
        }
    }
}
