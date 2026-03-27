<?php

namespace App\Listeners;

use App\Events\ClassScheduled;

class UpdateClassCalendar
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
        $schoolId = $payload['school_id'] ?? null;
        $academicSessionId = $payload['academic_session_id'] ?? null;

        if (!$schoolId || !$academicSessionId) {
            \Illuminate\Support\Facades\Log::warning('UpdateClassCalendar: missing school/session');
            return;
        }

        $academic = new \App\Services\AcademicService();
        $calendar = $academic->generateAcademicCalendar($schoolId, $academicSessionId);
        \Illuminate\Support\Facades\Cache::put("academic_calendar_{$schoolId}_{$academicSessionId}", $calendar, now()->addHours(6));
    }
}
