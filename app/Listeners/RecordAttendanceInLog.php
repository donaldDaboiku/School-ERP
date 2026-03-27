<?php

namespace App\Listeners;

use App\Events\StudentAttended;
use App\Models\ParentCommunicationLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class RecordAttendanceInLog
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
            Log::warning('RecordAttendanceInLog: missing attendance/student');
            return;
        }

        foreach ($attendance->student->parents as $parent) {
            ParentCommunicationLog::create([
                'parent_id' => $parent->id,
                'type' => 'attendance',
                'title' => 'Attendance Record',
                'message' => 'Attendance status: ' . $attendance->status . ' on ' . $attendance->attendance_date->format('Y-m-d') . '.',
                'sent_via' => 'system',
                'sent_by' => Auth::id(),
                'status' => 'sent',
            ]);
        }
    }
}
