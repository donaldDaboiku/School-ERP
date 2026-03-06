<?php

namespace App\Providers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],

        // User Events
        \App\Events\UserRegistered::class => [
            \App\Listeners\SendWelcomeEmail::class,
            \App\Listeners\CreateUserProfile::class,
            \App\Listeners\AssignDefaultRole::class,
        ],

        \App\Events\UserLoggedIn::class => [
            \App\Listeners\RecordLoginActivity::class,
            \App\Listeners\SendLoginNotification::class,
        ],

        \App\Events\UserPasswordChanged::class => [
            \App\Listeners\SendPasswordChangedNotification::class,
        ],

        // Student Events
        \App\Events\StudentCreated::class => [
            \App\Listeners\GenerateStudentId::class,
            \App\Listeners\CreateStudentProfile::class,
            \App\Listeners\SendStudentWelcomeEmail::class,
            \App\Listeners\NotifyParentsOfEnrollment::class,
        ],

        \App\Events\StudentPromoted::class => [
            \App\Listeners\UpdateStudentClass::class,
            \App\Listeners\GeneratePromotionReport::class,
            \App\Listeners\NotifyParentsOfPromotion::class,
        ],

        \App\Events\StudentAttended::class => [
            \App\Listeners\RecordAttendanceInLog::class,
            \App\Listeners\CheckAttendanceThreshold::class,
            \App\Listeners\SendAbsenceNotification::class,
        ],

        \App\Events\GradeAssigned::class => [
            \App\Listeners\CalculateStudentGPA::class,
            \App\Listeners\UpdateClassRanking::class,
            \App\Listeners\NotifyParentsOfGrade::class,
            \App\Listeners\CheckAcademicPerformance::class,
        ],

        // Teacher Events
        \App\Events\TeacherAssigned::class => [
            \App\Listeners\UpdateTeacherWorkload::class,
            \App\Listeners\NotifyTeacherOfAssignment::class,
            \App\Listeners\UpdateTimetable::class,
        ],

        \App\Events\ClassScheduled::class => [
            \App\Listeners\NotifyTeacherOfSchedule::class,
            \App\Listeners\UpdateClassCalendar::class,
            \App\Listeners\CheckScheduleConflicts::class,
        ],

        // Parent Events
        \App\Events\ParentRegistered::class => [
            // \App\Listeners\LinkParentToStudents::class,
            // \App\Listeners\SendParentWelcomeEmail::class,
            // \App\Listeners\SetCommunicationPreferences::class,
        ],

        \App\Events\FeePaymentReceived::class => [
            // \App\Listeners\UpdatePaymentStatus::class,
            // \App\Listeners\GenerateReceipt::class,
            // \App\Listeners\SendPaymentConfirmation::class,
            // \App\Listeners\UpdateFinancialReport::class,
        ],

        \App\Events\FeePaymentOverdue::class => [
            // \App\Listeners\SendPaymentReminder::class,
            // \App\Listeners\UpdateOverdueStatus::class,
            // \App\Listeners\NotifyAccountant::class,
        ],

        // Meeting Events
        \App\Events\ParentTeacherMeetingScheduled::class => [
            // \App\Listeners\SendMeetingInvitation::class,
            // \App\Listeners\AddToCalendar::class,
            // \App\Listeners\NotifyAllParticipants::class,
        ],

        // \App\Events\MeetingReminder::class => [
        //     \App\Listeners\SendMeetingReminder::class,
        // ],

        // Communication Events
        \App\Events\NotificationSent::class => [
            // \App\Listeners\LogNotification::class,
            // \App\Listeners\UpdateNotificationStatus::class,
        ],

        \App\Events\AnnouncementPublished::class => [
            // \App\Listeners\BroadcastAnnouncement::class,
            // \App\Listeners\SendAnnouncementNotifications::class,
            // \App\Listeners\ArchiveAnnouncement::class,
        ],

        // System Events
        \App\Events\ReportGenerated::class => [
            // \App\Listeners\StoreReport::class,
            // \App\Listeners\SendReportNotification::class,
        ],

        \App\Events\BackupCreated::class => [
            // \App\Listeners\VerifyBackup::class,
            // \App\Listeners\CleanOldBackups::class,
            // \App\Listeners\NotifyAdminOfBackup::class,
        ],

        \App\Events\SystemMaintenance::class => [
            // \App\Listeners\NotifyUsersOfMaintenance::class,
            // \App\Listeners\PutSystemInMaintenanceMode::class,
        ],

        // Security Events
        \App\Events\SuspiciousActivityDetected::class => [
            // \App\Listeners\LogSecurityEvent::class,
            // \App\Listeners\NotifySecurityTeam::class,
            // \App\Listeners\BlockSuspiciousIP::class,
        ],

        \App\Events\FailedLoginAttempt::class => [
            // \App\Listeners\RecordFailedLogin::class,
            // \App\Listeners\CheckForBruteForce::class,
        ],
    ];

    /**
     * The subscriber classes to register.
     *
     * @var array
     */
    protected $subscribe = [
        // \App\Listeners\UserEventSubscriber::class,
        // \App\Listeners\StudentEventSubscriber::class,
        // \App\Listeners\TeacherEventSubscriber::class,
        // \App\Listeners\FinancialEventSubscriber::class,
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        parent::boot();

        // Register model observers
        $this->registerObservers();
    }

    /**
     * Register model observers.
     *
     * @return void
     */
    protected function registerObservers()
    {
        \App\Models\User::observe(\App\Observers\UserObserver::class);
        // \App\Models\Student::observe(\App\Observers\StudentObserver::class);
        // \App\Models\Teacher::observe(\App\Observers\TeacherObserver::class);
        // \App\Models\ParentGuardian::observe(\App\Observers\ParentObserver::class);
        // \App\Models\Grade::observe(\App\Observers\GradeObserver::class);
        // \App\Models\Attendance::observe(\App\Observers\AttendanceObserver::class);
        // \App\Models\FinancialTransaction::observe(\App\Observers\FinancialTransactionObserver::class);
        // \App\Models\FeePayment::observe(\App\Observers\FeePaymentObserver::class);
        // \App\Models\ParentTeacherMeeting::observe(\App\Observers\MeetingObserver::class);
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    public function shouldDiscoverEvents()
    {
        return false;
    }
}