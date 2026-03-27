<?php

namespace App\Listeners;

use App\Events\StudentPromoted;
Use Illuminate\Support\Facades\Log;

class GeneratePromotionReport
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
        if (!$event->toClassId || !$event->termId) {
            Log::warning('GeneratePromotionReport: missing class/term');
            return;
        }

        $academic = new \App\Services\AcademicService();
        $ranking = $academic->calculateClassRank($event->toClassId, $event->termId);
        \Illuminate\Support\Facades\Cache::put("class_rank_{$event->toClassId}_{$event->termId}", $ranking, now()->addHours(6));
    }
}
