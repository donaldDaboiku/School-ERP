<?php

namespace App\Listeners;

use App\Events\ClassScheduled;
use Illuminate\Support\Facades\Mail;

class NotifyTeacherOfSchedule
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
    public function handle(ClassScheduled $event): void
    {
        $payload = $event->payload ?? [];
        $teacherId = $payload['teacher_id'] ?? null;
        $teacher = $teacherId ? \App\Models\Teacher::find($teacherId) : null;

        if (!$teacher || !$teacher->user || empty($teacher->user->email)) {
            \Illuminate\Support\Facades\Log::warning('NotifyTeacherOfSchedule: missing teacher/email');
            return;
        }

        if (view()->exists('emails.teacher-schedule')) {
            Mail::send('emails.teacher-schedule', [
                'teacher' => $teacher,
                'timetable' => $event->timetable,
                'payload' => $payload,
            ], function ($message) use ($teacher) {
                $message->to($teacher->user->email)->subject('Class Schedule Update');
            });
        }
    }
}
