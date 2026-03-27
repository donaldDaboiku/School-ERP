<?php

namespace App\Listeners;

use App\Events\TeacherAssigned;
use Illuminate\Support\Facades\Mail;

class NotifyTeacherOfAssignment
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
    public function handle(TeacherAssigned $event): void
    {
        $teacher = $event->teacher ?? null;
        if (!$teacher || !$teacher->user || empty($teacher->user->email)) {
            \Illuminate\Support\Facades\Log::warning('NotifyTeacherOfAssignment: missing teacher/email');
            return;
        }

        if (view()->exists('emails.teacher-assignment')) {
            Mail::send('emails.teacher-assignment', [
                'teacher' => $teacher,
                'class_ids' => $event->classIds ?? [],
                'subject_ids' => $event->subjectIds ?? [],
                'role' => $event->role ?? null,
            ], function ($message) use ($teacher) {
                $message->to($teacher->user->email)->subject('New Teaching Assignment');
            });
        }
    }
}
