<?php

namespace App\Listeners;

use App\Events\GradeAssigned;

class UpdateClassRanking
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
        if (!$result || !$result->class_id || !$result->term_id) {
            \Illuminate\Support\Facades\Log::warning('UpdateClassRanking: missing result/class/term');
            return;
        }

        $academic = new \App\Services\AcademicService();
        $ranking = $academic->calculateClassRank($result->class_id, $result->term_id);
        \Illuminate\Support\Facades\Cache::put("class_rank_{$result->class_id}_{$result->term_id}", $ranking, now()->addHours(6));
    }
}
