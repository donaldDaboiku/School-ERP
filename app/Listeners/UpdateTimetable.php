<?php

namespace App\Listeners;

use App\Events\TeacherAssigned;

class UpdateTimetable
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
        if (!$teacher) {
            \Illuminate\Support\Facades\Log::warning('UpdateTimetable: missing teacher');
            return;
        }

        \Illuminate\Support\Facades\Log::info('UpdateTimetable: assignment received', [
            'teacher_id' => $teacher->id,
            'class_ids' => $event->classIds ?? [],
            'subject_ids' => $event->subjectIds ?? [],
            'role' => $event->role ?? null,
        ]);
    }
}
