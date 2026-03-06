<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Broadcast::routes();

        // Authenticate user's personal channel
        Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
            return (int) $user->id === (int) $id;
        });

        // Channel for teacher notifications
        Broadcast::channel('teacher.{teacherId}.notifications', function ($user, $teacherId) {
            return $user->hasRole('teacher') && (int) $user->teacher->id === (int) $teacherId;
        });

        // Channel for student notifications
        Broadcast::channel('student.{studentId}.notifications', function ($user, $studentId) {
            return $user->hasRole('student') && (int) $user->student->id === (int) $studentId;
        });

        // Channel for parent notifications
        Broadcast::channel('parent.{parentId}.notifications', function ($user, $parentId) {
            return $user->hasRole('parent') && (int) $user->parent->id === (int) $parentId;
        });

        // Channel for admin notifications
        Broadcast::channel('admin.notifications', function ($user) {
            return $user->hasAnyRole(['admin', 'super-admin']);
        });

        // Channel for classroom activities
        Broadcast::channel('classroom.{classId}', function ($user, $classId) {
            if ($user->hasRole('teacher')) {
                return $user->teacher->classes()->where('class_id', $classId)->exists();
            }
            
            if ($user->hasRole('student')) {
                return $user->student->class_id === (int) $classId;
            }
            
            return $user->hasAnyRole(['admin', 'super-admin']);
        });

        // Channel for school-wide announcements
        Broadcast::channel('school.announcements', function ($user) {
            return !is_null($user);
        });

        // Channel for live attendance updates
        Broadcast::channel('attendance.updates.{classId}', function ($user, $classId) {
            return $user->hasAnyRole(['teacher', 'admin', 'super-admin']) ||
                   ($user->hasRole('student') && $user->student->class_id === (int) $classId);
        });

        // Channel for grade updates
        Broadcast::channel('grades.updates.{studentId}', function ($user, $studentId) {
            if ($user->hasRole('student')) {
                return $user->id === (int) $studentId;
            }
            
            if ($user->hasRole('parent')) {
                return $user->parent->students()->where('student_id', $studentId)->exists();
            }
            
            return $user->hasAnyRole(['teacher', 'admin', 'super-admin']);
        });

        // Channel for fee payment updates
        Broadcast::channel('fees.updates.{studentId}', function ($user, $studentId) {
            if ($user->hasRole('parent')) {
                return $user->parent->students()->where('student_id', $studentId)->exists();
            }
            
            return $user->hasAnyRole(['admin', 'super-admin', 'accountant']);
        });

        // Channel for parent-teacher meeting updates
        Broadcast::channel('meetings.{meetingId}', function ($user, $meetingId) {
            // Users can access meeting channel if they are participants
            $meeting = \App\Models\ParentTeacherMeeting::find($meetingId);
            
            if (!$meeting) {
                return false;
            }
            
            if ($user->hasRole('parent')) {
                return $user->parent->id === $meeting->parent_id;
            }
            
            if ($user->hasRole('teacher')) {
                return $user->teacher->id === $meeting->teacher_id;
            }
            
            if ($user->hasRole('student')) {
                return $user->student->id === $meeting->student_id;
            }
            
            return $user->hasAnyRole(['admin', 'super-admin']);
        });

        // Channel for real-time chat
        // Broadcast::channel('chat.{chatId}', function ($user, $chatId) {
        //     $chat = \App\Models\Chat::find($chatId);
            
            // if (!$chat) {
            //     return false;
            // }
            
            // Check if user is a participant in the chat
        //     return $chat->participants()->where('user_id', $user->id)->exists();
        // });

        // Presence channel for online users
        Broadcast::channel('online.users', function ($user) {
            if ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'role' => $user->getRoleNames()->first(),
                    'avatar' => $user->profile_picture
                ];
            }
        });

        // Presence channel for classroom online status
        Broadcast::channel('classroom.{classId}.online', function ($user, $classId) {
            if ($user->hasRole('teacher')) {
                $isTeaching = $user->teacher->classes()->where('class_id', $classId)->exists();
                if ($isTeaching) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'role' => 'teacher',
                        'teacher_id' => $user->teacher->id
                    ];
                }
            }
            
            if ($user->hasRole('student') && $user->student->class_id === (int) $classId) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'role' => 'student',
                    'student_id' => $user->student->id,
                    'roll_number' => $user->student->roll_number
                ];
            }
            
            return false;
        });

        require base_path('routes/channels.php');
    }
}