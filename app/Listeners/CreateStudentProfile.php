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
                'phone' => $student->user->phone,
                'address' => $student->user->address,
                'avatar' => $student->user->avatar,
                'date_of_birth' => $student->user->date_of_birth,
                'gender' => $student->user->gender,
            ]);
        }
    }
}
