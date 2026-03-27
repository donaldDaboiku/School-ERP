<?php

namespace App\Listeners;

use App\Events\GradeAssigned;
Use App\Models\ParentCommunicationLog;
Use Illuminate\Support\Facades\Log;
Use Illuminate\Support\Facades\Auth;
Use Illuminate\Support\Facades\Mail;

class NotifyParentsOfGrade
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
             Log::warning('NotifyParentsOfGrade: missing result/student');
            return;
        }

        foreach ($result->student->parents as $parent) {
            ParentCommunicationLog::create([
                'parent_id' => $parent->id,
                'type' => 'grade',
                'title' => 'Grade Update',
                'message' => 'New grade recorded for ' . ($result->subject->name ?? 'subject') . '.',
                'sent_via' => 'system',
                'sent_by' => Auth::id(),
                'status' => 'sent',
            ]);

            if ($parent->user && $parent->user->email && view()->exists('emails.grade-notification')) {
                 Mail::send('emails.grade-notification', [
                    'result' => $result,
                    'student' => $result->student,
                    'parent' => $parent,
                ], function ($message) use ($parent) {
                    $message->to($parent->user->email)->subject('Grade Update');
                });
            }
        }
    }
}
