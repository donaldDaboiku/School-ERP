<?php

namespace App\Listeners;

use App\Events\StudentCreated;
use App\Models\ParentCommunicationLog;
Use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class NotifyParentsOfEnrollment
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
             Log::warning('NotifyParentsOfEnrollment: missing student');
            return;
        }

        foreach ($student->parents as $parent) {
            ParentCommunicationLog::create([
                'parent_id' => $parent->id,
                'type' => 'enrollment',
                'title' => 'Student Enrollment',
                'message' => $student->full_name . ' has been enrolled.',
                'sent_via' => 'system',
                'sent_by' => Auth::id(),
                'status' => 'sent',
            ]);
        }
    }
}
