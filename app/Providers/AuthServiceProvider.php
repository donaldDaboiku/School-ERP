<?php

namespace App\Providers;

use App\Models\Payment;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Policies\PaymentPolicy;
use App\Policies\SchoolPolicy;
use App\Policies\StudentPolicy;
use App\Policies\TeacherPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Laravel\Passport\Passport;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        School::class => SchoolPolicy::class,
        Student::class => StudentPolicy::class,
        Teacher::class => TeacherPolicy::class,
        Payment::class => PaymentPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        // Define gates for role-based authorization
        $this->defineGates();

        // Passport API routes (if using Passport for API authentication)
        if (class_exists(Passport::class)) {
            $this->configurePassport();
        }
    }

    /**
     * Define application gates for role-based authorization.
     *
     * @return void
     */
    protected function defineGates()
    {
        // Super Admin can do everything
        Gate::before(function ($user, $ability) {
            if ($user->hasRole('super-admin')) {
                return true;
            }
        });

        // Admin permissions
        Gate::define('manage-users', function ($user) {
            return $user->hasAnyRole(['admin', 'super-admin']);
        });

        Gate::define('manage-roles', function ($user) {
            return $user->hasRole('super-admin');
        });

        Gate::define('manage-settings', function ($user) {
            return $user->hasAnyRole(['admin', 'super-admin']);
        });

        // Teacher permissions
        Gate::define('manage-students', function ($user) {
            return $user->hasAnyRole(['teacher', 'admin', 'super-admin']);
        });

        Gate::define('manage-grades', function ($user) {
            return $user->hasAnyRole(['teacher', 'admin', 'super-admin']);
        });

        Gate::define('manage-attendance', function ($user) {
            return $user->hasAnyRole(['teacher', 'admin', 'super-admin']);
        });

        // Parent permissions
        Gate::define('view-student-progress', function ($user) {
            return $user->hasRole('parent');
        });

        Gate::define('manage-fee-payments', function ($user) {
            return $user->hasAnyRole(['parent', 'admin', 'super-admin']);
        });

        // Student permissions
        Gate::define('view-own-grades', function ($user) {
            return $user->hasRole('student');
        });

        Gate::define('view-own-attendance', function ($user) {
            return $user->hasRole('student');
        });

        // Resource-based authorization
        Gate::define('update-student', function ($user, $student) {
            return $user->hasAnyRole(['admin', 'super-admin']) ||
                   ($user->hasRole('teacher') && $user->teachesStudent($student->id));
        });

        Gate::define('view-student', function ($user, $student) {
            return $user->hasAnyRole(['admin', 'super-admin', 'teacher']) ||
                   ($user->hasRole('parent') && $user->isParentOf($student->id)) ||
                   ($user->hasRole('student') && $user->id === $student->id);
        });
    }

    /**
     * Configure Passport for API authentication.
     *
     * @return void
     */
    protected function configurePassport()
    {
        Passport::tokensExpireIn(now()->addDays(15));
        Passport::refreshTokensExpireIn(now()->addDays(30));
        Passport::personalAccessTokensExpireIn(now()->addMonths(6));

        // Define token scopes
        Passport::tokensCan([
            'user:read' => 'Read user information',
            'user:write' => 'Modify user information',
            'student:read' => 'Read student information',
            'student:write' => 'Modify student information',
            'teacher:read' => 'Read teacher information',
            'teacher:write' => 'Modify teacher information',
            'parent:read' => 'Read parent information',
            'parent:write' => 'Modify parent information',
            'grades:read' => 'Read grades',
            'grades:write' => 'Modify grades',
            'attendance:read' => 'Read attendance records',
            'attendance:write' => 'Modify attendance records',
            'financial:read' => 'Read financial information',
            'financial:write' => 'Modify financial information',
            'admin' => 'Full administrative access',
        ]);

        // Set default scopes
        Passport::defaultScopes([
            'user:read',
        ]);
    }
}
