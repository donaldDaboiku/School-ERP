<?php

namespace App\Listeners;

use App\Events\StudentCreated;
Use Illuminate\Support\Facades\Log;

class GenerateStudentId
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
             Log::warning('GenerateStudentId: missing student/user');
            return;
        }

        if (!$student->user->student_id) {
            $schoolCode = $student->school->code ?? 'SCH';
            $year = now()->format('y');
            $sequence = str_pad((string) $student->id, 4, '0', STR_PAD_LEFT);
            $student->user->student_id = $schoolCode . $year . $sequence;
            $student->user->save();
        }
    }
}
