<?php

namespace App\Listeners;

use App\Events\UserRegistered;

class SendWelcomeEmail
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
            \Illuminate\Support\Facades\Log::warning('SendWelcomeEmail: missing user');
            return;
        }

        (new \App\Services\NotificationService())->sendWelcomeEmail($user, $event->password ?? null);
    }
}
