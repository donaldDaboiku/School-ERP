<?php

namespace App\Listeners;

use App\Events\UserLoggedIn;

class RecordLoginActivity
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
    public function handle(UserLoggedIn $event): void
    {
        $user = $event->user ?? null;
        if (!$user) {
            \Illuminate\Support\Facades\Log::warning('RecordLoginActivity: missing user');
            return;
        }

        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => $event->ip ?? request()->ip(),
        ]);
    }
}
