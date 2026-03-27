<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendEmailVerificationNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public User $user;

    /**
     * Create a new job instance.
     */
    public function __construct(User $user)
    {
        $this->user = $user;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (!$this->user->email) {
            Log::warning('SendEmailVerificationNotification: missing user email', ['user_id' => $this->user->id]);
            return;
        }

        if (view()->exists('emails.verify-email')) {
            $token = $this->user->email_verification_token;
            $verifyUrl = url('/email/verify/' . $token);

            Mail::send('emails.verify-email', [
                'user' => $this->user,
                'verify_url' => $verifyUrl,
            ], function ($message) {
                $message->to($this->user->email)->subject('Verify Your Email');
            });
            return;
        }

        Log::info('SendEmailVerificationNotification: view not found', ['view' => 'emails.verify-email']);
    }
}
