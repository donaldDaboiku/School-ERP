<?php

namespace App\Listeners;

use App\Events\UserLoggedIn;

class SendLoginNotification
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
        if (!$user || empty($user->email)) {
            \Illuminate\Support\Facades\Log::warning('SendLoginNotification: missing user/email');
            return;
        }

        if (view()->exists('emails.login-notification')) {
            \Illuminate\Support\Facades\Mail::send('emails.login-notification', [
                'user' => $user,
                'ip' => $event->ip ?? request()->ip(),
                'user_agent' => $event->userAgent ?? request()->userAgent(),
            ], function ($message) use ($user) {
                $message->to($user->email)->subject('New Login Notification');
            });
        } else {
            \Illuminate\Support\Facades\Log::info('SendLoginNotification: view not found', ['view' => 'emails.login-notification']);
        }
    }
}
