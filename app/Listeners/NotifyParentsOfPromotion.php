<?php

namespace App\Listeners;

use App\Events\StudentPromoted;
Use App\Models\ParentCommunicationLog;
Use Illuminate\Support\Facades\Auth;

class NotifyParentsOfPromotion
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
            \Illuminate\Support\Facades\Log::warning('NotifyParentsOfPromotion: missing student');
            return;
        }

        foreach ($student->parents as $parent) {
             ParentCommunicationLog::create([
                'parent_id' => $parent->id,
                'type' => 'promotion',
                'title' => 'Student Promotion',
                'message' => $student->full_name . ' has been promoted.',
                'sent_via' => 'system',
                'sent_by' => Auth::id(),
                'status' => 'sent',
            ]);

            if ($parent->user && $parent->user->email && view()->exists('emails.promotion')) {
                \Illuminate\Support\Facades\Mail::send('emails.promotion', [
                    'student' => $student,
                    'parent' => $parent,
                ], function ($message) use ($parent) {
                    $message->to($parent->user->email)->subject('Student Promotion');
                });
            }
        }
    }
}
