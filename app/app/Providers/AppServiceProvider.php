<?php

namespace App\Providers;

use App\Models\User;
use App\Observers\UserObserver;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Pagination\Paginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;



class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */


    public function register(): void
    {
        // Register custom services
        $this->registerServices();

        // Register repositories if using repository pattern
        $this->registerRepositories();

        // Register macros
        $this->registerMacros();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        User::observe(UserObserver::class);
        // Database configuration
        $this->configureDatabase();

        // Force HTTPS in production
        $this->forceHttps();

        // Configure pagination
        $this->configurePagination();

        // Register custom validators
        $this->registerValidators();

        // Register Blade components and directives
        $this->registerBladeDirectives();

        // Register custom gates for authorization
        $this->registerGates();

        // Configure model settings
        $this->configureModels();

        // Set default string length for migrations
        Schema::defaultStringLength(191);
    }

    /**
     * Register custom application services.
     */
    protected function registerServices(): void
    {
        $this->app->singleton(\App\Services\SchoolService::class, function ($app) {
            return new \App\Services\SchoolService();
        });

        $this->app->singleton(\App\Services\AcademicService::class, function ($app) {
            return new \App\Services\AcademicService();
        });

        $this->app->singleton(\App\Services\StudentService::class, function ($app) {
            return new \App\Services\StudentService();
        });

        $this->app->singleton(\App\Services\TeacherService::class, function ($app) {
            return new \App\Services\TeacherService();
        });

        $this->app->singleton(\App\Services\PaymentService::class, function ($app) {
            return new \App\Services\PaymentService();
        });

        $this->app->singleton(\App\Services\ReportService::class, function ($app) {
            return new \App\Services\ReportService();
        });

        $this->app->singleton(\App\Services\NotificationService::class, function ($app) {
            return new \App\Services\NotificationService();
        });

        $this->app->singleton(\App\Services\ExportService::class, function ($app) {
            return new \App\Services\ExportService();
        });

        $this->app->singleton(\App\Services\ImportService::class, function ($app) {
            return new \App\Services\ImportService();
        });
    }

    /**
     * Register repositories (if using repository pattern).
     */
    protected function registerRepositories(): void
    {
        // Example of repository binding
        // $this->app->bind(
        //     \App\Repositories\StudentRepositoryInterface::class,
        //     \App\Repositories\StudentRepository::class
        // );
    }

    /**
     * Register custom macros.
     */
    protected function registerMacros(): void
    {
        // Paginate a standard Laravel Collection
        if (!Collection::hasMacro('paginate')) {
            Collection::macro('paginate', function ($perPage = 15, $page = null, $options = []) {
                $page = $page ?: (Paginator::resolveCurrentPage() ?: 1);
                $items = $this->forPage($page, $perPage)->values();

                return new LengthAwarePaginator(
                    $items,
                    $this->count(),
                    $perPage,
                    $page,
                    $options
                );
            });
        }

        // Format Nigerian phone numbers
        Collection::macro('formatNigerianPhone', function () {
            return $this->map(function ($phone) {
                $phone = preg_replace('/[^0-9]/', '', (string) $phone);

                if (strlen($phone) === 11 && str_starts_with($phone, '0')) {
                    return '234' . substr($phone, 1);
                }

                if (strlen($phone) === 10) {
                    return '234' . $phone;
                }

                return $phone;
            });
        });
    }

    /**
     * Configure database settings.
     */
    protected function configureDatabase(): void
    {
        // Set default string length for MySQL
        Schema::defaultStringLength(191);

        // Configure database query logging in development
        if ($this->app->environment('local', 'development')) {
            DB::listen(function ($query) {
                Log::channel('database')->debug($query->sql, [
                    'bindings' => $query->bindings,
                    'time' => $query->time . 'ms',
                ]);
            });
        }
    }

    /**
     * Force HTTPS in production.
     */
    protected function forceHttps(): void
    {
        if ($this->app->environment('production', 'staging')) {
            URL::forceScheme('https');
            URL::forceRootUrl(config('app.url'));
        }
    }

    /**
     * Configure pagination.
     */
    protected function configurePagination(): void
    {
        Paginator::useBootstrapFive();

        // Customize pagination views
        Paginator::defaultView('vendor.pagination.bootstrap-5');
        Paginator::defaultSimpleView('vendor.pagination.simple-bootstrap-5');
    }

    /**
     * Register custom validators.
     */
    protected function registerValidators(): void
    {
        // Nigerian phone number validator
        Validator::extend('nigerian_phone', function ($attribute, $value, $parameters, $validator) {
            $phone = preg_replace('/[^0-9]/', '', $value);

            // Check if it's a valid Nigerian phone number
            return preg_match('/^(0|234)(7|8|9)(0|1)\d{8}$/', $phone);
        }, 'The :attribute must be a valid Nigerian phone number.');

        // Student admission number validator
        Validator::extend('admission_number', function ($attribute, $value, $parameters, $validator) {
            return preg_match('/^[A-Z]{3}\d{4}\/[A-Z]{2}\d{2}$/', $value);
        }, 'The :attribute must be a valid admission number format (e.g., STU2024/AB01).');

        // Nigerian bank account validator
        Validator::extend('nigerian_bank_account', function ($attribute, $value, $parameters, $validator) {
            return preg_match('/^\d{10}$/', $value);
        }, 'The :attribute must be a valid 10-digit Nigerian bank account number.');

        // Age validator (minimum age)
        Validator::extend('min_age', function ($attribute, $value, $parameters, $validator) {
            $minAge = $parameters[0] ?? 18;
            $birthDate = new \DateTime($value);
            $today = new \DateTime();
            $age = $today->diff($birthDate)->y;

            return $age >= $minAge;
        }, 'The :attribute must be at least :min_age years old.');

        // File size with custom message
        Validator::extend('max_file_size', function ($attribute, $value, $parameters, $validator) {
            $maxSize = $parameters[0] ?? 2048; // Default 2MB in KB
            return $value->getSize() <= ($maxSize * 1024);
        }, 'The :attribute must not be larger than :max_file_size KB.');
    }

    /**
     * Register custom Blade directives.
     */
    protected function registerBladeDirectives(): void
    {
        // Format date
        Blade::directive('date', function ($expression) {
            return "<?php echo ($expression)->format('d/m/Y'); ?>";
        });

        // Format date with time
        Blade::directive('datetime', function ($expression) {
            return "<?php echo ($expression)->format('d/m/Y h:i A'); ?>";
        });

        // Format currency (Naira)
        Blade::directive('currency', function ($expression) {
            return "<?php echo '₦' . number_format($expression, 2); ?>";
        });

        // Check if user has role
        Blade::directive('role', function ($expression) {
            return "<?php if(auth()->check() && auth()->user()->hasRole({$expression})): ?>";
        });

        Blade::directive('endrole', function () {
            return "<?php endif; ?>";
        });

        // Check if user has permission
        Blade::directive('permission', function ($expression) {
            return "<?php if(auth()->check() && auth()->user()->can({$expression})): ?>";
        });

        Blade::directive('endpermission', function () {
            return "<?php endif; ?>";
        });

        // Check user type
        Blade::directive('usertype', function ($expression) {
            return "<?php if(auth()->check() && auth()->user()->user_type === {$expression}): ?>";
        });

        Blade::directive('endusertype', function () {
            return "<?php endif; ?>";
        });

        // Active menu item
        Blade::directive('active', function ($expression) {
            return "<?php echo request()->routeIs({$expression}) ? 'active' : ''; ?>";
        });

        // School year formatting
        Blade::directive('schoolyear', function ($expression) {
            return "<?php echo str_replace('/', '-', {$expression}); ?>";
        });
    }

    /**
     * Register custom authorization gates.
     */
    protected function registerGates(): void
    {
        // School-based gates
        Gate::define('manage-school', function ($user, $school) {
            return $user->school_id === $school->id &&
                in_array($user->user_type, ['super_admin', 'admin', 'principal']);
        });

        Gate::define('view-school', function ($user, $school) {
            return $user->school_id === $school->id;
        });

        // Student gates
        Gate::define('manage-students', function ($user) {
            return in_array($user->user_type, ['super_admin', 'admin', 'principal', 'teacher']);
        });

        Gate::define('view-student', function ($user, $student) {
            // Teachers can view students in their classes
            if ($user->user_type === 'teacher') {
                return $user->classes()->whereHas('students', function ($q) use ($student) {
                    $q->where('id', $student->id);
                })->exists();
            }

            // Parents can view their children
            if ($user->user_type === 'parent') {
                return $user->children()->where('student_id', $student->id)->exists();
            }

            // Students can view themselves
            if ($user->user_type === 'student') {
                return $user->student && $user->student->id === $student->id;
            }

            return in_array($user->user_type, ['super_admin', 'admin', 'principal']);
        });

        // Teacher gates
        Gate::define('manage-teachers', function ($user) {
            return in_array($user->user_type, ['super_admin', 'admin', 'principal']);
        });

        Gate::define('view-teacher', function ($user, $teacher) {
            return $user->school_id === $teacher->school_id;
        });

        // Academic gates
        Gate::define('manage-academic', function ($user) {
            return in_array($user->user_type, ['super_admin', 'admin', 'principal', 'teacher']);
        });

        Gate::define('enter-results', function ($user, $class, $subject) {
            if ($user->user_type !== 'teacher') {
                return false;
            }

            // Check if teacher teaches this subject in this class
            return $user->teacher &&
                $user->teacher->subjects->contains('id', $subject->id) &&
                $user->teacher->classes->contains('id', $class->id);
        });

        // Financial gates
        Gate::define('manage-financial', function ($user) {
            return in_array($user->user_type, ['super_admin', 'admin', 'accountant']);
        });

        Gate::define('view-financial', function ($user) {
            return in_array($user->user_type, ['super_admin', 'admin', 'accountant', 'principal']);
        });

        // Report gates
        Gate::define('generate-reports', function ($user) {
            return in_array($user->user_type, ['super_admin', 'admin', 'principal', 'teacher']);
        });

        // Settings gates
        Gate::define('manage-settings', function ($user) {
            return in_array($user->user_type, ['super_admin', 'admin']);
        });
    }

    /**
     * Configure Eloquent model settings.
     */
    protected function configureModels(): void
    {
        // Enable strict mode in development
        if ($this->app->environment('local', 'development')) {
            Model::shouldBeStrict();
        }

        // Prevent lazy loading in production
        Model::preventLazyLoading(!$this->app->environment('production'));

        // Global query scopes
        Model::addGlobalScope('school', function ($builder) {
            if (Auth::check() && !in_array(Auth::user()->user_type, ['super_admin'])) {
                $builder->where('school_id', Auth::user()->school_id);
            }
        });

        // Register model events
        $this->registerModelEvents();
    }

    /**
     * Register model events.
     */
    protected function registerModelEvents(): void
    {
        // Log user creation
        User::creating(function ($user) {
            if (!$user->username) {
                $user->username = $this->generateUsername($user);
            }
        });

        // Log student creation
        \App\Models\Student::creating(function ($student) {
            if (!$student->admission_number) {
                $student->admission_number = $this->generateAdmissionNumber($student);
            }
        });

        // Log teacher creation
        \App\Models\Teacher::creating(function ($teacher) {
            if (!$teacher->teacher_id) {
                $teacher->teacher_id = $this->generateTeacherId($teacher);
            }
        });

        // Log payment creation
        \App\Models\Payment::creating(function ($payment) {
            if (!$payment->invoice_number) {
                $payment->invoice_number = $this->generateInvoiceNumber($payment);
            }
        });
    }

    /**
     * Generate a unique username.
     */
    private function generateUsername($user): string
    {
        $base = strtolower(preg_replace('/[^a-z]/i', '', $user->name));
        $username = $base . rand(100, 999);

        while (User::where('username', $username)->exists()) {
            $username = $base . rand(100, 999);
        }

        return $username;
    }

    /**
     * Generate admission number.
     */
    private function generateAdmissionNumber($student): string
    {
        $schoolCode = $student->school->code ?? 'SCH';
        $year = date('Y');
        $sequence = \App\Models\Student::where('school_id', $student->school_id)
            ->whereYear('created_at', $year)
            ->count() + 1;

        return $schoolCode . str_pad($sequence, 4, '0', STR_PAD_LEFT) . '/' . substr($year, -2);
    }

    /**
     * Generate teacher ID.
     */
    private function generateTeacherId($teacher): string
    {
        $schoolCode = $teacher->school->code ?? 'SCH';
        $year = date('y');
        $sequence = \App\Models\Teacher::where('school_id', $teacher->school_id)
            ->whereYear('created_at', date('Y'))
            ->count() + 1;

        return 'TCH' . $schoolCode . $year . str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Generate invoice number.
     */
    private function generateInvoiceNumber($payment): string
    {
        $schoolCode = $payment->school->code ?? 'SCH';
        $date = date('ymd');
        $sequence = \App\Models\Payment::where('school_id', $payment->school_id)
            ->whereDate('created_at', today())
            ->count() + 1;

        return 'INV' . $schoolCode . $date . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }
}
