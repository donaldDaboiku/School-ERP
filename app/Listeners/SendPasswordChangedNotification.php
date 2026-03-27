<?php

namespace App\Listeners;

use App\Events\UserPasswordChanged;

class SendPasswordChangedNotification
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
    public function handle(UserPasswordChanged $event): void
    {
        $user = $event->user ?? null;
        if (!$user || empty($user->email)) {
            \Illuminate\Support\Facades\Log::warning('SendPasswordChangedNotification: missing user/email');
            return;
        }

        if (view()->exists('emails.password-changed')) {
            \Illuminate\Support\Facades\Mail::send('emails.password-changed', [
                'user' => $user,
            ], function ($message) use ($user) {
                $message->to($user->email)->subject('Password Changed');
            });
        } else {
            \Illuminate\Support\Facades\Log::info('SendPasswordChangedNotification: view not found', ['view' => 'emails.password-changed']);
        }
    }
}
