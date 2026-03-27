<?php

namespace App\Listeners;

use App\Events\ClassScheduled;

class CheckScheduleConflicts
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
        if (empty($payload)) {
            \Illuminate\Support\Facades\Log::warning('CheckScheduleConflicts: missing payload');
            return;
        }

        \Illuminate\Support\Facades\Log::info('CheckScheduleConflicts: payload received', $payload);
    }
}
