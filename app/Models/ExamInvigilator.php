<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;    

class ExamInvigilator extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'exam_invigilators';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'exam_id',
        'hall_allocation_id',
        'teacher_id',
        'role',
        'assigned_date',
        'start_time',
        'end_time',
        'status',
        'attendance_status',
        'check_in_time',
        'check_out_time',
        'notes',
        'assigned_by',
        'assigned_at',
        'confirmed_by',
        'confirmed_at'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'assigned_date' => 'date',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'check_in_time' => 'datetime',
        'check_out_time' => 'datetime',
        'assigned_at' => 'datetime',
        'confirmed_at' => 'datetime',
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
        'role_display',
        'status_display',
        'attendance_status_display',
        'is_present',
        'is_absent',
        'duration_minutes',
        'time_slot'
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $with = ['exam', 'hallAllocation', 'teacher', 'assigner', 'confirmer'];

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($invigilator) {
            // Set default role if not provided
            if (empty($invigilator->role)) {
                $invigilator->role = 'invigilator';
            }
            
            // Set default status if not provided
            if (empty($invigilator->status)) {
                $invigilator->status = 'assigned';
            }
            
            // Set default attendance status if not provided
            if (empty($invigilator->attendance_status)) {
                $invigilator->attendance_status = 'pending';
            }
            
            // Set assigned_by and assigned_at
            if (empty($invigilator->assigned_by) && Auth::check()) {
                $invigilator->assigned_by = Auth::id();
            }
            
            if (empty($invigilator->assigned_at)) {
                $invigilator->assigned_at = now();
            }
            
            // Validate invigilator assignment
            self::validateAssignment($invigilator);
            
            Log::info('Exam invigilator creating', [
                'exam_id' => $invigilator->exam_id,
                'teacher_id' => $invigilator->teacher_id,
                'role' => $invigilator->role,
                'assigned_by' => $invigilator->assigned_by
            ]);
        });

        static::updating(function ($invigilator) {
            // Handle confirmation
            if ($invigilator->isDirty('confirmed_by') && $invigilator->confirmed_by) {
                $invigilator->confirmed_at = now();
                $invigilator->status = 'confirmed';
            }
            
            // Handle check-in
            if ($invigilator->isDirty('check_in_time') && $invigilator->check_in_time) {
                $invigilator->attendance_status = 'present';
            }
            
            // Handle check-out
            if ($invigilator->isDirty('check_out_time') && $invigilator->check_out_time) {
                // Attendance status remains present
            }
            
            // Handle status changes
            if ($invigilator->isDirty('status')) {
                $oldStatus = $invigilator->getOriginal('status');
                $newStatus = $invigilator->status;
                
                // If cancelled, reset attendance
                if ($newStatus === 'cancelled') {
                    $invigilator->attendance_status = 'cancelled';
                    $invigilator->check_in_time = null;
                    $invigilator->check_out_time = null;
                }
            }
        });

        static::saved(function ($invigilator) {
            // Clear relevant cache
            Cache::forget("exam_invigilator_{$invigilator->id}");
            Cache::tags([
                "exam_invigilators_exam_{$invigilator->exam_id}",
                "exam_invigilators_hall_{$invigilator->hall_allocation_id}",
                "exam_invigilators_teacher_{$invigilator->teacher_id}"
            ])->flush();
        });

        static::deleted(function ($invigilator) {
            // Clear cache
            Cache::forget("exam_invigilator_{$invigilator->id}");
            Cache::tags([
                "exam_invigilators_exam_{$invigilator->exam_id}",
                "exam_invigilators_hall_{$invigilator->hall_allocation_id}",
                "exam_invigilators_teacher_{$invigilator->teacher_id}"
            ])->flush();
        });
    }

    /**
     * Get the exam for this invigilator.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function exam()
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    /**
     * Get the hall allocation for this invigilator.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function hallAllocation()
    {
        return $this->belongsTo(ExamHallAllocation::class, 'hall_allocation_id');
    }

    /**
     * Get the teacher for this invigilator.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function teacher()
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    /**
     * Get the user who assigned this invigilator.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function assigner()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * Get the user who confirmed this assignment.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function confirmer()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * Get role display name.
     *
     * @return string
     */
    public function getRoleDisplayAttribute()
    {
        $roles = [
            'chief_invigilator' => 'Chief Invigilator',
            'invigilator' => 'Invigilator',
            'assistant_invigilator' => 'Assistant Invigilator',
            'observer' => 'Observer',
            'technician' => 'Technician'
        ];
        
        return $roles[$this->role] ?? ucfirst($this->role);
    }

    /**
     * Get status display name.
     *
     * @return string
     */
    public function getStatusDisplayAttribute()
    {
        $statuses = [
            'assigned' => 'Assigned',
            'confirmed' => 'Confirmed',
            'cancelled' => 'Cancelled',
            'rejected' => 'Rejected',
            'completed' => 'Completed'
        ];
        
        return $statuses[$this->status] ?? ucfirst($this->status);
    }

    /**
     * Get attendance status display name.
     *
     * @return string
     */
    public function getAttendanceStatusDisplayAttribute()
    {
        $statuses = [
            'pending' => 'Pending',
            'present' => 'Present',
            'absent' => 'Absent',
            'late' => 'Late',
            'cancelled' => 'Cancelled'
        ];
        
        return $statuses[$this->attendance_status] ?? ucfirst($this->attendance_status);
    }

    /**
     * Check if invigilator is present.
     *
     * @return bool
     */
    public function getIsPresentAttribute()
    {
        return $this->attendance_status === 'present';
    }

    /**
     * Check if invigilator is absent.
     *
     * @return bool
     */
    public function getIsAbsentAttribute()
    {
        return $this->attendance_status === 'absent';
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
     * Get time slot.
     *
     * @return string
     */
    public function getTimeSlotAttribute()
    {
        if (!$this->start_time || !$this->end_time) {
            return 'N/A';
        }
        
        $start = $this->start_time->format('g:i A');
        $end = $this->end_time->format('g:i A');
        
        return "{$start} - {$end}";
    }

    /**
     * Confirm the assignment.
     *
     * @param  User|null  $confirmer
     * @return bool
     */
    public function confirm($confirmer = null)
    {
        if (!$confirmer) {
            $confirmer = Auth::user();
        }
        
        if ($this->status === 'confirmed') {
            throw new \Exception('Assignment is already confirmed');
        }
        
        if ($this->status === 'cancelled') {
            throw new \Exception('Cannot confirm a cancelled assignment');
        }
        
        $this->confirmed_by = $confirmer->id;
        $this->confirmed_at = now();
        $this->status = 'confirmed';
        
        $this->save();
        
        return true;
    }

    /**
     * Cancel the assignment.
     *
     * @param  string|null  $notes
     * @return bool
     */
    public function cancel($notes = null)
    {
        if ($this->status === 'cancelled') {
            throw new \Exception('Assignment is already cancelled');
        }
        
        $this->status = 'cancelled';
        $this->attendance_status = 'cancelled';
        
        if ($notes) {
            $this->notes = $notes;
        }
        
        $this->save();
        
        return true;
    }

    /**
     * Mark check-in.
     *
     * @param  \DateTime|null  $checkInTime
     * @return bool
     */
    public function markCheckIn($checkInTime = null)
    {
        if ($this->check_in_time) {
            throw new \Exception('Invigilator has already checked in');
        }
        
        if ($this->status !== 'confirmed') {
            throw new \Exception('Assignment must be confirmed before check-in');
        }
        
        $this->check_in_time = $checkInTime ?? now();
        $this->attendance_status = 'present';
        
        $this->save();
        
        return true;
    }

    /**
     * Mark check-out.
     *
     * @param  \DateTime|null  $checkOutTime
     * @return bool
     */
    public function markCheckOut($checkOutTime = null)
    {
        if (!$this->check_in_time) {
            throw new \Exception('Invigilator has not checked in');
        }
        
        if ($this->check_out_time) {
            throw new \Exception('Invigilator has already checked out');
        }
        
        $this->check_out_time = $checkOutTime ?? now();
        $this->save();
        
        return true;
    }

    /**
     * Validate invigilator assignment.
     *
     * @param  ExamInvigilator  $invigilator
     * @return void
     * @throws \Exception
     */
    private static function validateAssignment($invigilator)
    {
        // Check for scheduling conflicts
        $conflictingAssignment = self::where('teacher_id', $invigilator->teacher_id)
            ->where('id', '!=', $invigilator->id)
            ->where('assigned_date', $invigilator->assigned_date)
            ->where('status', '!=', 'cancelled')
            ->where(function($q) use ($invigilator) {
                $q->whereBetween('start_time', [$invigilator->start_time, $invigilator->end_time])
                  ->orWhereBetween('end_time', [$invigilator->start_time, $invigilator->end_time])
                  ->orWhere(function($q2) use ($invigilator) {
                      $q2->where('start_time', '<', $invigilator->start_time)
                         ->where('end_time', '>', $invigilator->end_time);
                  });
            })
            ->first();
            
        if ($conflictingAssignment) {
            throw new \Exception("Teacher has a scheduling conflict with assignment ID: {$conflictingAssignment->id}");
        }
        
        // Check if teacher is available
        if ($invigilator->teacher && !$invigilator->teacher->is_available) {
            throw new \Exception('Teacher is not available for invigilation');
        }
    }

    /**
     * Get invigilators for an exam.
     *
     * @param  int  $examId
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getForExam($examId, $filters = [])
    {
        $cacheKey = "exam_invigilators_exam_{$examId}_" . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHours(6), function () use ($examId, $filters) {
            $query = self::where('exam_id', $examId)
                ->with(['teacher.user', 'hallAllocation.hall', 'assigner'])
                ->orderBy('role')
                ->orderBy('teacher_id');
            
            // Apply filters
            if (isset($filters['hall_allocation_id'])) {
                $query->where('hall_allocation_id', $filters['hall_allocation_id']);
            }
            
            if (isset($filters['teacher_id'])) {
                $query->where('teacher_id', $filters['teacher_id']);
            }
            
            if (isset($filters['role'])) {
                $query->where('role', $filters['role']);
            }
            
            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            
            if (isset($filters['attendance_status'])) {
                $query->where('attendance_status', $filters['attendance_status']);
            }
            
            return $query->get();
        });
    }

    /**
     * Get invigilator statistics.
     *
     * @param  array  $filters
     * @return array
     */
    public static function getStatistics($filters = [])
    {
        $query = self::query();
        
        // Apply filters
        if (isset($filters['start_date'])) {
            $query->where('assigned_date', '>=', $filters['start_date']);
        }
        
        if (isset($filters['end_date'])) {
            $query->where('assigned_date', '<=', $filters['end_date']);
        }
        
        if (isset($filters['teacher_id'])) {
            $query->where('teacher_id', $filters['teacher_id']);
        }
        
        $totalAssignments = $query->count();
        $confirmedAssignments = $query->where('status', 'confirmed')->count();
        $presentCount = $query->where('attendance_status', 'present')->count();
        $absentCount = $query->where('attendance_status', 'absent')->count();
        
        return [
            'total_assignments' => $totalAssignments,
            'confirmed_assignments' => $confirmedAssignments,
            'confirmation_rate' => $totalAssignments > 0 ? round(($confirmedAssignments / $totalAssignments) * 100, 2) : 0,
            'present_count' => $presentCount,
            'absent_count' => $absentCount,
            'attendance_rate' => ($presentCount + $absentCount) > 0 ? round(($presentCount / ($presentCount + $absentCount)) * 100, 2) : 0
        ];
    }

    /**
     * Scope a query to only include confirmed invigilators.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    /**
     * Scope a query to only include present invigilators.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePresent($query)
    {
        return $query->where('attendance_status', 'present');
    }

    /**
     * Scope a query to only include invigilators for a specific hall.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $hallAllocationId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForHall($query, $hallAllocationId)
    {
        return $query->where('hall_allocation_id', $hallAllocationId);
    }

    /**
     * Scope a query to only include invigilators for a specific teacher.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $teacherId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForTeacher($query, $teacherId)
    {
        return $query->where('teacher_id', $teacherId);
    }

    /**
     * Scope a query to only include invigilators for a specific date.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $date
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForDate($query, $date)
    {
        return $query->where('assigned_date', $date);
    }
}