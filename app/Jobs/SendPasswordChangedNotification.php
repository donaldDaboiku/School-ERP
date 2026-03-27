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

class SendPasswordChangedNotification implements ShouldQueue
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
            Log::warning('SendPasswordChangedNotification: missing user email', ['user_id' => $this->user->id]);
            return;
        }

        if (view()->exists('emails.password-changed')) {
            Mail::send('emails.password-changed', [
                'user' => $this->user,
            ], function ($message) {
                $message->to($this->user->email)->subject('Password Changed');
            });
            return;
        }

        Log::info('SendPasswordChangedNotification: view not found', ['view' => 'emails.password-changed']);
    }
}
