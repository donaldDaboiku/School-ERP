<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;    

class ExamSeatAllocation extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'exam_seat_allocations';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'exam_id',
        'hall_allocation_id',
        'student_id',
        'seat_number',
        'row_number',
        'column_number',
        'seat_type',
        'is_special_needs',
        'special_needs_notes',
        'status',
        'allocated_by',
        'allocated_at',
        'confirmed_by',
        'confirmed_at',
        'notes'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'row_number' => 'integer',
        'column_number' => 'integer',
        'is_special_needs' => 'boolean',
        'allocated_at' => 'datetime',
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
        'seat_type_display',
        'status_display',
        'seat_location',
        'is_confirmed',
        'is_active'
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $with = ['exam', 'hallAllocation', 'student', 'allocator', 'confirmer'];

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($allocation) {
            // Generate seat number if not provided
            if (empty($allocation->seat_number)) {
                $allocation->seat_number = self::generateSeatNumber($allocation);
            }
            
            // Set default status if not provided
            if (empty($allocation->status)) {
                $allocation->status = 'allocated';
            }
            
            // Set allocated_by and allocated_at
            if (empty($allocation->allocated_by) && Auth::check()) {
                $allocation->allocated_by = Auth::id();
            }
            
            if (empty($allocation->allocated_at)) {
                $allocation->allocated_at = now();
            }
            
            // Validate seat allocation
            self::validateSeatAllocation($allocation);
            
            Log::info('Exam seat allocation creating', [
                'exam_id' => $allocation->exam_id,
                'student_id' => $allocation->student_id,
                'seat_number' => $allocation->seat_number,
                'hall_allocation_id' => $allocation->hall_allocation_id
            ]);
        });

        static::updating(function ($allocation) {
            // Handle confirmation
            if ($allocation->isDirty('confirmed_by') && $allocation->confirmed_by) {
                $allocation->confirmed_at = now();
                $allocation->status = 'confirmed';
            }
            
            // Handle cancellation
            if ($allocation->isDirty('status') && $allocation->status === 'cancelled') {
                // Update hall allocation counts
                if ($allocation->hallAllocation) {
                    $allocation->hallAllocation->allocated_seats = max(0, $allocation->hallAllocation->allocated_seats - 1);
                    $allocation->hallAllocation->available_seats = $allocation->hallAllocation->capacity - $allocation->hallAllocation->allocated_seats;
                    $allocation->hallAllocation->is_full = false;
                    $allocation->hallAllocation->save();
                }
            }
        });

        static::saved(function ($allocation) {
            // Clear relevant cache
            Cache::forget("exam_seat_allocation_{$allocation->id}");
            Cache::forget("exam_seat_allocation_student_{$allocation->student_id}_exam_{$allocation->exam_id}");
            Cache::tags([
                "exam_seat_allocations_exam_{$allocation->exam_id}",
                "exam_seat_allocations_hall_{$allocation->hall_allocation_id}",
                "exam_seat_allocations_student_{$allocation->student_id}"
            ])->flush();
            
            // Update hall allocation counts if this is a new allocation
            if ($allocation->wasRecentlyCreated && $allocation->hallAllocation) {
                $allocation->hallAllocation->allocated_seats++;
                $allocation->hallAllocation->available_seats = $allocation->hallAllocation->capacity - $allocation->hallAllocation->allocated_seats;
                $allocation->hallAllocation->is_full = $allocation->hallAllocation->available_seats <= 0;
                $allocation->hallAllocation->save();
            }
        });

        static::deleted(function ($allocation) {
            // Clear cache
            Cache::forget("exam_seat_allocation_{$allocation->id}");
            Cache::forget("exam_seat_allocation_student_{$allocation->student_id}_exam_{$allocation->exam_id}");
            Cache::tags([
                "exam_seat_allocations_exam_{$allocation->exam_id}",
                "exam_seat_allocations_hall_{$allocation->hall_allocation_id}",
                "exam_seat_allocations_student_{$allocation->student_id}"
            ])->flush();
            
            // Update hall allocation counts
            if ($allocation->hallAllocation) {
                $allocation->hallAllocation->allocated_seats = max(0, $allocation->hallAllocation->allocated_seats - 1);
                $allocation->hallAllocation->available_seats = $allocation->hallAllocation->capacity - $allocation->hallAllocation->allocated_seats;
                $allocation->hallAllocation->is_full = false;
                $allocation->hallAllocation->save();
            }
        });
    }

    /**
     * Get the exam for this seat allocation.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function exam()
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    /**
     * Get the hall allocation for this seat allocation.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function hallAllocation()
    {
        return $this->belongsTo(ExamHallAllocation::class, 'hall_allocation_id');
    }

    /**
     * Get the student for this seat allocation.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    /**
     * Get the user who allocated this seat.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function allocator()
    {
        return $this->belongsTo(User::class, 'allocated_by');
    }

    /**
     * Get the user who confirmed this allocation.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function confirmer()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * Get the exam attendance for this seat allocation.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function attendance()
    {
        return $this->hasOne(ExamAttendance::class, 'seat_allocation_id');
    }

    /**
     * Get seat type display name.
     *
     * @return string
     */
    public function getSeatTypeDisplayAttribute()
    {
        $types = [
            'regular' => 'Regular',
            'special' => 'Special',
            'front_row' => 'Front Row',
            'back_row' => 'Back Row',
            'aisle' => 'Aisle',
            'window' => 'Window',
            'reserved' => 'Reserved'
        ];
        
        return $types[$this->seat_type] ?? ucfirst($this->seat_type);
    }

    /**
     * Get status display name.
     *
     * @return string
     */
    public function getStatusDisplayAttribute()
    {
        $statuses = [
            'allocated' => 'Allocated',
            'confirmed' => 'Confirmed',
            'cancelled' => 'Cancelled',
            'used' => 'Used',
            'no_show' => 'No Show'
        ];
        
        return $statuses[$this->status] ?? ucfirst($this->status);
    }

    /**
     * Get seat location description.
     *
     * @return string
     */
    public function getSeatLocationAttribute()
    {
        $location = "Seat {$this->seat_number}";
        
        if ($this->row_number) {
            $location .= ", Row {$this->row_number}";
        }
        
        if ($this->column_number) {
            $location .= ", Column {$this->column_number}";
        }
        
        if ($this->hallAllocation && $this->hallAllocation->hall) {
            $location .= " in {$this->hallAllocation->hall->name}";
        }
        
        return $location;
    }

    /**
     * Check if allocation is confirmed.
     *
     * @return bool
     */
    public function getIsConfirmedAttribute()
    {
        return $this->status === 'confirmed' && !is_null($this->confirmed_by);
    }

    /**
     * Check if allocation is active.
     *
     * @return bool
     */
    public function getIsActiveAttribute()
    {
        return in_array($this->status, ['allocated', 'confirmed']);
    }

    /**
     * Confirm the seat allocation.
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
            throw new \Exception('Seat allocation is already confirmed');
        }
        
        if ($this->status === 'cancelled') {
            throw new \Exception('Cannot confirm a cancelled allocation');
        }
        
        $this->confirmed_by = $confirmer->id;
        $this->confirmed_at = now();
        $this->status = 'confirmed';
        
        $this->save();
        
        return true;
    }

    /**
     * Cancel the seat allocation.
     *
     * @param  string|null  $notes
     * @return bool
     */
    public function cancel($notes = null)
    {
        if ($this->status === 'cancelled') {
            throw new \Exception('Seat allocation is already cancelled');
        }
        
        $this->status = 'cancelled';
        
        if ($notes) {
            $this->notes = $notes;
        }
        
        $this->save();
        
        return true;
    }

    /**
     * Mark seat as used (for attendance).
     *
     * @return bool
     */
    public function markAsUsed()
    {
        if ($this->status === 'used') {
            throw new \Exception('Seat is already marked as used');
        }
        
        $this->status = 'used';
        $this->save();
        
        return true;
    }

    /**
     * Mark student as no-show.
     *
     * @return bool
     */
    public function markAsNoShow()
    {
        if ($this->status === 'no_show') {
            throw new \Exception('Seat is already marked as no-show');
        }
        
        $this->status = 'no_show';
        $this->save();
        
        return true;
    }

    /**
     * Get seat allocation information for student.
     *
     * @return array
     */
    public function getAllocationInfo()
    {
        return [
            'student' => [
                'id' => $this->student_id,
                'name' => $this->student ? $this->student->name : 'N/A',
                'roll_number' => $this->student ? $this->student->roll_number : 'N/A'
            ],
            'exam' => [
                'id' => $this->exam_id,
                'name' => $this->exam ? $this->exam->name : 'N/A',
                'code' => $this->exam ? $this->exam->code : 'N/A',
                'date' => $this->exam ? $this->exam->date->format('Y-m-d') : 'N/A',
                'time' => $this->exam ? ($this->exam->start_time ? $this->exam->start_time->format('g:i A') : 'N/A') : 'N/A'
            ],
            'hall' => [
                'id' => $this->hallAllocation ? $this->hallAllocation->hall_id : null,
                'name' => $this->hallAllocation && $this->hallAllocation->hall ? $this->hallAllocation->hall->name : 'N/A',
                'room_number' => $this->hallAllocation && $this->hallAllocation->hall ? $this->hallAllocation->hall->room_number : 'N/A'
            ],
            'seat' => [
                'number' => $this->seat_number,
                'row' => $this->row_number,
                'column' => $this->column_number,
                'type' => $this->seat_type_display,
                'location' => $this->seat_location
            ],
            'status' => $this->status_display,
            'is_confirmed' => $this->is_confirmed,
            'confirmed_at' => $this->confirmed_at ? $this->confirmed_at->format('Y-m-d H:i:s') : null
        ];
    }

    /**
     * Generate seat number.
     *
     * @param  ExamSeatAllocation  $allocation
     * @return string
     */
    private static function generateSeatNumber($allocation)
    {
        if (!$allocation->hall_allocation_id) {
            throw new \Exception('Hall allocation is required to generate seat number');
        }
        
        // Get existing seat numbers in this hall allocation
        $existingSeats = self::where('hall_allocation_id', $allocation->hall_allocation_id)
            ->pluck('seat_number')
            ->toArray();
        
        // Simple seat numbering: Row A, seats 1-30, Row B, seats 1-30, etc.
        $rows = range('A', 'Z');
        $seatsPerRow = 30;
        
        foreach ($rows as $row) {
            for ($seat = 1; $seat <= $seatsPerRow; $seat++) {
                $seatNumber = $row . str_pad($seat, 2, '0', STR_PAD_LEFT);
                
                if (!in_array($seatNumber, $existingSeats)) {
                    // Set row and column numbers
                    $allocation->row_number = ord($row) - ord('A') + 1;
                    $allocation->column_number = $seat;
                    return $seatNumber;
                }
            }
        }
        
        throw new \Exception('No available seats in this hall allocation');
    }

    /**
     * Validate seat allocation.
     *
     * @param  ExamSeatAllocation  $allocation
     * @return void
     * @throws \Exception
     */
    private static function validateSeatAllocation($allocation)
    {
        // Check if student already has a seat allocation for this exam
        $existingAllocation = self::where('exam_id', $allocation->exam_id)
            ->where('student_id', $allocation->student_id)
            ->where('id', '!=', $allocation->id)
            ->where('status', '!=', 'cancelled')
            ->first();
            
        if ($existingAllocation) {
            throw new \Exception('Student already has a seat allocation for this exam');
        }
        
        // Check if seat number is already allocated in this hall allocation
        if ($allocation->seat_number && $allocation->hall_allocation_id) {
            $existingSeat = self::where('hall_allocation_id', $allocation->hall_allocation_id)
                ->where('seat_number', $allocation->seat_number)
                ->where('id', '!=', $allocation->id)
                ->where('status', '!=', 'cancelled')
                ->first();
                
            if ($existingSeat) {
                throw new \Exception("Seat {$allocation->seat_number} is already allocated");
            }
        }
        
        // Check if hall allocation is available
        if ($allocation->hallAllocation && $allocation->hallAllocation->is_full) {
            throw new \Exception('Hall allocation is full');
        }
    }

    /**
     * Get seat allocations for a student.
     *
     * @param  int  $studentId
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getForStudent($studentId, $filters = [])
    {
        $cacheKey = "exam_seat_allocations_student_{$studentId}_" . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHours(6), function () use ($studentId, $filters) {
            $query = self::where('student_id', $studentId)
                ->with(['exam', 'hallAllocation.hall', 'allocator'])
                ->orderBy('allocated_at', 'desc');
            
            // Apply filters
            if (isset($filters['exam_id'])) {
                $query->where('exam_id', $filters['exam_id']);
            }
            
            if (isset($filters['hall_allocation_id'])) {
                $query->where('hall_allocation_id', $filters['hall_allocation_id']);
            }
            
            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            
            if (isset($filters['is_confirmed'])) {
                if ($filters['is_confirmed']) {
                    $query->where('status', 'confirmed');
                } else {
                    $query->where('status', '!=', 'confirmed');
                }
            }
            
            return $query->get();
        });
    }

    /**
     * Get seat allocations for an exam.
     *
     * @param  int  $examId
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getForExam($examId, $filters = [])
    {
        $cacheKey = "exam_seat_allocations_exam_{$examId}_" . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHours(6), function () use ($examId, $filters) {
            $query = self::where('exam_id', $examId)
                ->with(['student', 'hallAllocation.hall', 'allocator'])
                ->orderBy('hall_allocation_id')
                ->orderBy('seat_number');
            
            // Apply filters
            if (isset($filters['hall_allocation_id'])) {
                $query->where('hall_allocation_id', $filters['hall_allocation_id']);
            }
            
            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            
            if (isset($filters['student_id'])) {
                $query->where('student_id', $filters['student_id']);
            }
            
            return $query->get();
        });
    }

    /**
     * Get seat allocation by student and exam.
     *
     * @param  int  $studentId
     * @param  int  $examId
     * @return ExamSeatAllocation|null
     */
    public static function getByStudentAndExam($studentId, $examId)
    {
        $cacheKey = "exam_seat_allocation_student_{$studentId}_exam_{$examId}";
        
        return Cache::remember($cacheKey, now()->addHours(6), function () use ($studentId, $examId) {
            return self::where('student_id', $studentId)
                ->where('exam_id', $examId)
                ->where('status', '!=', 'cancelled')
                ->first();
        });
    }

    /**
     * Get seat allocation statistics.
     *
     * @param  int  $examId
     * @return array
     */
    public static function getStatistics($examId)
    {
        $totalAllocations = self::where('exam_id', $examId)->count();
        $confirmedAllocations = self::where('exam_id', $examId)->where('status', 'confirmed')->count();
        $cancelledAllocations = self::where('exam_id', $examId)->where('status', 'cancelled')->count();
        $specialNeedsAllocations = self::where('exam_id', $examId)->where('is_special_needs', true)->count();
        
        return [
            'total_allocations' => $totalAllocations,
            'confirmed_allocations' => $confirmedAllocations,
            'cancelled_allocations' => $cancelledAllocations,
            'special_needs_allocations' => $specialNeedsAllocations,
            'confirmation_rate' => $totalAllocations > 0 ? round(($confirmedAllocations / $totalAllocations) * 100, 2) : 0,
            'cancellation_rate' => $totalAllocations > 0 ? round(($cancelledAllocations / $totalAllocations) * 100, 2) : 0
        ];
    }

    /**
     * Scope a query to only include confirmed allocations.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    /**
     * Scope a query to only include active allocations.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['allocated', 'confirmed']);
    }

    /**
     * Scope a query to only include special needs allocations.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSpecialNeeds($query)
    {
        return $query->where('is_special_needs', true);
    }

    /**
     * Scope a query to only include allocations for a specific hall.
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
     * Scope a query to only include allocations for a specific exam.
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
     * Scope a query to only include allocations for a specific student.
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