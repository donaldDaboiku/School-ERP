<?php

namespace App\Services;

use App\Models\School;
use App\Models\User;
use App\Models\AcademicSession;
use App\Models\TermSemester;
use App\Models\ClassLevel;
use App\Models\Classes;
use App\Models\Subject;
use App\Models\SchoolSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class SchoolService
{
    /**
     * Create a new school with default settings
     */
    public function createSchool(array $data): School
    {
        return DB::transaction(function () use ($data) {
            // Create school
            $school = School::create([
                'name' => $data['name'],
                'code' => $this->generateSchoolCode($data['name']),
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'state' => $data['state'] ?? null,
                'country' => $data['country'] ?? 'Nigeria',
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'website' => $data['website'] ?? null,
                'status' => $data['status'] ?? 'active',
                'establishment_date' => $data['establishment_date'] ?? now(),
                'registration_number' => $data['registration_number'] ?? null,
                'principal_name' => $data['principal_name'] ?? null,
            ]);

            // Create default admin user
            $this->createDefaultAdmin($school, $data);

            // Create default settings
            $this->createDefaultSettings($school);

            // Create default academic structures
            $this->createDefaultAcademicStructures($school);

            // Create default class levels
            $this->createDefaultClassLevels($school);

            // Create default subjects
            $this->createDefaultSubjects($school);

            return $school;
        });
    }

    /**
     * Update school information
     */
    public function updateSchool(School $school, array $data): School
    {
        return DB::transaction(function () use ($school, $data) {
            $school->update($data);
            
            // Update school code if name changed
            if (isset($data['name']) && $data['name'] !== $school->getOriginal('name')) {
                $school->update([
                    'code' => $this->generateSchoolCode($data['name'], $school->id)
                ]);
            }

            return $school->fresh();
        });
    }

    /**
     * Delete school (soft delete)
     */
    public function deleteSchool(School $school): bool
    {
        return DB::transaction(function () use ($school) {
            // Soft delete related records
            $school->users()->delete();
            $school->students()->delete();
            $school->teachers()->delete();
            $school->classes()->delete();
            $school->subjects()->delete();
            $school->academicSessions()->delete();
            
            return $school->delete();
        });
    }

    /**
     * Get school statistics
     */
    public function getSchoolStatistics(School $school): array
    {
        return [
            'students' => [
                'total' => $school->students()->count(),
                'active' => $school->students()->whereHas('user', function ($q) {
                    $q->where('status', 'active');
                })->count(),
                'male' => $school->students()->whereHas('user', function ($q) {
                    $q->where('gender', 'male');
                })->count(),
                'female' => $school->students()->whereHas('user', function ($q) {
                    $q->where('gender', 'female');
                })->count(),
                'new_this_month' => $school->students()
                    ->whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year)
                    ->count(),
            ],
            'teachers' => [
                'total' => $school->teachers()->count(),
                'active' => $school->teachers()->whereHas('user', function ($q) {
                    $q->where('status', 'active');
                })->count(),
                'class_teachers' => $school->teachers()->where('is_class_teacher', true)->count(),
            ],
            'academic' => [
                'classes' => $school->classes()->count(),
                'subjects' => $school->subjects()->count(),
                'current_session' => $school->currentAcademicSession?->name ?? 'Not Set',
                'current_term' => $school->currentAcademicSession?->currentTerm?->name ?? 'Not Set',
            ],
            'financial' => [
                'total_fees' => $school->payments()->sum('amount'),
                'paid_fees' => $school->payments()->where('status', 'paid')->sum('amount_paid'),
                'pending_fees' => $school->payments()->where('status', 'pending')->sum('amount'),
                'overdue_fees' => $school->payments()
                    ->where('status', 'pending')
                    ->where('due_date', '<', now())
                    ->sum('amount'),
            ],
            'attendance' => [
                'today_present' => $this->getTodayAttendance($school, 'present'),
                'today_absent' => $this->getTodayAttendance($school, 'absent'),
                'attendance_rate' => $this->calculateAttendanceRate($school),
            ],
        ];
    }

    /**
     * Get school dashboard data
     */
    public function getDashboardData(School $school): array
    {
        $currentTerm = $school->currentAcademicSession?->currentTerm;
        
        return [
            'quick_stats' => [
                'total_students' => $school->students()->count(),
                'total_teachers' => $school->teachers()->count(),
                'total_classes' => $school->classes()->count(),
                'unpaid_fees' => $school->payments()->where('status', 'pending')->count(),
            ],
            'recent_activities' => $this->getRecentActivities($school),
            'upcoming_events' => $this->getUpcomingEvents($school),
            'attendance_today' => $this->getTodayAttendanceSummary($school),
            'fee_collection' => $this->getFeeCollectionData($school),
            'class_performance' => $this->getClassPerformance($school, $currentTerm?->id),
        ];
    }

    /**
     * Generate school report
     */
    public function generateReport(School $school, string $type, array $parameters = []): array
    {
        return match($type) {
            'student_list' => $this->generateStudentListReport($school, $parameters),
            'fee_report' => $this->generateFeeReport($school, $parameters),
            'attendance_report' => $this->generateAttendanceReport($school, $parameters),
            'academic_report' => $this->generateAcademicReport($school, $parameters),
            'teacher_report' => $this->generateTeacherReport($school, $parameters),
            default => throw new \InvalidArgumentException("Invalid report type: {$type}"),
        };
    }

    /**
     * Import data into school
     */
    public function importData(School $school, string $type, $file): array
    {
        return DB::transaction(function () use ($school, $type, $file) {
            $importer = $this->getImporter($type);
            return $importer->import($school, $file);
        });
    }

    /**
     * Export school data
     */
    public function exportData(School $school, string $type, array $parameters = []): string
    {
        $exporter = $this->getExporter($type);
        return $exporter->export($school, $parameters);
    }

    /**
     * Update school settings
     */
    public function updateSettings(School $school, array $settings): void
    {
        DB::transaction(function () use ($school, $settings) {
            foreach ($settings as $key => $value) {
                $school->settings()->updateOrCreate(
                    ['setting_key' => $key],
                    [
                        'setting_value' => $value,
                        'data_type' => $this->determineDataType($value),
                        'category' => $this->determineCategory($key),
                    ]
                );
            }
        });
    }

    /**
     * Get school setting value
     */
    public function getSetting(School $school, string $key, $default = null)
    {
        $setting = $school->settings()->where('setting_key', $key)->first();
        
        if (!$setting) {
            return $default;
        }

        return $this->castSettingValue($setting->setting_value, $setting->data_type);
    }

    /**
     * Create academic year for school
     */
    public function createAcademicYear(School $school, array $data): AcademicSession
    {
        return DB::transaction(function () use ($school, $data) {
            // If setting this as current, deactivate others
            if ($data['is_current'] ?? false) {
                $school->academicSessions()->update(['is_current' => false]);
            }

            $academicSession = $school->academicSessions()->create([
                'name' => $data['name'],
                'code' => 'AS' . str_replace('/', '', $data['name']),
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'is_current' => $data['is_current'] ?? false,
                'status' => $data['status'] ?? 'upcoming',
                'description' => $data['description'] ?? null,
            ]);

            // Create terms for this academic session
            $this->createTermsForSession($academicSession, $data['terms'] ?? []);

            return $academicSession;
        });
    }

    /**
     * Promote students to next class level
     */
    public function promoteStudents(School $school, array $parameters): array
    {
        return DB::transaction(function () use ($school, $parameters) {
            $results = [
                'successful' => 0,
                'failed' => 0,
                'details' => [],
            ];

            $fromClassId = $parameters['from_class_id'];
            $toClassLevelId = $parameters['to_class_level_id'];
            $academicSessionId = $parameters['academic_session_id'];
            
            $students = $school->students()
                ->where('class_id', $fromClassId)
                ->whereHas('user', function ($q) {
                    $q->where('status', 'active');
                })
                ->get();

            foreach ($students as $student) {
                try {
                    // Check if student passed promotion criteria
                    if (!$this->checkPromotionCriteria($student, $parameters)) {
                        $results['failed']++;
                        $results['details'][] = [
                            'student' => $student->full_name,
                            'status' => 'failed',
                            'reason' => 'Did not meet promotion criteria',
                        ];
                        continue;
                    }

                    // Create new class or find existing
                    $toClass = $this->getOrCreatePromotionClass(
                        $school, 
                        $toClassLevelId, 
                        $academicSessionId,
                        $parameters
                    );

                    // Update student class
                    $oldClassId = $student->class_id;
                    $student->update(['class_id' => $toClass->id]);

                    // Record promotion history
                    $student->promotionHistory()->create([
                        'from_class_id' => $oldClassId,
                        'to_class_id' => $toClass->id,
                        'promoted_by' => Auth::check() ? Auth::id() : null,
                        'promotion_date' => now(),
                        'academic_session_id' => $academicSessionId,
                        'remarks' => $parameters['remarks'] ?? null,
                    ]);

                    // Update class student counts
                    $this->updateClassStudentCount($oldClassId);
                    $this->updateClassStudentCount($toClass->id);

                    $results['successful']++;
                    $results['details'][] = [
                        'student' => $student->full_name,
                        'status' => 'promoted',
                        'from_class' => Classes::find($oldClassId)->name ?? 'Unknown',
                        'to_class' => $toClass->name,
                    ];

                } catch (\Exception $e) {
                    $results['failed']++;
                    $results['details'][] = [
                        'student' => $student->full_name,
                        'status' => 'error',
                        'reason' => $e->getMessage(),
                    ];
                }
            }

            return $results;
        });
    }

    /**
     * Generate school code from name
     */
    private function generateSchoolCode(string $name, ?int $schoolId = null): string
    {
        $words = explode(' ', strtoupper($name));
        $code = '';
        
        foreach ($words as $word) {
            if (!empty($word)) {
                $code .= substr($word, 0, 1);
            }
        }
        
        $code = preg_replace('/[^A-Z]/', '', $code);
        
        if (strlen($code) < 2) {
            $code = substr(strtoupper($name), 0, 3);
        }
        
        $code = substr($code, 0, 3);
        
        // Add sequence number if code already exists
        if ($schoolId) {
            $existing = School::where('code', 'like', $code . '%')
                ->where('id', '!=', $schoolId)
                ->count();
        } else {
            $existing = School::where('code', 'like', $code . '%')->count();
        }
        
        if ($existing > 0) {
            $code .= str_pad($existing + 1, 2, '0', STR_PAD_LEFT);
        }
        
        return $code;
    }

    /**
     * Create default admin user for school
     */
    private function createDefaultAdmin(School $school, array $data): User
    {
        $adminEmail = $data['admin_email'] ?? 'admin@' . Str::slug($school->name) . '.com';
        $adminPassword = $data['admin_password'] ?? 'Password123!';
        
        return User::create([
            'name' => $data['admin_name'] ?? $school->name . ' Admin',
            'email' => $adminEmail,
            'password' => Hash::make($adminPassword),
            'school_id' => $school->id,
            'user_type' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Create default school settings
     */
    private function createDefaultSettings(School $school): void
    {
        $defaultSettings = [
            // Academic Settings
            ['key' => 'academic_year', 'value' => date('Y') . '/' . (date('Y') + 1), 'type' => 'string', 'category' => 'academic'],
            ['key' => 'current_term', 'value' => 'First Term', 'type' => 'string', 'category' => 'academic'],
            ['key' => 'grading_system', 'value' => 'percentage', 'type' => 'string', 'category' => 'academic'],
            ['key' => 'attendance_threshold', 'value' => '75', 'type' => 'integer', 'category' => 'academic'],
            ['key' => 'promotion_threshold', 'value' => '50', 'type' => 'integer', 'category' => 'academic'],
            
            // Financial Settings
            ['key' => 'currency', 'value' => 'NGN', 'type' => 'string', 'category' => 'financial'],
            ['key' => 'fee_payment_deadline', 'value' => '30', 'type' => 'integer', 'category' => 'financial'],
            ['key' => 'late_fee_percentage', 'value' => '5', 'type' => 'decimal', 'category' => 'financial'],
            
            // System Settings
            ['key' => 'date_format', 'value' => 'd/m/Y', 'type' => 'string', 'category' => 'system'],
            ['key' => 'time_format', 'value' => 'h:i A', 'type' => 'string', 'category' => 'system'],
            ['key' => 'timezone', 'value' => 'Africa/Lagos', 'type' => 'string', 'category' => 'system'],
            
            // Communication Settings
            ['key' => 'sms_enabled', 'value' => 'false', 'type' => 'boolean', 'category' => 'communication'],
            ['key' => 'email_enabled', 'value' => 'true', 'type' => 'boolean', 'category' => 'communication'],
            ['key' => 'parent_portal', 'value' => 'true', 'type' => 'boolean', 'category' => 'communication'],
            
            // Security Settings
            ['key' => 'login_attempts', 'value' => '5', 'type' => 'integer', 'category' => 'security'],
            ['key' => 'password_expiry', 'value' => '90', 'type' => 'integer', 'category' => 'security'],
        ];

        foreach ($defaultSettings as $setting) {
            $school->settings()->create([
                'setting_key' => $setting['key'],
                'setting_value' => $setting['value'],
                'data_type' => $setting['type'],
                'category' => $setting['category'],
                'is_public' => in_array($setting['category'], ['system', 'communication']) ? true : false,
            ]);
        }
    }

    /**
     * Create default academic structures
     */
    private function createDefaultAcademicStructures(School $school): void
    {
        $currentYear = date('Y');
        $nextYear = $currentYear + 1;
        
        // Create academic session
        $academicSession = $school->academicSessions()->create([
            'name' => "{$currentYear}/{$nextYear}",
            'code' => "AS{$currentYear}{$nextYear}",
            'start_date' => "{$currentYear}-09-01",
            'end_date' => "{$nextYear}-07-31",
            'is_current' => true,
            'status' => 'active',
            'description' => 'Default academic session',
        ]);

        // Create terms
        $terms = [
            ['name' => 'First Term', 'order' => 1, 'start' => "{$currentYear}-09-01", 'end' => "{$currentYear}-12-15"],
            ['name' => 'Second Term', 'order' => 2, 'start' => "{$nextYear}-01-08", 'end' => "{$nextYear}-04-05"],
            ['name' => 'Third Term', 'order' => 3, 'start' => "{$nextYear}-04-23", 'end' => "{$nextYear}-07-31"],
        ];

        foreach ($terms as $term) {
            $academicSession->terms()->create([
                'name' => $term['name'],
                'code' => 'TERM' . $term['order'],
                'order' => $term['order'],
                'start_date' => $term['start'],
                'end_date' => $term['end'],
                'is_current' => $term['order'] === 1,
                'status' => $term['order'] === 1 ? 'active' : 'upcoming',
                'term_fee' => null,
            ]);
        }
    }

    /**
     * Create default class levels
     */
    private function createDefaultClassLevels(School $school): void
    {
        $classLevels = [
            // Nursery
            ['name' => 'Nursery 1', 'code' => 'NUR1', 'category' => 'nursery', 'order' => 1],
            ['name' => 'Nursery 2', 'code' => 'NUR2', 'category' => 'nursery', 'order' => 2],
            ['name' => 'Nursery 3', 'code' => 'NUR3', 'category' => 'nursery', 'order' => 3],
            
            // Primary
            ['name' => 'Primary 1', 'code' => 'PRI1', 'category' => 'primary', 'order' => 4],
            ['name' => 'Primary 2', 'code' => 'PRI2', 'category' => 'primary', 'order' => 5],
            ['name' => 'Primary 3', 'code' => 'PRI3', 'category' => 'primary', 'order' => 6],
            ['name' => 'Primary 4', 'code' => 'PRI4', 'category' => 'primary', 'order' => 7],
            ['name' => 'Primary 5', 'code' => 'PRI5', 'category' => 'primary', 'order' => 8],
            ['name' => 'Primary 6', 'code' => 'PRI6', 'category' => 'primary', 'order' => 9],
            
            // Junior Secondary
            ['name' => 'JSS 1', 'code' => 'JSS1', 'category' => 'junior', 'order' => 10],
            ['name' => 'JSS 2', 'code' => 'JSS2', 'category' => 'junior', 'order' => 11],
            ['name' => 'JSS 3', 'code' => 'JSS3', 'category' => 'junior', 'order' => 12],
            
            // Senior Secondary
            ['name' => 'SSS 1', 'code' => 'SSS1', 'category' => 'senior', 'order' => 13],
            ['name' => 'SSS 2', 'code' => 'SSS2', 'category' => 'senior', 'order' => 14],
            ['name' => 'SSS 3', 'code' => 'SSS3', 'category' => 'senior', 'order' => 15],
        ];

        foreach ($classLevels as $level) {
            $school->classLevels()->create([
                'name' => $level['name'],
                'code' => $level['code'],
                'short_name' => $level['code'],
                'level_order' => $level['order'],
                'category' => $level['category'],
                'fee_amount' => $this->calculateDefaultFee($level['category'], $level['order']),
                'description' => "Default {$level['name']} class level",
            ]);
        }
    }

    /**
     * Create default subjects
     */
    private function createDefaultSubjects(School $school): void
    {
        $subjects = [
            // Core Subjects
            ['name' => 'English Language', 'code' => 'ENG', 'type' => 'core', 'order' => 1],
            ['name' => 'Mathematics', 'code' => 'MATH', 'type' => 'core', 'order' => 2],
            ['name' => 'Basic Science', 'code' => 'BSC', 'type' => 'core', 'order' => 3],
            ['name' => 'Social Studies', 'code' => 'SST', 'type' => 'core', 'order' => 4],
            ['name' => 'Computer Studies', 'code' => 'COMP', 'type' => 'core', 'order' => 5],
            
            // Languages
            ['name' => 'French', 'code' => 'FRN', 'type' => 'elective', 'order' => 6],
            ['name' => 'Arabic', 'code' => 'ARB', 'type' => 'elective', 'order' => 7],
            
            // Arts
            ['name' => 'Fine Arts', 'code' => 'ART', 'type' => 'elective', 'order' => 8],
            ['name' => 'Music', 'code' => 'MUS', 'type' => 'elective', 'order' => 9],
            
            // Sciences (for secondary)
            ['name' => 'Physics', 'code' => 'PHY', 'type' => 'core', 'order' => 10],
            ['name' => 'Chemistry', 'code' => 'CHEM', 'type' => 'core', 'order' => 11],
            ['name' => 'Biology', 'code' => 'BIO', 'type' => 'core', 'order' => 12],
            
            // Humanities
            ['name' => 'Geography', 'code' => 'GEO', 'type' => 'elective', 'order' => 13],
            ['name' => 'History', 'code' => 'HIST', 'type' => 'elective', 'order' => 14],
            ['name' => 'Government', 'code' => 'GOVT', 'type' => 'elective', 'order' => 15],
            
            // Commercial
            ['name' => 'Economics', 'code' => 'ECON', 'type' => 'elective', 'order' => 16],
            ['name' => 'Commerce', 'code' => 'COMM', 'type' => 'elective', 'order' => 17],
            ['name' => 'Accounting', 'code' => 'ACC', 'type' => 'elective', 'order' => 18],
            
            // Technical
            ['name' => 'Technical Drawing', 'code' => 'TD', 'type' => 'elective', 'order' => 19],
            ['name' => 'Agricultural Science', 'code' => 'AGRIC', 'type' => 'elective', 'order' => 20],
        ];

        foreach ($subjects as $subject) {
            $school->subjects()->create([
                'name' => $subject['name'],
                'code' => $subject['code'],
                'short_name' => $subject['code'],
                'type' => $subject['type'],
                'position' => $subject['order'],
                'has_practical' => in_array($subject['code'], ['PHY', 'CHEM', 'BIO', 'AGRIC']),
                'max_score' => 100.00,
                'pass_score' => 40.00,
                'description' => "{$subject['name']} subject",
            ]);
        }
    }

    /**
     * Calculate default fee based on class level
     */
    private function calculateDefaultFee(string $category, int $order): float
    {
        $baseFees = [
            'nursery' => 50000,
            'primary' => 75000,
            'junior' => 100000,
            'senior' => 120000,
        ];

        $baseFee = $baseFees[$category] ?? 50000;
        
        // Increase fee slightly for higher levels
        $increment = ($order % 3) * 5000;
        
        return $baseFee + $increment;
    }

    /**
     * Get today's attendance
     */
    private function getTodayAttendance(School $school, string $status): int
    {
        return $school->attendances()
            ->whereDate('attendance_date', today())
            ->where('status', $status)
            ->count();
    }

    /**
     * Calculate attendance rate
     */
    private function calculateAttendanceRate(School $school): float
    {
        $total = $school->attendances()
            ->whereDate('attendance_date', today())
            ->count();
        
        $present = $school->attendances()
            ->whereDate('attendance_date', today())
            ->whereIn('status', ['present', 'late'])
            ->count();
        
        return $total > 0 ? ($present / $total) * 100 : 0;
    }

    /**
     * Get recent activities
     */
    private function getRecentActivities(School $school): array
    {
        $activities = [];
        
        // Recent student admissions
        $recentStudents = $school->students()
            ->with('user')
            ->latest()
            ->limit(5)
            ->get()
            ->map(function ($student) {
                return [
                    'type' => 'student_admission',
                    'title' => 'New Student Admission',
                    'description' => "{$student->full_name} was admitted",
                    'time' => $student->created_at->diffForHumans(),
                    'icon' => 'user-plus',
                ];
            });
        
        // Recent payments
        $recentPayments = $school->payments()
            ->with('student.user')
            ->latest()
            ->limit(5)
            ->get()
            ->map(function ($payment) {
                return [
                    'type' => 'payment_received',
                    'title' => 'Fee Payment Received',
                    'description' => "₦" . number_format($payment->amount_paid, 2) . " from {$payment->student->full_name}",
                    'time' => $payment->created_at->diffForHumans(),
                    'icon' => 'credit-card',
                ];
            });
        
        return $recentStudents->merge($recentPayments)->take(10)->toArray();
    }

    /**
     * Get upcoming events
     */
    private function getUpcomingEvents(School $school): array
    {
        return $school->notices()
            ->where('type', 'event')
            ->where('is_published', true)
            ->where('expires_at', '>', now())
            ->orderBy('expires_at')
            ->limit(5)
            ->get()
            ->map(function ($notice) {
                return [
                    'title' => $notice->title,
                    'date' => $notice->expires_at->format('M d'),
                    'description' => $notice->short_content,
                    'priority' => $notice->priority,
                ];
            })
            ->toArray();
    }

    /**
     * Get today's attendance summary
     */
    private function getTodayAttendanceSummary(School $school): array
    {
        $today = today();
        
        $attendance = $school->attendances()
            ->whereDate('attendance_date', $today)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');
        
        return [
            'present' => $attendance['present'] ?? 0,
            'absent' => $attendance['absent'] ?? 0,
            'late' => $attendance['late'] ?? 0,
            'excused' => $attendance['excused'] ?? 0,
            'total' => array_sum($attendance->toArray()),
        ];
    }

    /**
     * Get fee collection data
     */
    private function getFeeCollectionData(School $school): array
    {
        $currentMonth = now()->month;
        $currentYear = now()->year;
        
        $monthlyData = [];
        
        for ($i = 0; $i < 6; $i++) {
            $date = now()->subMonths($i);
            $month = $date->month;
            $year = $date->year;
            
            $total = $school->payments()
                ->whereMonth('payment_date', $month)
                ->whereYear('payment_date', $year)
                ->where('status', 'paid')
                ->sum('amount_paid');
            
            $monthlyData[] = [
                'month' => $date->format('M Y'),
                'amount' => $total,
            ];
        }
        
        return array_reverse($monthlyData);
    }

    /**
     * Get class performance data
     */
    private function getClassPerformance(School $school, ?int $termId): array
    {
        if (!$termId) {
            return [];
        }
        
        return $school->classes()
            ->withCount(['students', 'results as average_score' => function ($query) use ($termId) {
                $query->select(DB::raw('COALESCE(AVG(percentage), 0)'))
                    ->where('term_id', $termId)
                    ->where('is_finalized', true);
            }])
            ->orderBy('average_score', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($class) {
                return [
                    'class' => $class->name,
                    'students' => $class->students_count,
                    'average' => round($class->average_score, 2),
                    'performance' => $this->getPerformanceLevel($class->average_score),
                ];
            })
            ->toArray();
    }

    /**
     * Get performance level based on average
     */
    private function getPerformanceLevel(float $average): string
    {
        if ($average >= 70) return 'Excellent';
        if ($average >= 60) return 'Good';
        if ($average >= 50) return 'Average';
        if ($average >= 40) return 'Below Average';
        return 'Poor';
    }

    /**
     * Generate student list report
     */
    private function generateStudentListReport(School $school, array $parameters): array
    {
        $query = $school->students()->with(['user', 'class']);
        
        if (isset($parameters['class_id'])) {
            $query->where('class_id', $parameters['class_id']);
        }
        
        if (isset($parameters['status'])) {
            $query->whereHas('user', function ($q) use ($parameters) {
                $q->where('status', $parameters['status']);
            });
        }
        
        $students = $query->get();
        
        return [
            'report_type' => 'student_list',
            'generated_at' => now(),
            'filters' => $parameters,
            'total_students' => $students->count(),
            'students' => $students->map(function ($student) {
                return [
                    'admission_number' => $student->admission_number,
                    'name' => $student->full_name,
                    'class' => $student->class->name ?? 'Not Assigned',
                    'gender' => $student->gender,
                    'age' => $student->age,
                    'phone' => $student->phone,
                    'status' => $student->is_active ? 'Active' : 'Inactive',
                ];
            }),
        ];
    }

    /**
     * Generate fee report
     */
    private function generateFeeReport(School $school, array $parameters): array
    {
        $query = $school->payments()->with('student.user');
        
        if (isset($parameters['status'])) {
            $query->where('status', $parameters['status']);
        }
        
        if (isset($parameters['start_date'])) {
            $query->where('created_at', '>=', $parameters['start_date']);
        }
        
        if (isset($parameters['end_date'])) {
            $query->where('created_at', '<=', $parameters['end_date']);
        }
        
        $payments = $query->get();
        
        return [
            'report_type' => 'fee_report',
            'generated_at' => now(),
            'filters' => $parameters,
            'total_amount' => $payments->sum('amount'),
            'total_paid' => $payments->where('status', 'paid')->sum('amount_paid'),
            'total_pending' => $payments->where('status', 'pending')->sum('amount'),
            'payments' => $payments->map(function ($payment) {
                return [
                    'invoice_number' => $payment->invoice_number,
                    'student' => $payment->student->full_name,
                    'amount' => $payment->formatted_amount,
                    'amount_paid' => $payment->formatted_amount_paid,
                    'balance' => $payment->formatted_balance,
                    'status' => ucfirst($payment->status),
                    'due_date' => $payment->due_date->format('d/m/Y'),
                    'payment_date' => $payment->payment_date?->format('d/m/Y'),
                ];
            }),
        ];
    }

    /**
     * Generate attendance report
     */
    private function generateAttendanceReport(School $school, array $parameters): array
    {
        $query = $school->attendances()->with(['student.user', 'class']);
        
        if (isset($parameters['class_id'])) {
            $query->where('class_id', $parameters['class_id']);
        }
        
        if (isset($parameters['start_date'])) {
            $query->where('attendance_date', '>=', $parameters['start_date']);
        }
        
        if (isset($parameters['end_date'])) {
            $query->where('attendance_date', '<=', $parameters['end_date']);
        }
        
        if (isset($parameters['status'])) {
            $query->where('status', $parameters['status']);
        }
        
        $attendances = $query->get();
        
        $summary = [
            'present' => $attendances->where('status', 'present')->count(),
            'absent' => $attendances->where('status', 'absent')->count(),
            'late' => $attendances->where('status', 'late')->count(),
            'excused' => $attendances->where('status', 'excused')->count(),
        ];
        
        return [
            'report_type' => 'attendance_report',
            'generated_at' => now(),
            'filters' => $parameters,
            'summary' => $summary,
            'total_records' => $attendances->count(),
            'attendance_rate' => $attendances->count() > 0 
                ? round((($summary['present'] + $summary['late']) / $attendances->count()) * 100, 2)
                : 0,
            'attendances' => $attendances->map(function ($attendance) {
                return [
                    'student' => $attendance->student->full_name ?? 'Unknown',
                    'class' => $attendance->class->name ?? 'Not Assigned',
                    'date' => $attendance->attendance_date->format('d/m/Y'),
                    'status' => ucfirst($attendance->status),
                    'remarks' => $attendance->remarks,
                ];
            }),
        ];
    }

    /**
     * Generate academic report
     */
    private function generateAcademicReport(School $school, array $parameters): array
    {
        $query = $school->results()->with(['student.user', 'subject', 'class']);
        
        if (isset($parameters['class_id'])) {
            $query->where('class_id', $parameters['class_id']);
        }
        
        if (isset($parameters['term_id'])) {
            $query->where('term_id', $parameters['term_id']);
        }
        
        if (isset($parameters['session_id'])) {
            $query->where('academic_session_id', $parameters['session_id']);
        }
        
        $results = $query->get();
        
        $summary = [
            'total_results' => $results->count(),
            'average_score' => $results->avg('percentage'),
            'highest_score' => $results->max('percentage'),
            'lowest_score' => $results->min('percentage'),
            'passed' => $results->where('status', 'passed')->count(),
            'failed' => $results->where('status', 'failed')->count(),
        ];
        
        return [
            'report_type' => 'academic_report',
            'generated_at' => now(),
            'filters' => $parameters,
            'summary' => $summary,
            'results' => $results->map(function ($result) {
                return [
                    'student' => $result->student->full_name ?? 'Unknown',
                    'class' => $result->class->name ?? 'Not Assigned',
                    'subject' => $result->subject->name ?? 'Unknown',
                    'score' => $result->score,
                    'percentage' => $result->percentage,
                    'grade' => $result->grade,
                    'status' => ucfirst($result->status),
                    'remarks' => $result->remarks,
                ];
            }),
        ];
    }

    /**
     * Generate teacher report
     */
    private function generateTeacherReport(School $school, array $parameters): array
    {
        $query = $school->teachers()->with('user');
        
        if (isset($parameters['department'])) {
            $query->where('department', $parameters['department']);
        }
        
        if (isset($parameters['status'])) {
            $query->whereHas('user', function ($q) use ($parameters) {
                $q->where('status', $parameters['status']);
            });
        }
        
        $teachers = $query->get();
        
        return [
            'report_type' => 'teacher_report',
            'generated_at' => now(),
            'filters' => $parameters,
            'total_teachers' => $teachers->count(),
            'class_teachers' => $teachers->where('is_class_teacher', true)->count(),
            'teachers' => $teachers->map(function ($teacher) {
                return [
                    'name' => $teacher->user->name,
                    'email' => $teacher->user->email,
                    'phone' => $teacher->user->phone,
                    'qualification' => $teacher->qualification,
                    'department' => $teacher->department,
                    'is_class_teacher' => $teacher->is_class_teacher ? 'Yes' : 'No',
                    'classes_assigned' => $teacher->classes()->count(),
                    'subjects_assigned' => $teacher->subjects()->count(),
                    'status' => $teacher->user->status,
                    'employment_date' => $teacher->employment_date?->format('d/m/Y'),
                ];
            }),
        ];
    }

    /**
     * Determine data type for setting value
     */
    private function determineDataType($value): string
    {
        if (is_bool($value) || in_array(strtolower($value), ['true', 'false'])) {
            return 'boolean';
        }
        
        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? 'decimal' : 'integer';
        }
        
        if (is_array($value) || is_object($value)) {
            return 'json';
        }
        
        return 'string';
    }

    /**
     * Determine category for setting key
     */
    private function determineCategory(string $key): string
    {
        $categories = [
            'academic' => ['academic_year', 'current_term', 'grading_system', 'attendance_threshold'],
            'financial' => ['currency', 'fee_payment_deadline', 'late_fee_percentage'],
            'communication' => ['sms_enabled', 'email_enabled', 'parent_portal'],
            'security' => ['login_attempts', 'password_expiry'],
            'system' => ['date_format', 'time_format', 'timezone'],
        ];
        
        foreach ($categories as $category => $keys) {
            if (in_array($key, $keys)) {
                return $category;
            }
        }
        
        return 'general';
    }

    /**
     * Cast setting value based on data type
     */
    private function castSettingValue($value, string $dataType)
    {
        return match($dataType) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $value,
            'decimal' => (float) $value,
            'json' => is_string($value) ? json_decode($value, true) : $value,
            default => (string) $value,
        };
    }

    /**
     * Get importer instance based on type
     */
    private function getImporter(string $type)
    {
        $importers = [
            'students' => 'StudentsImporter',
            'teachers' => 'TeachersImporter',
            'subjects' => 'SubjectsImporter',
            'classes' => 'ClassesImporter',
        ];

        $importerType = $importers[$type] ?? null;

        if (!$importerType) {
            throw new \InvalidArgumentException("Invalid importer type: {$type}");
        }

        $importerClass = "App\\Imports\\{$importerType}";
        
        if (!class_exists($importerClass)) {
            throw new \InvalidArgumentException("Importer class not found: {$importerClass}");
        }

        return new $importerClass();
    }

    /**
     * Get exporter instance based on type
     */
    private function getExporter(string $type)
    {
        $exporters = [
            'students' => 'StudentsExport',
            'teachers' => 'TeachersExport',
            'subjects' => 'SubjectsExport',
            'classes' => 'ClassesExport',
            'results' => 'ResultsExport',
        ];

        $exporterType = $exporters[$type] ?? null;

        if (!$exporterType) {
            throw new \InvalidArgumentException("Invalid exporter type: {$type}");
        }

        $exporterClass = "App\\Exports\\{$exporterType}";

        if (!class_exists($exporterClass)) {
            throw new \InvalidArgumentException("Exporter class not found: {$exporterClass}");
        }

        return new $exporterClass();
    }

    /**
     * Create terms for academic session
     */
    private function createTermsForSession(AcademicSession $academicSession, array $terms): void
    {
        if (empty($terms)) {
            return;
        }

        foreach ($terms as $term) {
            $academicSession->terms()->create([
                'name' => $term['name'],
                'code' => $term['code'] ?? 'TERM' . ($term['order'] ?? 1),
                'order' => $term['order'] ?? 1,
                'start_date' => $term['start_date'],
                'end_date' => $term['end_date'],
                'is_current' => $term['is_current'] ?? false,
                'status' => $term['status'] ?? 'upcoming',
                'term_fee' => $term['term_fee'] ?? null,
            ]);
        }
    }

    /**
     * Check if student meets promotion criteria
     */
    private function checkPromotionCriteria($student, array $parameters): bool
    {
        $promotionThreshold = $this->getSetting(
            $student->school,
            'promotion_threshold',
            50
        );

        $academicSessionId = $parameters['academic_session_id'];
        $termId = $parameters['term_id'] ?? null;

        // Get student's average score for the term
        $averageScore = $student->results()
            ->where('academic_session_id', $academicSessionId)
            ->when($termId, function ($query) use ($termId) {
                return $query->where('term_id', $termId);
            })
            ->avg('percentage');

        // Check if student's average meets the promotion threshold
        return $averageScore !== null && $averageScore >= $promotionThreshold;
    }

    /**
     * Get or create promotion class
     */
    private function getOrCreatePromotionClass(
        School $school,
        int $toClassLevelId,
        int $academicSessionId,
        array $parameters
    ): Classes
    {
        $classLevelId = $toClassLevelId;
        
        // Try to find an existing class for this level in the new session
        $existingClass = $school->classes()
            ->where('class_level_id', $classLevelId)
            ->where('academic_session_id', $academicSessionId)
            ->first();

        if ($existingClass) {
            return $existingClass;
        }

        // Create a new class if it doesn't exist
        $className = $school->classLevels()->find($classLevelId)?->name ?? 'Class';
        
        return $school->classes()->create([
            'name' => $className,
            'class_level_id' => $classLevelId,
            'academic_session_id' => $academicSessionId,
            'class_teacher_id' => $parameters['class_teacher_id'] ?? null,
            'max_students' => $parameters['max_students'] ?? 50,
        ]);
    }

    /**
     * Update class student count
     */
    private function updateClassStudentCount(int $classId): void
    {
        $class = Classes::find($classId);
        
        if ($class) {
            $studentCount = $class->students()->count();
            $class->update(['total_students' => $studentCount]);
        }
    }
}
