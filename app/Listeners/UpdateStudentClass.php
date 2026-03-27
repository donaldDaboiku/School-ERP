<?php

namespace App\Listeners;

use App\Events\StudentPromoted;

class UpdateStudentClass
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
    public function handle(StudentPromoted $event): void
    {
        $student = $event->student ?? null;
        if (!$student) {
            \Illuminate\Support\Facades\Log::warning('UpdateStudentClass: missing student');
            return;
        }

        if ($event->toClassId && $student->class_id !== $event->toClassId) {
            $student->update(['class_id' => $event->toClassId]);
        }
    }
}
