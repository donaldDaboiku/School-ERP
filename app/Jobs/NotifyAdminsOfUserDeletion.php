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

class NotifyAdminsOfUserDeletion implements ShouldQueue
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
        $admins = User::where('school_id', $this->user->school_id)
            ->whereIn('user_type', ['super_admin', 'admin', 'principal'])
            ->where('status', 'active')
            ->get();

        if ($admins->isEmpty()) {
            Log::warning('NotifyAdminsOfUserDeletion: no admins found', ['user_id' => $this->user->id]);
            return;
        }

        foreach ($admins as $admin) {
            if (!$admin->email) {
                continue;
            }

            if (view()->exists('emails.user-deleted')) {
                Mail::send('emails.user-deleted', [
                    'admin' => $admin,
                    'user' => $this->user,
                ], function ($message) use ($admin) {
                    $message->to($admin->email)->subject('User Account Deleted');
                });
            } else {
                Log::info('NotifyAdminsOfUserDeletion: view not found', ['view' => 'emails.user-deleted']);
            }
        }
    }
}
