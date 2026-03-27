<?php

namespace App\Listeners;

use App\Events\TeacherAssigned;

class UpdateTeacherWorkload
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
            \Illuminate\Support\Facades\Log::warning('UpdateTeacherWorkload: missing teacher');
            return;
        }

        $service = new \App\Services\TeacherService();
        $workload = $service->getTeacherWorkload($teacher->id, $event->academicYear ?? null);
        \Illuminate\Support\Facades\Cache::put("teacher_workload_{$teacher->id}", $workload, now()->addHours(6));
    }
}
