<?php

namespace App\Listeners;

use App\Events\StudentAttended;

class SendAbsenceNotification
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
    public function handle(StudentAttended $event): void
    {
        $attendance = $event->attendance ?? null;
        if (!$attendance || !$attendance->student) {
            \Illuminate\Support\Facades\Log::warning('SendAbsenceNotification: missing attendance/student');
            return;
        }

        if (in_array($attendance->status, ['absent', 'late'], true)) {
            (new \App\Services\NotificationService())->sendAttendanceNotification([
                'student_id' => $attendance->student_id,
                'date' => $attendance->attendance_date,
                'status' => $attendance->status,
            ]);
            $attendance->markAsNotified();
        }
    }
}
