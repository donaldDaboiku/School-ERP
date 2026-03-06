<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Carbon\Carbon;

class Exam extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'exams';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'code',
        'description',
        'exam_type',
        'subject_id',
        'class_id',
        'academic_year',
        'term',
        'total_marks',
        'passing_marks',
        'duration',
        'date',
        'start_time',
        'end_time',
        'venue',
        'room_number',
        'supervisor_id',
        'assistant_supervisor_id',
        'status',
        'is_published',
        'published_at',
        'published_by',
        'is_active',
        'weightage',
        'include_in_final_grade',
        'grading_system_id',
        'instructions',
        'created_by',
        'updated_by'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'total_marks' => 'decimal:2',
        'passing_marks' => 'decimal:2',
        'duration' => 'integer',
        'date' => 'date',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
        'is_active' => 'boolean',
        'weightage' => 'decimal:2',
        'include_in_final_grade' => 'boolean',
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
        'exam_type_display',
        'status_display',
        'is_upcoming',
        'is_ongoing',
        'is_completed',
        'is_cancelled',
        'duration_formatted',
        'full_date_time',
        'remaining_time',
        'total_students',
        'average_score',
        'pass_rate'
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $with = ['subject', 'class', 'supervisor', 'assistantSupervisor', 'gradingSystem'];

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($exam) {
            // Generate exam code if not provided
            if (empty($exam->code)) {
                $exam->code = self::generateExamCode($exam);
            }

            // Set default status
            if (empty($exam->status)) {
                $exam->status = 'scheduled';
            }

            // Set academic year if not provided
            if (empty($exam->academic_year)) {
                $exam->academic_year = self::getCurrentAcademicYear();
            }

            // Set created_by if not set
            if (empty($exam->created_by) && Auth::check()) {
                $exam->created_by = Auth::id();
            }

            // Validate exam date and time
            self::validateExamDateTime($exam);

            // Calculate end time if not provided but duration is
            if ($exam->start_time && $exam->duration && !$exam->end_time) {
                $exam->end_time = Carbon::parse($exam->start_time)->addMinutes($exam->duration);
            }

            // Set weightage default if not provided
            if (empty($exam->weightage)) {
                $exam->weightage = self::getDefaultWeightage($exam->exam_type);
            }

            // Set is_active default
            if (is_null($exam->is_active)) {
                $exam->is_active = true;
            }

            Log::info('Exam creating', [
                'name' => $exam->name,
                'code' => $exam->code,
                'date' => $exam->date,
                'created_by' => $exam->created_by
            ]);
        });

        static::updating(function ($exam) {
            // Handle status changes
            if ($exam->isDirty('status')) {
                $oldStatus = $exam->getOriginal('status');
                $newStatus = $exam->status;

                self::validateStatusTransition($oldStatus, $newStatus);

                // Set timestamps for status changes
                switch ($newStatus) {
                    case 'ongoing':
                        $exam->started_at = now();
                        break;
                    case 'completed':
                        $exam->completed_at = now();
                        $exam->completed_by = Auth::id();
                        break;
                    case 'cancelled':
                        $exam->cancelled_at = now();
                        $exam->cancelled_by = Auth::id();
                        $exam->cancellation_reason = request()->input('cancellation_reason', 'Exam cancelled');
                        break;
                    case 'postponed':
                        $exam->postponed_at = now();
                        $exam->postponed_by = Auth::id();
                        $exam->postponed_reason = request()->input('postponed_reason', 'Exam postponed');
                        break;
                }

                Log::info('Exam status changing', [
                    'exam_id' => $exam->id,
                    'from' => $oldStatus,
                    'to' => $newStatus,
                    'changed_by' => Auth::id()
                ]);
            }

            // Handle publication
            if ($exam->isDirty('is_published') && $exam->is_published) {
                $exam->published_at = now();
                $exam->published_by = Auth::id();
            }

            // Update updated_by
            if (Auth::check()) {
                $exam->updated_by = Auth::id();
            }

            // Validate exam date and time on update
            if ($exam->isDirty(['date', 'start_time', 'end_time', 'duration'])) {
                self::validateExamDateTime($exam);
            }
        });

        static::saved(function ($exam) {
            // Clear relevant cache
            Cache::forget("exam_{$exam->id}");
            Cache::forget("exam_code_{$exam->code}");
            Cache::tags([
                "exams_class_{$exam->class_id}",
                "exams_subject_{$exam->subject_id}",
                "exams_academic_year_{$exam->academic_year}",
                "exams_teacher_{$exam->supervisor_id}"
            ])->flush();

            // Create audit log
            $exam->createAuditLog('saved');
        });

        static::deleted(function ($exam) {
            // Clear cache
            Cache::forget("exam_{$exam->id}");
            Cache::forget("exam_code_{$exam->code}");
            Cache::tags([
                "exams_class_{$exam->class_id}",
                "exams_subject_{$exam->subject_id}",
                "exams_academic_year_{$exam->academic_year}",
                "exams_teacher_{$exam->supervisor_id}"
            ])->flush();

            // Create audit log for deletion
            $exam->createAuditLog('deleted');
        });

        static::restored(function ($exam) {
            // Clear cache
            Cache::tags([
                "exams_class_{$exam->class_id}",
                "exams_subject_{$exam->subject_id}",
                "exams_academic_year_{$exam->academic_year}",
                "exams_teacher_{$exam->supervisor_id}"
            ])->flush();

            // Create audit log for restoration
            $exam->createAuditLog('restored');
        });
    }

    /**
     * Get the subject for this exam.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    /**
     * Get the class for this exam.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function class()
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    /**
     * Get the main supervisor (teacher) for this exam.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function supervisor()
    {
        return $this->belongsTo(Teacher::class, 'supervisor_id');
    }

    /**
     * Get the assistant supervisor for this exam.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function assistantSupervisor()
    {
        return $this->belongsTo(Teacher::class, 'assistant_supervisor_id');
    }

    /**
     * Get the grading system for this exam.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function gradingSystem()
    {
        return $this->belongsTo(GradingSystem::class, 'grading_system_id');
    }

    /**
     * Get the user who created this exam.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this exam.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get the user who published this exam.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function publisher()
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * Get the exam results.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function results()
    {
        return $this->hasMany(ExamResult::class, 'exam_id');
    }

    /**
     * Get the exam timetable entries.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function timetableEntries()
    {
        return $this->hasMany(ExamTimetable::class, 'exam_id');
    }

    /**
     * Get the exam hall allocations.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function hallAllocations()
    {
        return $this->hasMany(ExamHallAllocation::class, 'exam_id');
    }

    /**
     * Get the exam invigilators.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function invigilators()
    {
        return $this->hasMany(ExamInvigilator::class, 'exam_id');
    }

    /**
     * Get the exam papers.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function papers()
    {
        return $this->hasMany(ExamPaper::class, 'exam_id');
    }

    /**
     * Get the exam attendances.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function attendances()
    {
        return $this->hasMany(ExamAttendance::class, 'exam_id');
    }

    /**
     * Get exam type display name.
     *
     * @return string
     */
    public function getExamTypeDisplayAttribute()
    {
        $types = [
            'midterm' => 'Midterm',
            'final' => 'Final',
            'quiz' => 'Quiz',
            'assignment' => 'Assignment',
            'project' => 'Project',
            'practical' => 'Practical',
            'oral' => 'Oral',
            'written' => 'Written',
            'test' => 'Test',
            'continuous_assessment' => 'Continuous Assessment'
        ];

        return $types[$this->exam_type] ?? ucfirst($this->exam_type);
    }

    /**
     * Get status display name.
     *
     * @return string
     */
    public function getStatusDisplayAttribute()
    {
        $statuses = [
            'scheduled' => 'Scheduled',
            'ongoing' => 'Ongoing',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'postponed' => 'Postponed',
            'draft' => 'Draft'
        ];

        return $statuses[$this->status] ?? ucfirst($this->status);
    }

    /**
     * Check if exam is upcoming.
     *
     * @return bool
     */
    public function getIsUpcomingAttribute()
    {
        return $this->status === 'scheduled' &&
            $this->date > now()->format('Y-m-d') ||
            ($this->date == now()->format('Y-m-d') &&
                $this->start_time && $this->start_time > now());
    }

    /**
     * Check if exam is ongoing.
     *
     * @return bool
     */
    public function getIsOngoingAttribute()
    {
        if ($this->status === 'ongoing') {
            return true;
        }

        $currentTime = now();
        $examDate = Carbon::parse($this->date);
        $startTime = $this->start_time ? Carbon::parse($this->start_time) : null;
        $endTime = $this->end_time ? Carbon::parse($this->end_time) : null;

        if ($examDate->isToday() && $startTime && $endTime) {
            return $currentTime->between($startTime, $endTime);
        }

        return false;
    }

    /**
     * Check if exam is completed.
     *
     * @return bool
     */
    public function getIsCompletedAttribute()
    {
        return $this->status === 'completed' ||
            ($this->end_time && Carbon::parse($this->end_time) < now());
    }

    /**
     * Check if exam is cancelled.
     *
     * @return bool
     */
    public function getIsCancelledAttribute()
    {
        return $this->status === 'cancelled';
    }

    /**
     * Get formatted duration.
     *
     * @return string
     */
    public function getDurationFormattedAttribute()
    {
        if (!$this->duration) {
            return 'N/A';
        }

        $hours = floor($this->duration / 60);
        $minutes = $this->duration % 60;

        if ($hours > 0 && $minutes > 0) {
            return "{$hours}h {$minutes}m";
        } elseif ($hours > 0) {
            return "{$hours}h";
        } else {
            return "{$minutes}m";
        }
    }

    /**
     * Get full date and time string.
     *
     * @return string
     */
    public function getFullDateTimeAttribute()
    {
        $date = Carbon::parse($this->date)->format('F j, Y');

        if ($this->start_time) {
            $startTime = Carbon::parse($this->start_time)->format('g:i A');
            $date .= " at {$startTime}";

            if ($this->end_time) {
                $endTime = Carbon::parse($this->end_time)->format('g:i A');
                $date .= " - {$endTime}";
            }
        }

        return $date;
    }

    /**
     * Get remaining time until exam starts.
     *
     * @return string|null
     */
    public function getRemainingTimeAttribute()
    {
        if (!$this->is_upcoming) {
            return null;
        }

        $examDateTime = Carbon::parse($this->date . ' ' . ($this->start_time ?: '00:00:00'));
        $diff = now()->diff($examDateTime);

        if ($diff->days > 0) {
            return $diff->days . ' day' . ($diff->days > 1 ? 's' : '');
        } elseif ($diff->h > 0) {
            return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '');
        } elseif ($diff->i > 0) {
            return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '');
        } else {
            return 'Less than a minute';
        }
    }

    /**
     * Get total students eligible for this exam.
     *
     * @return int
     */
    public function getTotalStudentsAttribute()
    {
        if (!$this->class_id) {
            return 0;
        }

        return Cache::remember("exam_{$this->id}_total_students", now()->addHours(6), function () {
            return Student::where('class_id', $this->class_id)
                ->where('status', 'active')
                ->count();
        });
    }

    /**
     * Get average score for this exam.
     *
     * @return float|null
     */
    public function getAverageScoreAttribute()
    {
        return Cache::remember("exam_{$this->id}_average_score", now()->addHours(6), function () {
            return $this->results()->avg('marks_obtained');
        });
    }

    /**
     * Get pass rate for this exam.
     *
     * @return float
     */
    public function getPassRateAttribute()
    {
        return Cache::remember("exam_{$this->id}_pass_rate", now()->addHours(6), function () {
            $totalResults = $this->results()->count();

            if ($totalResults === 0) {
                return 0;
            }

            $passedResults = $this->results()
                ->where('marks_obtained', '>=', $this->passing_marks)
                ->count();

            return round(($passedResults / $totalResults) * 100, 2);
        });
    }

    /**
     * Get exam statistics.
     *
     * @return array
     */
    public function getStatistics()
    {
        $cacheKey = "exam_{$this->id}_statistics";

        return Cache::remember($cacheKey, now()->addHours(2), function () {
            $results = $this->results()->get();
            $totalResults = $results->count();

            if ($totalResults === 0) {
                return [
                    'total_students' => 0,
                    'total_present' => 0,
                    'total_absent' => 0,
                    'average_score' => 0,
                    'highest_score' => 0,
                    'lowest_score' => 0,
                    'pass_rate' => 0,
                    'grade_distribution' => []
                ];
            }

            $presentCount = $results->where('attendance_status', 'present')->count();
            $absentCount = $results->where('attendance_status', 'absent')->count();
            $averageScore = $results->avg('marks_obtained');
            $highestScore = $results->max('marks_obtained');
            $lowestScore = $results->min('marks_obtained');

            // Calculate grade distribution if grading system exists
            $gradeDistribution = [];
            if ($this->gradingSystem) {
                foreach ($this->gradingSystem->gradeRanges as $gradeRange) {
                    $count = $results->whereBetween('marks_obtained', [
                        $gradeRange->min_score,
                        $gradeRange->max_score
                    ])->count();

                    $gradeDistribution[] = [
                        'grade' => $gradeRange->grade,
                        'count' => $count,
                        'percentage' => $totalResults > 0 ? round(($count / $totalResults) * 100, 2) : 0
                    ];
                }
            }

            return [
                'total_students' => $this->total_students,
                'total_present' => $presentCount,
                'total_absent' => $absentCount,
                'attendance_rate' => $totalResults > 0 ? round(($presentCount / $totalResults) * 100, 2) : 0,
                'average_score' => round($averageScore, 2),
                'highest_score' => round($highestScore, 2),
                'lowest_score' => round($lowestScore, 2),
                'pass_rate' => $this->pass_rate,
                'grade_distribution' => $gradeDistribution,
                'score_distribution' => $this->getScoreDistribution($results)
            ];
        });
    }

    /**
     * Get score distribution for the exam.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $results
     * @return array
     */
    private function getScoreDistribution($results)
    {
        $distribution = [
            '0-20' => 0,
            '21-40' => 0,
            '41-60' => 0,
            '61-80' => 0,
            '81-100' => 0
        ];

        foreach ($results as $result) {
            $score = $result->marks_obtained;

            if ($score <= 20) {
                $distribution['0-20']++;
            } elseif ($score <= 40) {
                $distribution['21-40']++;
            } elseif ($score <= 60) {
                $distribution['41-60']++;
            } elseif ($score <= 80) {
                $distribution['61-80']++;
            } else {
                $distribution['81-100']++;
            }
        }

        return $distribution;
    }

    /**
     * Check if a student can take this exam.
     *
     * @param  Student|int  $student
     * @return array
     */
    public function canStudentTakeExam($student)
    {
        $studentId = $student instanceof Student ? $student->id : $student;

        // Check if exam is active and published
        if (!$this->is_active || !$this->is_published) {
            return ['can_take' => false, 'reason' => 'Exam is not available'];
        }

        // Check if exam is cancelled or postponed
        if ($this->status === 'cancelled') {
            return ['can_take' => false, 'reason' => 'Exam has been cancelled'];
        }

        if ($this->status === 'postponed') {
            return ['can_take' => false, 'reason' => 'Exam has been postponed'];
        }

        // Check if exam is completed
        if ($this->status === 'completed') {
            return ['can_take' => false, 'reason' => 'Exam has already been completed'];
        }

        // Check if student is in the right class
        $student = Student::find($studentId);
        if (!$student || $student->class_id != $this->class_id) {
            return ['can_take' => false, 'reason' => 'Student not enrolled in this class'];
        }

        // Check if student status is active
        if ($student->status !== 'active') {
            return ['can_take' => false, 'reason' => 'Student is not active'];
        }

        // Check if exam time is valid
        $now = now();
        $examDate = Carbon::parse($this->date);
        $startTime = $this->start_time ? Carbon::parse($this->start_time) : null;
        $endTime = $this->end_time ? Carbon::parse($this->end_time) : null;

        if ($examDate->isToday() && $startTime && $endTime) {
            if ($now < $startTime) {
                return ['can_take' => false, 'reason' => 'Exam has not started yet'];
            }

            if ($now > $endTime) {
                return ['can_take' => false, 'reason' => 'Exam time has expired'];
            }
        }

        // Check if student has already taken the exam
        $existingResult = ExamResult::where('exam_id', $this->id)
            ->where('student_id', $studentId)
            ->first();

        if ($existingResult) {
            return ['can_take' => false, 'reason' => 'Student has already taken this exam'];
        }

        return ['can_take' => true, 'reason' => ''];
    }

    /**
     * Record exam result for a student.
     *
     * @param  Student|int  $student
     * @param  float  $marksObtained
     * @param  array  $additionalData
     * @return ExamResult
     */
    public function recordResult($student, $marksObtained, $additionalData = [])
    {
        $studentId = $student instanceof Student ? $student->id : $student;

        // Validate marks
        if ($marksObtained < 0 || $marksObtained > $this->total_marks) {
            throw new \Exception("Marks must be between 0 and {$this->total_marks}");
        }

        // Check if result already exists
        $existingResult = ExamResult::where('exam_id', $this->id)
            ->where('student_id', $studentId)
            ->first();

        if ($existingResult) {
            throw new \Exception('Result already recorded for this student');
        }

        // Calculate percentage
        $percentage = $this->total_marks > 0 ? ($marksObtained / $this->total_marks) * 100 : 0;

        // Determine grade if grading system exists
        $grade = null;
        $gradePoint = null;

        if ($this->gradingSystem) {
            $gradeRange = $this->gradingSystem->getGradeForScore($percentage);
            if ($gradeRange) {
                $grade = $gradeRange->grade;
                $gradePoint = $gradeRange->grade_point;
            }
        }

        // Create result
        $result = $this->results()->create(array_merge([
            'student_id' => $studentId,
            'marks_obtained' => $marksObtained,
            'percentage' => $percentage,
            'grade' => $grade,
            'grade_point' => $gradePoint,
            'is_pass' => $marksObtained >= $this->passing_marks,
            'recorded_by' => Auth::id(),
            'recorded_at' => now()
        ], $additionalData));

        // Clear cache
        Cache::forget("exam_{$this->id}_average_score");
        Cache::forget("exam_{$this->id}_pass_rate");
        Cache::forget("exam_{$this->id}_statistics");

        // Create audit log
        $this->createAuditLog('result_recorded', [
            'student_id' => $studentId,
            'marks_obtained' => $marksObtained,
            'result_id' => $result->id
        ]);

        // Update student's overall grade if this exam is included in final grade
        if ($this->include_in_final_grade && $gradePoint !== null) {
            $this->updateStudentOverallGrade($studentId, $gradePoint);
        }

        return $result;
    }

    /**
     * Update student's overall grade.
     *
     * @param  int  $studentId
     * @param  float  $gradePoint
     * @return void
     */
    private function updateStudentOverallGrade($studentId, $gradePoint)
    {
        // Get all exam results for this student in this subject
        $studentExams = ExamResult::whereHas('exam', function ($query) use ($studentId) {
            $query->where('subject_id', $this->subject_id)
                ->where('class_id', $this->class_id)
                ->where('include_in_final_grade', true);
        })->where('student_id', $studentId)
            ->whereNotNull('grade_point')
            ->get();

        if ($studentExams->isEmpty()) {
            return;
        }

        // Calculate weighted average
        $totalWeight = 0;
        $weightedSum = 0;

        foreach ($studentExams as $examResult) {
            $weight = $examResult->exam->weightage;
            $totalWeight += $weight;
            $weightedSum += $examResult->grade_point * $weight;
        }

        if ($totalWeight > 0) {
            $overallGradePoint = $weightedSum / $totalWeight;

            // Update or create student grade record
            Grade::updateOrCreate(
                [
                    'student_id' => $studentId,
                    'subject_id' => $this->subject_id,
                    'class_id' => $this->class_id,
                    'academic_year' => $this->academic_year,
                    'term' => $this->term
                ],
                [
                    'score' => $overallGradePoint,
                    'grade' => $this->gradingSystem ?
                        ($this->gradingSystem->getGradeForScore($overallGradePoint)?->grade) : null,
                    'grading_system_id' => $this->grading_system_id,
                    'updated_at' => now()
                ]
            );
        }
    }

    /**
     * Publish exam results.
     *
     * @param  User|null  $publishedBy
     * @return bool
     */
    public function publishResults($publishedBy = null)
    {
        if (!$publishedBy) {
            $publishedBy = Auth::user();
        }

        // Check if exam is completed
        if ($this->status !== 'completed') {
            throw new \Exception('Cannot publish results for an incomplete exam');
        }

        // Update all results to published
        $this->results()->update([
            'is_published' => true,
            'published_at' => now(),
            'published_by' => $publishedBy->id
        ]);

        // Create audit log
        $this->createAuditLog('results_published', [
            'published_by' => $publishedBy->id,
            'total_results' => $this->results()->count()
        ]);

        // Send notifications to students/parents
        $this->notifyResultsPublished($publishedBy);

        return true;
    }

    /**
     * Generate exam timetable for the class.
     *
     * @return array
     */
    public function generateTimetable()
    {
        if (!$this->class_id) {
            return [];
        }

        // Get all exams for this class in the current academic year and term
        $exams = self::where('class_id', $this->class_id)
            ->where('academic_year', $this->academic_year)
            ->where('term', $this->term)
            ->where('is_published', true)
            ->where('status', '!=', 'cancelled')
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        $timetable = [];

        foreach ($exams as $exam) {
            $timetable[] = [
                'exam' => $exam->name,
                'code' => $exam->code,
                'subject' => $exam->subject ? $exam->subject->name : 'N/A',
                'date' => $exam->date->format('Y-m-d'),
                'day' => $exam->date->format('l'),
                'time' => $exam->start_time ? $exam->start_time->format('g:i A') : 'N/A',
                'duration' => $exam->duration_formatted,
                'venue' => $exam->venue,
                'room' => $exam->room_number,
                'supervisor' => $exam->supervisor ? $exam->supervisor->user->name : 'N/A'
            ];
        }

        return $timetable;
    }

    /**
     * Create audit log entry.
     *
     * @param  string  $action
     * @param  array  $data
     * @return ExamAuditLog
     */
    public function createAuditLog($action, $data = [])
    {
        return ExamAuditLog::create([
            'exam_id' => $this->id,
            'action' => $action,
            'performed_by' => Auth::id(),
            'data' => $data,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);
    }

    /**
     * Get exam by code.
     *
     * @param  string  $code
     * @return Exam|null
     */
    public static function findByCode($code)
    {
        return Cache::remember("exam_code_{$code}", now()->addHours(12), function () use ($code) {
            return self::where('code', $code)->first();
        });
    }

    /**
     * Get exams for a class.
     *
     * @param  int  $classId
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getForClass($classId, $filters = [])
    {
        $cacheKey = "exams_class_{$classId}_" . md5(json_encode($filters));

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($classId, $filters) {
            $query = self::where('class_id', $classId)
                ->with(['subject', 'supervisor.user'])
                ->orderBy('date')
                ->orderBy('start_time');

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

            if (isset($filters['exam_type'])) {
                $query->where('exam_type', $filters['exam_type']);
            }

            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (isset($filters['is_published'])) {
                $query->where('is_published', $filters['is_published']);
            }

            if (isset($filters['upcoming']) && $filters['upcoming']) {
                $query->where('date', '>=', now()->format('Y-m-d'))
                    ->where('status', 'scheduled');
            }

            return $query->get();
        });
    }

    /**
     * Get exams for a teacher.
     *
     * @param  int  $teacherId
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getForTeacher($teacherId, $filters = [])
    {
        $cacheKey = "exams_teacher_{$teacherId}_" . md5(json_encode($filters));

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($teacherId, $filters) {
            $query = self::where(function ($q) use ($teacherId) {
                $q->where('supervisor_id', $teacherId)
                    ->orWhere('assistant_supervisor_id', $teacherId);
            })
                ->with(['subject', 'class', 'supervisor.user'])
                ->orderBy('date')
                ->orderBy('start_time');

            // Apply filters
            if (isset($filters['academic_year'])) {
                $query->where('academic_year', $filters['academic_year']);
            }

            if (isset($filters['term'])) {
                $query->where('term', $filters['term']);
            }

            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            return $query->get();
        });
    }

    /**
     * Generate exam code.
     *
     * @param  Exam  $exam
     * @return string
     */
    private static function generateExamCode($exam)
    {
        $prefix = strtoupper(substr($exam->exam_type, 0, 3));
        $year = date('y');
        $month = date('m');

        do {
            $random = strtoupper(Str::random(6));
            $code = "{$prefix}{$year}{$month}{$random}";
        } while (self::where('code', $code)->exists());

        return $code;
    }

    /**
     * Validate exam date and time.
     *
     * @param  Exam  $exam
     * @return void
     * @throws \Exception
     */
    private static function validateExamDateTime($exam)
    {
        // Check if date is in the past
        if ($exam->date && $exam->date < now()->format('Y-m-d')) {
            throw new \Exception('Exam date cannot be in the past');
        }

        // Check if start time is before end time
        if (
            $exam->start_time && $exam->end_time &&
            $exam->start_time >= $exam->end_time
        ) {
            throw new \Exception('Start time must be before end time');
        }

        // Check for scheduling conflicts for the same class
        if ($exam->class_id && $exam->date && $exam->start_time && $exam->end_time) {
            $conflictingExam = self::where('class_id', $exam->class_id)
                ->where('id', '!=', $exam->id)
                ->where('date', $exam->date)
                ->where('status', '!=', 'cancelled')
                ->where(function ($q) use ($exam) {
                    $q->whereBetween('start_time', [$exam->start_time, $exam->end_time])
                        ->orWhereBetween('end_time', [$exam->start_time, $exam->end_time])
                        ->orWhere(function ($q2) use ($exam) {
                            $q2->where('start_time', '<', $exam->start_time)
                                ->where('end_time', '>', $exam->end_time);
                        });
                })
                ->first();

            if ($conflictingExam) {
                throw new \Exception("Schedule conflicts with exam: {$conflictingExam->name} ({$conflictingExam->code})");
            }
        }
    }

    /**
     * Validate status transition.
     *
     * @param  string  $oldStatus
     * @param  string  $newStatus
     * @return void
     * @throws \Exception
     */
    private static function validateStatusTransition($oldStatus, $newStatus)
    {
        $validTransitions = [
            'draft' => ['scheduled', 'cancelled'],
            'scheduled' => ['ongoing', 'cancelled', 'postponed'],
            'ongoing' => ['completed', 'cancelled'],
            'completed' => ['cancelled'],
            'cancelled' => ['scheduled'],
            'postponed' => ['scheduled', 'cancelled']
        ];

        if (!in_array($newStatus, $validTransitions[$oldStatus] ?? [])) {
            throw new \Exception("Invalid status transition from {$oldStatus} to {$newStatus}");
        }
    }

    /**
     * Get default weightage for exam type.
     *
     * @param  string  $examType
     * @return float
     */
    private static function getDefaultWeightage($examType)
    {
        $weightages = [
            'final' => 40.00,
            'midterm' => 20.00,
            'quiz' => 10.00,
            'assignment' => 15.00,
            'project' => 15.00,
            'practical' => 20.00,
            'oral' => 10.00,
            'written' => 30.00,
            'test' => 15.00,
            'continuous_assessment' => 20.00
        ];

        return $weightages[$examType] ?? 10.00;
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

        // Academic year typically runs from August to July
        if ($month >= 8) {
            return $year . '-' . ($year + 1);
        } else {
            return ($year - 1) . '-' . $year;
        }
    }

    /**
     * Get exam type options.
     *
     * @return array
     */
    public static function getExamTypeOptions()
    {
        return [
            'midterm' => 'Midterm',
            'final' => 'Final',
            'quiz' => 'Quiz',
            'assignment' => 'Assignment',
            'project' => 'Project',
            'practical' => 'Practical',
            'oral' => 'Oral',
            'written' => 'Written',
            'test' => 'Test',
            'continuous_assessment' => 'Continuous Assessment'
        ];
    }

    /**
     * Get status options.
     *
     * @return array
     */
    public static function getStatusOptions()
    {
        return [
            'draft' => 'Draft',
            'scheduled' => 'Scheduled',
            'ongoing' => 'Ongoing',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'postponed' => 'Postponed'
        ];
    }

    /**
     * Get term options.
     *
     * @return array
     */
    public static function getTermOptions()
    {
        return [
            'term1' => 'Term 1',
            'term2' => 'Term 2',
            'term3' => 'Term 3',
            'term4' => 'Term 4'
        ];
    }

    /**
     * Scope a query to only include active exams.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include published exams.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    /**
     * Scope a query to only include upcoming exams.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUpcoming($query)
    {
        return $query->where('status', 'scheduled')
            ->where('date', '>=', now()->format('Y-m-d'));
    }

    /**
     * Scope a query to only include ongoing exams.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOngoing($query)
    {
        return $query->where('status', 'ongoing');
    }

    /**
     * Scope a query to only include completed exams.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope a query to only include exams for a specific academic year.
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
     * Scope a query to only include exams for a specific term.
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
     * Scope a query to only include exams of a specific type.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $type
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('exam_type', $type);
    }

    /**
     * Scope a query to only include exams for a specific subject.
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
     * Scope a query to only include exams for a specific class.
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
     * Scope a query to only include exams supervised by a specific teacher.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $teacherId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSupervisedBy($query, $teacherId)
    {
        return $query->where('supervisor_id', $teacherId)
            ->orWhere('assistant_supervisor_id', $teacherId);
    }

    /**
     * Send notifications for published results.
     *
     * @param  User  $publishedBy
     * @return void
     */
    private function notifyResultsPublished($publishedBy)
    {
        // This would typically integrate with your notification system
        // For now, we'll just log it
        Log::info('Exam results published notifications should be sent', [
            'exam_id' => $this->id,
            'exam_name' => $this->name,
            'published_by' => $publishedBy->id,
            'total_students' => $this->total_students
        ]);
    }
}
