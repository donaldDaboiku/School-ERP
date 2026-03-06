<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/dashboard';

    /**
     * The controller namespace for the application.
     *
     * When present, controller route declarations will automatically be prefixed with this namespace.
     *
     * @var string|null
     */
    // protected $namespace = 'App\\Http\\Controllers';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::prefix('api')
                ->middleware('api')
                ->namespace($this->namespace)
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/web.php'));

            // Admin routes
            // Route::middleware(['web', 'auth', 'role:admin|super-admin'])
            //     ->prefix('admin')
            //     ->namespace('App\Http\Controllers\Admin')
            //     ->name('admin.')
            //     ->group(base_path('routes/admin.php'));

            // Teacher routes
            // Route::middleware(['web', 'auth', 'role:teacher'])
            //     ->prefix('teacher')
            //     ->namespace('App\Http\Controllers\Teacher')
            //     ->name('teacher.')
            //     ->group(base_path('routes/teacher.php'));

            // Student routes
            // Route::middleware(['web', 'auth', 'role:student'])
            //     ->prefix('student')
            //     ->namespace('App\Http\Controllers\Student')
            //     ->name('student.')
            //     ->group(base_path('routes/student.php'));

            // Parent routes
            // Route::middleware(['web', 'auth', 'role:parent'])
            //     ->prefix('parent')
            //     ->namespace('App\Http\Controllers\Parent')
            //     ->name('parent.')
            //     ->group(base_path('routes/parent.php'));

            // API v1 routes with authentication
            // Route::prefix('api/v1')
            //     ->middleware(['api', 'auth:api'])
            //     ->namespace('App\Http\Controllers\Api\V1')
            //     ->group(base_path('routes/api_v1.php'));

            // Public API routes (no authentication required)
            // Route::prefix('api/public')
            //     ->middleware('api')
            //     ->namespace('App\Http\Controllers\Api\Public')
            //     ->group(base_path('routes/api_public.php'));
        });

        // Custom route model bindings
        $this->configureRouteModelBindings();
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Rate limiting for login attempts
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Rate limiting for registration
        RateLimiter::for('register', function (Request $request) {
            return Limit::perHour(10)->by($request->ip());
        });

        // Rate limiting for password reset
        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perHour(5)->by($request->ip());
        });

        // Rate limiting for API endpoints
        RateLimiter::for('api-public', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        RateLimiter::for('api-authenticated', function (Request $request) {
            return $request->user()->hasRole('super-admin') 
                ? Limit::none()
                : Limit::perMinute(120)->by($request->user()->id);
        });

        // Rate limiting for file uploads
        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        // Rate limiting for report generation
        RateLimiter::for('reports', function (Request $request) {
            return Limit::perHour(20)->by($request->user()->id);
        });

        // Rate limiting for notifications
        RateLimiter::for('notifications', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()->id);
        });
    }

    /**
     * Configure custom route model bindings.
     *
     * @return void
     */
    protected function configureRouteModelBindings()
    {
        Route::model('student', \App\Models\Student::class);
        Route::model('teacher', \App\Models\Teacher::class);
        Route::model('parent', \App\Models\Parents::class);
        Route::model('class', \App\Models\Classes::class);
        Route::model('subject', \App\Models\Subject::class);
        Route::model('grade', \App\Models\GradingSystem::class);
        Route::model('attendance', \App\Models\Attendance::class);
        Route::model('fee', \App\Models\FeePayment::class);
        Route::model('meeting', \App\Models\ParentTeacherMeeting::class);

        // Custom binding for student with roll number
        Route::bind('student_by_roll', function ($value) {
            return \App\Models\Student::where('roll_number', $value)->firstOrFail();
        });

        // Custom binding for student with ID or roll number
        Route::bind('student', function ($value) {
            if (is_numeric($value)) {
                return \App\Models\Student::findOrFail($value);
            }
            return \App\Models\Student::where('roll_number', $value)->firstOrFail();
        });

        // Custom binding for teacher with ID or teacher_id
        Route::bind('teacher', function ($value) {
            if (is_numeric($value)) {
                return \App\Models\Teacher::findOrFail($value);
            }
            return \App\Models\Teacher::where('teacher_id', $value)->firstOrFail();
        });

        // Custom binding for parent with ID or email
        Route::bind('parent', function ($value) {
            if (is_numeric($value)) {
                return \App\Models\Parents::findOrFail($value);
            }
            return \App\Models\Parents::where('email', $value)->firstOrFail();
        });

        // Custom binding for user with ID or username
        Route::bind('user', function ($value) {
            if (is_numeric($value)) {
                return \App\Models\User::findOrFail($value);
            }
            return \App\Models\User::where('username', $value)
                ->orWhere('email', $value)
                ->firstOrFail();
        });

        // Custom binding for class with ID or code
        Route::bind('class', function ($value) {
            if (is_numeric($value)) {
                return \App\Models\Classes::findOrFail($value);
            }
            return \App\Models\Classes::where('class_code', $value)->firstOrFail();
        });
    }

    /**
     * Get the route patterns for the application.
     *
     * @return array
     */
    public static function getRoutePatterns()
    {
        return [
            'id' => '[0-9]+',
            'uuid' => '[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}',
            'slug' => '[a-z0-9-]+',
            'username' => '[a-zA-Z0-9_.-]+',
            'email' => '.+@.+\..+',
            'year' => '[0-9]{4}',
            'month' => '(0?[1-9]|1[0-2])',
            'day' => '(0?[1-9]|[12][0-9]|3[01])',
        ];
    }
}