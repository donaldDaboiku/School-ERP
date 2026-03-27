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
                'timezone' => config('app.timezone'),
                'locale' => config('app.locale'),
                'notifications_enabled' => true,
                'two_factor_enabled' => false,
            ]);
        }
    }
}
