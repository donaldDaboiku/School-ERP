<?php

namespace App\Listeners;

use App\Events\GradeAssigned;
use Illuminate\Support\Facades\Auth;

class CheckAcademicPerformance
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
            \Illuminate\Support\Facades\Log::warning('CheckAcademicPerformance: missing result/student');
            return;
        }

        $average = $result->student->results()
            ->where('term_id', $result->term_id)
            ->where('is_finalized', true)
            ->avg('percentage') ?? 0;

        if ($average < 50) {
            foreach ($result->student->parents as $parent) {
                \App\Models\ParentCommunicationLog::create([
                    'parent_id' => $parent->id,
                    'type' => 'academic_alert',
                    'title' => 'Academic Performance Alert',
                    'message' => 'Average score is ' . round($average, 2) . '%. Please review progress.',
                    'sent_via' => 'system',
                    'sent_by' => Auth::id(),
                    'status' => 'sent',
                ]);
            }
        }
    }
}
