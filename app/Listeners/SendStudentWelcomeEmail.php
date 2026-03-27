<?php

namespace App\Listeners;

use App\Events\StudentCreated;

class SendStudentWelcomeEmail
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
        if (!$student) {
            \Illuminate\Support\Facades\Log::warning('SendStudentWelcomeEmail: missing student');
            return;
        }

        (new \App\Services\NotificationService())->sendStudentAdmissionNotification($student);
    }
}
