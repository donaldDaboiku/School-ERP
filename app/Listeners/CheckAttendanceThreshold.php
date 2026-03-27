<?php

namespace App\Listeners;

use App\Events\StudentAttended;
use Illuminate\Support\Facades\Auth;

class CheckAttendanceThreshold
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
            \Illuminate\Support\Facades\Log::warning('CheckAttendanceThreshold: missing attendance/student');
            return;
        }

        $query = \App\Models\Attendance::where('student_id', $attendance->student_id);
        if ($attendance->term_id) {
            $query->where('term_id', $attendance->term_id);
        }

        $total = $query->count();
        $present = $query->whereIn('status', ['present', 'late'])->count();
        $rate = $total > 0 ? round(($present / $total) * 100, 2) : 100;
        $threshold = 75;

        if ($rate < $threshold) {
            foreach ($attendance->student->parents as $parent) {
                \App\Models\ParentCommunicationLog::create([
                    'parent_id' => $parent->id,
                    'type' => 'attendance_alert',
                    'title' => 'Low Attendance Alert',
                    'message' => 'Attendance rate is ' . $rate . '%. Please monitor attendance.',
                    'sent_via' => 'system',
                    'sent_by' => Auth::id(),
                    'status' => 'sent',
                ]);
            }
        }
    }
}
