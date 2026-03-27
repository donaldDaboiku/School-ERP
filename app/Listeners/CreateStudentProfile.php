<?php

namespace App\Listeners;

use App\Events\StudentCreated;

class CreateStudentProfile
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
    public function handle(StudentCreated $event): void
    {
        $student = $event->student ?? null;
        if (!$student || !$student->user) {
            \Illuminate\Support\Facades\Log::warning('CreateStudentProfile: missing student/user');
            return;
        }

        if (!$student->user->profile) {
            $student->user->profile()->create([
                'timezone' => config('app.timezone'),
                'locale' => config('app.locale'),
                'notifications_enabled' => true,
                'two_factor_enabled' => false,
            ]);
        }
    }
}
