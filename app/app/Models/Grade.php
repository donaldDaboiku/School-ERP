<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Grade extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'grades';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'student_id',
        'subject_id',
        'class_id',
        'exam_id',
        'grading_system_id',
        'score',
        'grade',
        'grade_point',
        'remarks',
        'is_published',
        'published_at',
        'published_by',
        'is_final',
        'term',
        'academic_year',
        'recorded_by',
        'recorded_at',
        'verified_by',
        'verified_at',
        'comments',
        'metadata'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'score' => 'decimal:2',
        'grade_point' => 'decimal:2',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
        'is_final' => 'boolean',
        'recorded_at' => 'datetime',
        'verified_at' => 'datetime',
        'metadata' => 'json',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    /**
     * The attributes that should be appended.
     *
     * @var array<string>
     */
    protected $appends = [
        'status',
        'is_passing',
        'score_percentage',
        'grade_display',
        'recorded_by_name',
        'verified_by_name',
        'published_by_name'
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $with = ['student', 'subject', 'class', 'exam', 'gradingSystem', 'recordedBy', 'verifiedBy', 'publishedBy'];

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($grade) {
            // Set default values
            if (empty($grade->academic_year)) {
                $grade->academic_year = self::getCurrentAcademicYear();
            }

            if (empty($grade->term)) {
                $grade->term = self::getCurrentTerm();
            }

            // Set recorded_by and recorded_at
            if (empty($grade->recorded_by) && Auth::check()) {
                $grade->recorded_by = Auth::id();
            }

            if (empty($grade->recorded_at)) {
                $grade->recorded_at = now();
            }

            // Validate required fields
            self::validateGradeCreation($grade);

            // Calculate grade and grade point if not provided
            if (!is_null($grade->score) && is_null($grade->grade)) {
                self::calculateGradeAndGradePoint($grade);
            }

            // Set is_final default if not provided
            if (is_null($grade->is_final)) {
                $grade->is_final = false;
            }

            Log::info('Grade creating', [
                'student_id' => $grade->student_id,
                'subject_id' => $grade->subject_id,
                'score' => $grade->score,
                'recorded_by' => $grade->recorded_by
            ]);
        });

        static::updating(function ($grade) {
            // Handle score changes
            if ($grade->isDirty('score')) {
                // Recalculate grade and grade point
                self::calculateGradeAndGradePoint($grade);

                // Validate new score
                self::validateScore($grade->score, $grade->grading_system_id);
            }

            // Handle publication
            if ($grade->isDirty('is_published') && $grade->is_published) {
                $grade->published_at = now();
                $grade->published_by = Auth::id();

                // Create audit log
                $grade->createAuditLog('published');
            }

            // Handle verification
            if ($grade->isDirty('verified_by') && $grade->verified_by) {
                $grade->verified_at = now();

                // Create audit log
                $grade->createAuditLog('verified');
            }

            // Prevent changes to published grades (except by admins)
            /** @var \App\Models\User|null $user */
            $user = Auth::user();
            if ($grade->is_published && (!$user || !$user->hasRole('admin'))) {
                $changedFields = array_keys($grade->getDirty());
                $allowedFields = ['remarks', 'comments', 'metadata'];

                foreach ($changedFields as $field) {
                    if (!in_array($field, $allowedFields)) {
                        throw new \Exception("Cannot modify {$field} of a published grade");
                    }
                }
            }
        });

        static::saved(function ($grade) {
            // Clear relevant cache
            Cache::forget("grade_{$grade->id}");
            Cache::tags([
                "grades_student_{$grade->student_id}",
                "grades_subject_{$grade->subject_id}",
                "grades_class_{$grade->class_id}",
                "grades_academic_year_{$grade->academic_year}",
                "grades_term_{$grade->term}"
            ])->flush();

            // Update student GPA if this is a final grade
            if ($grade->is_final) {
                $grade->updateStudentGPA();
            }

            // Create audit log
            $grade->createAuditLog('saved');
        });

        static::deleted(function ($grade) {
            // Clear cache
            Cache::forget("grade_{$grade->id}");
            Cache::tags([
                "grades_student_{$grade->student_id}",
                "grades_subject_{$grade->subject_id}",
                "grades_class_{$grade->class_id}",
                "grades_academic_year_{$grade->academic_year}",
                "grades_term_{$grade->term}"
            ])->flush();

            // Update student GPA if this was a final grade
            if ($grade->is_final) {
                $grade->updateStudentGPA();
            }

            // Create audit log for deletion
            $grade->createAuditLog('deleted');
        });

        static::restored(function ($grade) {
            // Clear cache
            Cache::tags([
                "grades_student_{$grade->student_id}",
                "grades_subject_{$grade->subject_id}",
                "grades_class_{$grade->class_id}",
                "grades_academic_year_{$grade->academic_year}",
                "grades_term_{$grade->term}"
            ])->flush();

            // Update student GPA if this is a final grade
            if ($grade->is_final) {
                $grade->updateStudentGPA();
            }

            // Create audit log for restoration
            $grade->createAuditLog('restored');
        });
    }

    /**
     * Get the student for this grade.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    /**
     * Get the subject for this grade.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    /**
     * Get the class for this grade.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function class()
    {
        return $this->belongsTo(Classes::class, 'class_id');
    }

    /**
     * Get the exam for this grade.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function exam()
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    /**
     * Get the grading system for this grade.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function gradingSystem()
    {
        return $this->belongsTo(GradingSystem::class, 'grading_system_id');
    }

    /**
     * Get the user who recorded this grade.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Get the user who verified this grade.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Get the user who published this grade.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function publishedBy()
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * Get the grade range for this grade.
     *
     * @return GradeRange|null
     */
    public function gradeRange()
    {
        if (!$this->gradingSystem || is_null($this->score)) {
            return null;
        }

        return GradeRange::getRangeForScore($this->grading_system_id, $this->score);
    }

    /**
     * Get the status of the grade.
     *
     * @return string
     */
    public function getStatusAttribute()
    {
        if ($this->is_published) {
            return 'published';
        }

        if ($this->verified_by) {
            return 'verified';
        }

        return 'draft';
    }

    /**
     * Check if the grade is passing.
     *
     * @return bool
     */
    public function getIsPassingAttribute()
    {
        if (!$this->gradeRange()) {
            return false;
        }

        return $this->gradeRange()->is_passing;
    }

    /**
     * Get the score percentage.
     *
     * @return float|null
     */
    public function getScorePercentageAttribute()
    {
        if (is_null($this->score) || !$this->gradingSystem) {
            return null;
        }

        // Get the max score from grading system or assume 100
        $maxScore = $this->gradingSystem->max_score ?? 100;

        if ($maxScore == 0) {
            return 0;
        }

        return round(($this->score / $maxScore) * 100, 2);
    }

    /**
     * Get the grade display.
     *
     * @return string
     */
    public function getGradeDisplayAttribute()
    {
        if ($this->grade) {
            return $this->grade;
        }

        if ($this->gradeRange()) {
            return $this->gradeRange()->grade;
        }

        return 'N/A';
    }

    /**
     * Get the recorded by user's name.
     *
     * @return string
     */
    public function getRecordedByNameAttribute()
    {
        return $this->recordedBy ? $this->recordedBy->name : 'System';
    }

    /**
     * Get the verified by user's name.
     *
     * @return string|null
     */
    public function getVerifiedByNameAttribute()
    {
        return $this->verifiedBy ? $this->verifiedBy->name : null;
    }

    /**
     * Get the published by user's name.
     *
     * @return string|null
     */
    public function getPublishedByNameAttribute()
    {
        return $this->publishedBy ? $this->publishedBy->name : null;
    }

    /**
     * Get the grade point for GPA calculation.
     *
     * @return float|null
     */
    public function getGradePointForGPA()
    {
        if (!is_null($this->grade_point)) {
            return $this->grade_point;
        }

        if ($this->gradeRange()) {
            return $this->gradeRange()->grade_point;
        }

        return null;
    }

    /**
     * Check if the grade can be viewed by a user.
     *
     * @param  User|null  $user
     * @return bool
     */
    public function canView($user = null)
    {
        if (!$user) {
            /** @var \App\Models\User|null $user */
            $user = Auth::user();
        }

        if (!$user) {
            return false;
        }

        // Super admin can view everything
        if ($user->hasRole('super-admin')) {
            return true;
        }

        // Admins can view everything
        if ($user->hasRole('admin')) {
            return true;
        }

        // Teachers can view grades for their students
        if ($user->hasRole('teacher')) {
            if ($this->class && $this->class->teachers->contains('id', $user->teacher->id)) {
                return true;
            }

            if ($this->subject && $this->subject->teachers->contains('id', $user->teacher->id)) {
                return true;
            }
        }

        // Students can view their own grades (if published)
        if ($user->hasRole('student') && $this->is_published) {
            return $this->student_id === $user->student->id;
        }

        // Parents can view their children's grades (if published)
        if ($user->hasRole('parent') && $this->is_published) {
            return $user->parent->students->contains('id', $this->student_id);
        }

        return false;
    }

    /**
     * Check if the grade can be edited by a user.
     *
     * @param  User|null  $user
     * @return bool
     */
    public function canEdit($user = null)
    {
        if (!$user) {
            /** @var \App\Models\User|null $user */
            $user = Auth::user();
        }

        if (!$user) {
            return false;
        }

        // Cannot edit published grades (except by admins)
        if ($this->is_published && !$user->hasRole('admin')) {
            return false;
        }

        // Super admin and admin can edit everything
        if ($user->hasAnyRole(['super-admin', 'admin'])) {
            return true;
        }

        // Teachers can edit grades for their subjects/classes
        if ($user->hasRole('teacher')) {
            if ($this->class && $this->class->teachers->contains('id', $user->teacher->id)) {
                return true;
            }

            if ($this->subject && $this->subject->teachers->contains('id', $user->teacher->id)) {
                return true;
            }
        }

        // User who recorded the grade can edit it (if not published)
        if ($this->recorded_by === $user->id && !$this->is_published) {
            return true;
        }

        return false;
    }

    /**
     * Publish the grade.
     *
     * @param  User|null  $publishedBy
     * @return bool
     */
    public function publish($publishedBy = null)
    {
        if (!$publishedBy) {
            /** @var \App\Models\User|null $publishedBy */
            $publishedBy = Auth::user();
        }

        if (!$publishedBy) {
            throw new \Exception('User must be logged in to publish grades');
        }

        if ($this->is_published) {
            throw new \Exception('Grade is already published');
        }

        // Verify grade before publishing (optional)
        if (!$this->verified_by && config('grades.require_verification_before_publishing', false)) {
            throw new \Exception('Grade must be verified before publishing');
        }

        $this->is_published = true;
        $this->published_at = now();
        $this->published_by = $publishedBy->id;
        $this->save();

        // Send notification to student/parent
        $this->notifyGradePublished($publishedBy);

        return true;
    }

    /**
     * Verify the grade.
     *
     * @param  User|null  $verifiedBy
     * @param  string|null  $comments
     * @return bool
     */
    public function verify($verifiedBy = null, $comments = null)
    {
        if (!$verifiedBy) {
            /** @var \App\Models\User|null $verifiedBy */
            $verifiedBy = Auth::user();
        }

        if (!$verifiedBy) {
            throw new \Exception('User must be logged in to verify grades');
        }

        if ($this->verified_by) {
            throw new \Exception('Grade is already verified');
        }

        $this->verified_by = $verifiedBy->id;
        $this->verified_at = now();

        if ($comments) {
            $this->comments = $comments;
        }

        $this->save();

        return true;
    }

    /**
     * Update student's GPA based on final grades.
     *
     * @return void
     */
    public function updateStudentGPA()
    {
        if (!$this->student_id || !$this->academic_year || !$this->term) {
            return;
        }

        // Get all final grades for this student in this term and academic year
        $finalGrades = self::where('student_id', $this->student_id)
            ->where('academic_year', $this->academic_year)
            ->where('term', $this->term)
            ->where('is_final', true)
            ->whereNotNull('grade_point')
            ->get();

        if ($finalGrades->isEmpty()) {
            return;
        }

        // Calculate GPA
        $totalGradePoints = 0;
        $totalCredits = 0;

        foreach ($finalGrades as $grade) {
            $gradePoint = $grade->getGradePointForGPA();
            $credits = $grade->subject ? ($grade->subject->credits ?? 1) : 1;

            if ($gradePoint !== null) {
                $totalGradePoints += $gradePoint * $credits;
                $totalCredits += $credits;
            }
        }

        if ($totalCredits > 0) {
            $gpa = $totalGradePoints / $totalCredits;

            // Update or create GPA record
            StudentGPA::updateOrCreate(
                [
                    'student_id' => $this->student_id,
                    'academic_year' => $this->academic_year,
                    'term' => $this->term
                ],
                [
                    'gpa' => round($gpa, 2),
                    'total_credits' => $totalCredits,
                    'updated_at' => now()
                ]
            );

            // Update student's cumulative GPA
            $this->updateCumulativeGPA($this->student_id);
        }
    }

    /**
     * Update student's cumulative GPA.
     *
     * @param  int  $studentId
     * @return void
     */
    private function updateCumulativeGPA($studentId)
    {
        $student = Student::find($studentId);
        if (!$student) {
            return;
        }

        // Get all GPAs for the student
        $gpas = StudentGPA::where('student_id', $studentId)
            ->orderBy('academic_year')
            ->orderBy('term')
            ->get();

        if ($gpas->isEmpty()) {
            return;
        }

        // Calculate cumulative GPA
        $totalGradePoints = 0;
        $totalCredits = 0;

        foreach ($gpas as $gpa) {
            $totalGradePoints += $gpa->gpa * $gpa->total_credits;
            $totalCredits += $gpa->total_credits;
        }

        if ($totalCredits > 0) {
            $cumulativeGPA = $totalGradePoints / $totalCredits;

            // Update student record
            $student->cumulative_gpa = round($cumulativeGPA, 2);
            $student->total_credits_earned = $totalCredits;
            $student->last_gpa_update = now();
            $student->save();
        }
    }

    /**
     * Create an audit log entry.
     *
     * @param  string  $action
     * @return GradeAuditLog
     */
    public function createAuditLog($action)
    {
        return GradeAuditLog::create([
            'grade_id' => $this->id,
            'action' => $action,
            'performed_by' => Auth::id(),
            'data' => [
                'score' => $this->score,
                'grade' => $this->grade,
                'grade_point' => $this->grade_point,
                'status' => $this->status
            ],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);
    }

    /**
     * Get grades for a student.
     *
     * @param  int  $studentId
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getForStudent($studentId, $filters = [])
    {
        $cacheKey = "grades_student_{$studentId}_" . md5(json_encode($filters));

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($studentId, $filters) {
            $query = self::where('student_id', $studentId)
                ->with(['subject', 'class', 'exam', 'gradingSystem'])
                ->orderBy('academic_year', 'desc')
                ->orderBy('term', 'desc')
                ->orderBy('subject_id');

            // Apply filters
            if (isset($filters['academic_year'])) {
                $query->where('academic_year', $filters['academic_year']);
            }

            if (isset($filters['term'])) {
                $query->where('term', $filters['term']);
            }

            if (isset($filters['subject_id'])) {
                $query->where('subject_id', $filters['subject_id']);
            }

            if (isset($filters['class_id'])) {
                $query->where('class_id', $filters['class_id']);
            }

            if (isset($filters['is_final'])) {
                $query->where('is_final', $filters['is_final']);
            }

            if (isset($filters['is_published'])) {
                $query->where('is_published', $filters['is_published']);
            }

            return $query->get();
        });
    }

    /**
     * Get grades for a class.
     *
     * @param  int  $classId
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getForClass($classId, $filters = [])
    {
        $cacheKey = "grades_class_{$classId}_" . md5(json_encode($filters));

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($classId, $filters) {
            $query = self::where('class_id', $classId)
                ->with(['student', 'subject', 'exam'])
                ->orderBy('student_id')
                ->orderBy('subject_id')
                ->orderBy('academic_year', 'desc')
                ->orderBy('term', 'desc');

            // Apply filters
            if (isset($filters['academic_year'])) {
                $query->where('academic_year', $filters['academic_year']);
            }

            if (isset($filters['term'])) {
                $query->where('term', $filters['term']);
            }

            if (isset($filters['subject_id'])) {
                $query->where('subject_id', $filters['subject_id']);
            }

            if (isset($filters['is_final'])) {
                $query->where('is_final', $filters['is_final']);
            }

            return $query->get();
        });
    }

    /**
     * Calculate grade statistics.
     *
     * @param  array  $filters
     * @return array
     */
    public static function getStatistics($filters = [])
    {
        $cacheKey = "grades_statistics_" . md5(json_encode($filters));

        return Cache::remember($cacheKey, now()->addHours(2), function () use ($filters) {
            $query = self::query();

            // Apply filters
            if (isset($filters['academic_year'])) {
                $query->where('academic_year', $filters['academic_year']);
            }

            if (isset($filters['term'])) {
                $query->where('term', $filters['term']);
            }

            if (isset($filters['class_id'])) {
                $query->where('class_id', $filters['class_id']);
            }

            if (isset($filters['subject_id'])) {
                $query->where('subject_id', $filters['subject_id']);
            }

            $totalGrades = $query->count();
            $averageScore = $query->avg('score');
            $publishedGrades = $query->where('is_published', true)->count();
            $finalGrades = $query->where('is_final', true)->count();
            $verifiedGrades = $query->whereNotNull('verified_by')->count();

            return [
                'total_grades' => $totalGrades,
                'average_score' => round($averageScore ?? 0, 2),
                'published_grades' => $publishedGrades,
                'published_percentage' => $totalGrades > 0 ? round(($publishedGrades / $totalGrades) * 100, 2) : 0,
                'final_grades' => $finalGrades,
                'verified_grades' => $verifiedGrades,
                'verification_rate' => $totalGrades > 0 ? round(($verifiedGrades / $totalGrades) * 100, 2) : 0
            ];
        });
    }

    /**
     * Validate grade creation.
     *
     * @param  Grade  $grade
     * @return void
     * @throws \Exception
     */
    private static function validateGradeCreation($grade)
    {
        // Check for duplicate grade for same student, subject, exam, term, and academic year
        if ($grade->student_id && $grade->subject_id && $grade->exam_id && $grade->term && $grade->academic_year) {
            $existingGrade = self::where('student_id', $grade->student_id)
                ->where('subject_id', $grade->subject_id)
                ->where('exam_id', $grade->exam_id)
                ->where('term', $grade->term)
                ->where('academic_year', $grade->academic_year)
                ->where('id', '!=', $grade->id)
                ->first();

            if ($existingGrade) {
                throw new \Exception('Grade already exists for this student, subject, exam, term, and academic year');
            }
        }

        // Validate score if provided
        if (!is_null($grade->score)) {
            self::validateScore($grade->score, $grade->grading_system_id);
        }
    }

    /**
     * Validate score.
     *
     * @param  float  $score
     * @param  int|null  $gradingSystemId
     * @return void
     * @throws \Exception
     */
    private static function validateScore($score, $gradingSystemId = null)
    {
        if ($score < 0) {
            throw new \Exception('Score cannot be negative');
        }

        if ($gradingSystemId) {
            $gradingSystem = GradingSystem::find($gradingSystemId);
            if ($gradingSystem && $score > $gradingSystem->max_score) {
                throw new \Exception("Score cannot exceed maximum score of {$gradingSystem->max_score}");
            }
        }
    }

    /**
     * Calculate grade and grade point.
     *
     * @param  Grade  $grade
     * @return void
     */
    private static function calculateGradeAndGradePoint($grade)
    {
        if (!$grade->grading_system_id || is_null($grade->score)) {
            return;
        }

        $gradeRange = GradeRange::getRangeForScore($grade->grading_system_id, $grade->score);

        if ($gradeRange) {
            $grade->grade = $gradeRange->grade;
            $grade->grade_point = $gradeRange->grade_point;

            // Add remarks based on grade range
            if ($gradeRange->remarks && empty($grade->remarks)) {
                $grade->remarks = $gradeRange->remarks;
            }
        }
    }

    /**
     * Get current academic year.
     *
     * @return string
     */
    public static function getCurrentAcademicYear()
    {
        $year = date('Y');
        $month = date('m');

        if ($month >= 8) {
            return $year . '-' . ($year + 1);
        } else {
            return ($year - 1) . '-' . $year;
        }
    }

    /**
     * Get current term.
     *
     * @return string
     */
    public static function getCurrentTerm()
    {
        $month = date('m');

        if ($month >= 1 && $month <= 4) {
            return 'term1';
        } elseif ($month >= 5 && $month <= 8) {
            return 'term2';
        } else {
            return 'term3';
        }
    }

    /**
     * Notify student/parent about grade publication.
     *
     * @param  User  $publishedBy
     * @return void
     */
    private function notifyGradePublished($publishedBy)
    {
        // This would typically integrate with your notification system
        Log::info('Grade publication notification should be sent', [
            'grade_id' => $this->id,
            'student_id' => $this->student_id,
            'score' => $this->score,
            'grade' => $this->grade,
            'published_by' => $publishedBy->id
        ]);
    }

    /**
     * Scope a query to only include published grades.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    /**
     * Scope a query to only include final grades.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFinal($query)
    {
        return $query->where('is_final', true);
    }

    /**
     * Scope a query to only include grades for a specific academic year.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $academicYear
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForAcademicYear($query, $academicYear)
    {
        return $query->where('academic_year', $academicYear);
    }

    /**
     * Scope a query to only include grades for a specific term.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $term
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForTerm($query, $term)
    {
        return $query->where('term', $term);
    }

    /**
     * Scope a query to only include grades for a specific student.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $studentId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForStudent($query, $studentId)
    {
        return $query->where('student_id', $studentId);
    }

    /**
     * Scope a query to only include grades for a specific subject.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $subjectId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForSubject($query, $subjectId)
    {
        return $query->where('subject_id', $subjectId);
    }

    /**
     * Scope a query to only include grades for a specific class.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $classId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForClass($query, $classId)
    {
        return $query->where('class_id', $classId);
    }

    /**
     * Scope a query to only include verified grades.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeVerified($query)
    {
        return $query->whereNotNull('verified_by');
    }

    /**
     * Scope a query to only include passing grades.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePassing($query)
    {
        return $query->whereHas('gradeRange', function ($q) {
            $q->where('status', 'pass');
        });
    }

    /**
     * Scope a query to only include failing grades.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFailing($query)
    {
        return $query->whereHas('gradeRange', function ($q) {
            $q->where('status', 'fail');
        });
    }
}
