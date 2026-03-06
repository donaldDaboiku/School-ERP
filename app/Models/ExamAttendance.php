<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;

class ExamAttendance extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'exam_attendances';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'exam_id',
        'student_id',
        'hall_allocation_id',
        'seat_allocation_id',
        'attendance_status',
        'check_in_time',
        'check_out_time',
        'late_minutes',
        'early_departure_minutes',
        'remarks',
        'verified_by',
        'verified_at',
        'recorded_by',
        'recorded_at',
        'is_excused',
        'excuse_reason',
        'excuse_approved_by',
        'excuse_approved_at'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'check_in_time' => 'datetime',
        'check_out_time' => 'datetime',
        'late_minutes' => 'integer',
        'early_departure_minutes' => 'integer',
        'verified_at' => 'datetime',
        'recorded_at' => 'datetime',
        'is_excused' => 'boolean',
        'excuse_approved_at' => 'datetime'
    ];

    /**
     * The attributes that should be appended.
     *
     * @var array<string>
     */
    protected $appends = [
        'attendance_status_display',
        'is_present',
        'is_absent',
        'is_late',
        'is_early_departure',
        'duration_minutes',
        'is_verified'
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $with = ['exam', 'student', 'hallAllocation', 'seatAllocation', 'verifiedBy', 'recordedBy', 'excuseApprover'];

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($attendance) {
            // Set default attendance status if not provided
            if (empty($attendance->attendance_status)) {
                $attendance->attendance_status = 'absent';
            }

            // Set recorded_by and recorded_at
            if (empty($attendance->recorded_by) && Auth::check()) {
                $attendance->recorded_by = Auth::id();
            }

            if (empty($attendance->recorded_at)) {
                $attendance->recorded_at = now();
            }

            // Validate attendance
            self::validateAttendance($attendance);

            Log::info('Exam attendance creating', [
                'exam_id' => $attendance->exam_id,
                'student_id' => $attendance->student_id,
                'status' => $attendance->attendance_status
            ]);
        });

        static::updating(function ($attendance) {
            // Handle status changes
            if ($attendance->isDirty('attendance_status')) {
                self::handleStatusChange($attendance);
            }

            // Handle check-in
            if ($attendance->isDirty('check_in_time') && $attendance->check_in_time) {
                self::handleCheckIn($attendance);
            }

            // Handle check-out
            if ($attendance->isDirty('check_out_time') && $attendance->check_out_time) {
                self::handleCheckOut($attendance);
            }

            // Handle excuse approval
            if ($attendance->isDirty('is_excused') && $attendance->is_excused) {
                $attendance->excuse_approved_at = now();
                $attendance->excuse_approved_by = Auth::id();
            }
        });

        static::saved(function ($attendance) {
            // Clear relevant cache
            Cache::forget("exam_attendance_{$attendance->id}");
            Cache::tags([
                "exam_attendances_exam_{$attendance->exam_id}",
                "exam_attendances_student_{$attendance->student_id}",
                "exam_attendance_stats_exam_{$attendance->exam_id}"
            ])->flush();

            // Update exam attendance statistics
            self::updateExamAttendanceStats($attendance->exam_id);
        });

        static::deleted(function ($attendance) {
            // Clear cache
            Cache::forget("exam_attendance_{$attendance->id}");
            Cache::tags([
                "exam_attendances_exam_{$attendance->exam_id}",
                "exam_attendances_student_{$attendance->student_id}",
                "exam_attendance_stats_exam_{$attendance->exam_id}"
            ])->flush();

            // Update exam attendance statistics
            self::updateExamAttendanceStats($attendance->exam_id);
        });
    }

    /**
     * Get the exam for this attendance.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function exam()
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    /**
     * Get the student for this attendance.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    /**
     * Get the hall allocation for this attendance.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function hallAllocation()
    {
        return $this->belongsTo(ExamHallAllocation::class, 'hall_allocation_id');
    }

    /**
     * Get the seat allocation for this attendance.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function seatAllocation()
    {
        return $this->belongsTo(ExamSeatAllocation::class, 'seat_allocation_id');
    }

    /**
     * Get the user who verified this attendance.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Get the user who recorded this attendance.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Get the user who approved the excuse.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function excuseApprover()
    {
        return $this->belongsTo(User::class, 'excuse_approved_by');
    }

    /**
     * Get attendance status display name.
     *
     * @return string
     */
    public function getAttendanceStatusDisplayAttribute()
    {
        $statuses = [
            'present' => 'Present',
            'absent' => 'Absent',
            'late' => 'Late',
            'excused' => 'Excused',
            'early_departure' => 'Early Departure'
        ];

        return $statuses[$this->attendance_status] ?? ucfirst($this->attendance_status);
    }

    /**
     * Check if student is present.
     *
     * @return bool
     */
    public function getIsPresentAttribute()
    {
        return in_array($this->attendance_status, ['present', 'late', 'early_departure']);
    }

    /**
     * Check if student is absent.
     *
     * @return bool
     */
    public function getIsAbsentAttribute()
    {
        return $this->attendance_status === 'absent' && !$this->is_excused;
    }

    /**
     * Check if student is late.
     *
     * @return bool
     */
    public function getIsLateAttribute()
    {
        return $this->attendance_status === 'late' || $this->late_minutes > 0;
    }

    /**
     * Check if student left early.
     *
     * @return bool
     */
    public function getIsEarlyDepartureAttribute()
    {
        return $this->attendance_status === 'early_departure' || $this->early_departure_minutes > 0;
    }

    /**
     * Get duration in minutes.
     *
     * @return int|null
     */
    public function getDurationMinutesAttribute()
    {
        if (!$this->check_in_time || !$this->check_out_time) {
            return null;
        }

        return $this->check_in_time->diffInMinutes($this->check_out_time);
    }

    /**
     * Check if attendance is verified.
     *
     * @return bool
     */
    public function getIsVerifiedAttribute()
    {
        return !is_null($this->verified_by);
    }

    /**
     * Mark student as present.
     *
     * @param  \DateTime|null  $checkInTime
     * @param  string|null  $remarks
     * @return bool
     */
    public function markPresent($checkInTime = null, $remarks = null)
    {
        if ($this->attendance_status === 'present') {
            throw new \Exception('Student is already marked present');
        }

        $this->attendance_status = 'present';
        $this->check_in_time = $checkInTime ? Carbon::instance($checkInTime) : now();

        if ($remarks) {
            $this->remarks = $remarks;
        }

        // Check if student is late
        if ($this->exam && $this->exam->start_time) {
            $lateMinutes = $this->check_in_time->diffInMinutes($this->exam->start_time);
            if ($lateMinutes > 15) { // More than 15 minutes late
                $this->attendance_status = 'late';
                $this->late_minutes = $lateMinutes;
            }
        }

        $this->save();

        return true;
    }

    /**
     * Mark student as absent.
     *
     * @param  string|null  $remarks
     * @return bool
     */
    public function markAbsent($remarks = null)
    {
        if ($this->attendance_status === 'absent') {
            throw new \Exception('Student is already marked absent');
        }

        $this->attendance_status = 'absent';
        $this->check_in_time = null;
        $this->check_out_time = null;
        $this->late_minutes = 0;
        $this->early_departure_minutes = 0;

        if ($remarks) {
            $this->remarks = $remarks;
        }

        $this->save();

        return true;
    }

    /**
     * Mark student check-out.
     *
     * @param  \DateTime|null  $checkOutTime
     * @return bool
     */
    public function markCheckOut($checkOutTime = null)
    {
        if (!$this->check_in_time) {
            throw new \Exception('Student has not checked in');
        }

        if ($this->check_out_time) {
            throw new \Exception('Student has already checked out');
        }

        $this->check_out_time = $checkOutTime ? Carbon::instance($checkOutTime) : now();

        // Check if student left early
        if ($this->exam && $this->exam->end_time) {
            $earlyDepartureMinutes = $this->exam->end_time->diffInMinutes($this->check_out_time);
            if ($earlyDepartureMinutes > 30 && $this->attendance_status === 'present') {
                $this->attendance_status = 'early_departure';
                $this->early_departure_minutes = $earlyDepartureMinutes;
            }
        }

        $this->save();

        return true;
    }

    /**
     * Excuse student's absence.
     *
     * @param  string  $reason
     * @param  User|null  $approvedBy
     * @return bool
     */
    public function excuseAbsence($reason, $approvedBy = null)
    {
        if ($this->attendance_status !== 'absent') {
            throw new \Exception('Only absent students can be excused');
        }

        if ($this->is_excused) {
            throw new \Exception('Absence is already excused');
        }

        if (!$approvedBy) {
            $approvedBy = Auth::user();
        }

        $this->is_excused = true;
        $this->excuse_reason = $reason;
        $this->excuse_approved_by = $approvedBy->id;
        $this->excuse_approved_at = now();

        $this->save();

        return true;
    }

    /**
     * Verify attendance.
     *
     * @param  User|null  $verifiedBy
     * @return bool
     */
    public function verify($verifiedBy = null)
    {
        if (!$verifiedBy) {
            $verifiedBy = Auth::user();
        }

        if ($this->verified_by) {
            throw new \Exception('Attendance is already verified');
        }

        $this->verified_by = $verifiedBy->id;
        $this->verified_at = now();
        $this->save();

        return true;
    }

    /**
     * Validate attendance.
     *
     * @param  ExamAttendance  $attendance
     * @return void
     * @throws \Exception
     */
    private static function validateAttendance($attendance)
    {
        // Check if student is enrolled in the exam class
        if ($attendance->exam && $attendance->student) {
            if ($attendance->exam->class_id !== $attendance->student->class_id) {
                throw new \Exception('Student is not enrolled in the exam class');
            }
        }

        // Check for duplicate attendance record
        $existingAttendance = self::where('exam_id', $attendance->exam_id)
            ->where('student_id', $attendance->student_id)
            ->where('id', '!=', $attendance->id)
            ->first();

        if ($existingAttendance) {
            throw new \Exception('Attendance record already exists for this student');
        }
    }

    /**
     * Handle status change.
     *
     * @param  ExamAttendance  $attendance
     * @return void
     */
    private static function handleStatusChange($attendance)
    {
        $oldStatus = $attendance->getOriginal('attendance_status');
        $newStatus = $attendance->attendance_status;

        // Reset check-in/check-out times if status changes from present
        if (
            in_array($oldStatus, ['present', 'late', 'early_departure']) &&
            in_array($newStatus, ['absent', 'excused'])
        ) {
            $attendance->check_in_time = null;
            $attendance->check_out_time = null;
            $attendance->late_minutes = 0;
            $attendance->early_departure_minutes = 0;
        }
    }

    /**
     * Handle check-in.
     *
     * @param  ExamAttendance  $attendance
     * @return void
     */
    private static function handleCheckIn($attendance)
    {
        // Calculate late minutes
        if ($attendance->exam && $attendance->exam->start_time) {
            $lateMinutes = $attendance->check_in_time->diffInMinutes($attendance->exam->start_time);
            if ($lateMinutes > 0) {
                $attendance->late_minutes = $lateMinutes;
                if ($attendance->attendance_status === 'present') {
                    $attendance->attendance_status = 'late';
                }
            }
        }
    }

    /**
     * Handle check-out.
     *
     * @param  ExamAttendance  $attendance
     * @return void
     */
    private static function handleCheckOut($attendance)
    {
        // Calculate early departure minutes
        if ($attendance->exam && $attendance->exam->end_time) {
            $earlyDepartureMinutes = $attendance->exam->end_time->diffInMinutes($attendance->check_out_time);
            if ($earlyDepartureMinutes > 30 && $attendance->attendance_status === 'present') {
                $attendance->early_departure_minutes = $earlyDepartureMinutes;
                $attendance->attendance_status = 'early_departure';
            }
        }
    }

    /**
     * Update exam attendance statistics.
     *
     * @param  int  $examId
     * @return void
     */
    private static function updateExamAttendanceStats($examId)
    {
        $totalStudents = Student::where('class_id', function ($query) use ($examId) {
            $query->select('class_id')
                ->from('exams')
                ->where('id', $examId);
        })->where('status', 'active')->count();

        $presentCount = self::where('exam_id', $examId)
            ->whereIn('attendance_status', ['present', 'late', 'early_departure'])
            ->count();

        $absentCount = self::where('exam_id', $examId)
            ->where('attendance_status', 'absent')
            ->count();

        $excusedCount = self::where('exam_id', $examId)
            ->where('is_excused', true)
            ->count();

        $lateCount = self::where('exam_id', $examId)
            ->where('attendance_status', 'late')
            ->count();

        Cache::put("exam_attendance_stats_exam_{$examId}", [
            'total_students' => $totalStudents,
            'present_count' => $presentCount,
            'absent_count' => $absentCount,
            'excused_count' => $excusedCount,
            'late_count' => $lateCount,
            'attendance_rate' => $totalStudents > 0 ? round(($presentCount / $totalStudents) * 100, 2) : 0,
            'updated_at' => now()
        ], now()->addHours(6));
    }

    /**
     * Get attendance statistics for an exam.
     *
     * @param  int  $examId
     * @return array
     */
    public static function getExamAttendanceStats($examId)
    {
        $cacheKey = "exam_attendance_stats_exam_{$examId}";

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($examId) {
            $totalStudents = Student::where('class_id', function ($query) use ($examId) {
                $query->select('class_id')
                    ->from('exams')
                    ->where('id', $examId);
            })->where('status', 'active')->count();

            $presentCount = self::where('exam_id', $examId)
                ->whereIn('attendance_status', ['present', 'late', 'early_departure'])
                ->count();

            $absentCount = self::where('exam_id', $examId)
                ->where('attendance_status', 'absent')
                ->count();

            $excusedCount = self::where('exam_id', $examId)
                ->where('is_excused', true)
                ->count();

            $lateCount = self::where('exam_id', $examId)
                ->where('attendance_status', 'late')
                ->count();

            return [
                'total_students' => $totalStudents,
                'present_count' => $presentCount,
                'absent_count' => $absentCount,
                'excused_count' => $excusedCount,
                'late_count' => $lateCount,
                'attendance_rate' => $totalStudents > 0 ? round(($presentCount / $totalStudents) * 100, 2) : 0
            ];
        });
    }

    /**
     * Scope a query to only include present attendances.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePresent($query)
    {
        return $query->whereIn('attendance_status', ['present', 'late', 'early_departure']);
    }

    /**
     * Scope a query to only include absent attendances.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAbsent($query)
    {
        return $query->where('attendance_status', 'absent');
    }

    /**
     * Scope a query to only include late attendances.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeLate($query)
    {
        return $query->where('attendance_status', 'late');
    }

    /**
     * Scope a query to only include excused attendances.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeExcused($query)
    {
        return $query->where('is_excused', true);
    }

    /**
     * Scope a query to only include verified attendances.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeVerified($query)
    {
        return $query->whereNotNull('verified_by');
    }

    /**
     * Scope a query to only include attendances for a specific exam.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $examId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForExam($query, $examId)
    {
        return $query->where('exam_id', $examId);
    }

    /**
     * Scope a query to only include attendances for a specific student.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $studentId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForStudent($query, $studentId)
    {
        return $query->where('student_id', $studentId);
    }
}
