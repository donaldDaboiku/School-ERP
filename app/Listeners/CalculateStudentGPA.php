<?php

namespace App\Listeners;

use App\Events\GradeAssigned;

class CalculateStudentGPA
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
    public function handle(GradeAssigned $event): void
    {
        $result = $event->result ?? null;
        if (!$result || !$result->student) {
            \Illuminate\Support\Facades\Log::warning('CalculateStudentGPA: missing result/student');
            return;
        }

        $average = $result->student->results()
            ->where('term_id', $result->term_id)
            ->where('is_finalized', true)
            ->avg('percentage') ?? 0;

        $custom = $result->student->custom_fields ?? [];
        $custom['term_average'] = $custom['term_average'] ?? [];
        $custom['term_average'][$result->term_id] = round($average, 2);
        $result->student->update(['custom_fields' => $custom]);
    }
}
