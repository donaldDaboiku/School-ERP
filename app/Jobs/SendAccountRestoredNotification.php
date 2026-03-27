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

class SendAccountRestoredNotification implements ShouldQueue
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
            Log::warning('SendAccountRestoredNotification: missing user email', ['user_id' => $this->user->id]);
            return;
        }

        if (view()->exists('emails.account-restored')) {
            Mail::send('emails.account-restored', [
                'user' => $this->user,
            ], function ($message) {
                $message->to($this->user->email)->subject('Account Restored');
            });
            return;
        }

        Log::info('SendAccountRestoredNotification: view not found', ['view' => 'emails.account-restored']);
    }
}
