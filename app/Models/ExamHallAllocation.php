<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;    

class ExamHallAllocation extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'exam_hall_allocations';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'exam_id',
        'hall_id',
        'date',
        'start_time',
        'end_time',
        'capacity',
        'allocated_seats',
        'available_seats',
        'is_full',
        'supervisor_id',
        'assistant_supervisor_id',
        'notes',
        'status',
        'created_by',
        'updated_by'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'date' => 'date',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'capacity' => 'integer',
        'allocated_seats' => 'integer',
        'available_seats' => 'integer',
        'is_full' => 'boolean',
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
        'status_display',
        'is_available',
        'occupancy_rate',
        'time_slot'
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $with = ['exam', 'hall', 'supervisor', 'assistantSupervisor'];

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($allocation) {
            // Set default capacity from hall if not provided
            if (empty($allocation->capacity) && $allocation->hall_id) {
                $allocation->capacity = $allocation->hall->capacity ?? 0;
            }
            
            // Set default available seats
            $allocation->allocated_seats = $allocation->allocated_seats ?? 0;
            $allocation->available_seats = $allocation->capacity - $allocation->allocated_seats;
            $allocation->is_full = $allocation->available_seats <= 0;
            
            // Set default status
            if (empty($allocation->status)) {
                $allocation->status = 'scheduled';
            }
            
            // Set created_by if not set
            if (empty($allocation->created_by) && Auth::check()) {
                $allocation->created_by = Auth::id();
            }
            
            // Validate allocation
            self::validateAllocation($allocation);
            
            Log::info('Exam hall allocation creating', [
                'exam_id' => $allocation->exam_id,
                'hall_id' => $allocation->hall_id,
                'date' => $allocation->date,
                'capacity' => $allocation->capacity
            ]);
        });

        static::updating(function ($allocation) {
            // Update available seats when allocated_seats changes
            if ($allocation->isDirty('allocated_seats') || $allocation->isDirty('capacity')) {
                $allocation->available_seats = $allocation->capacity - $allocation->allocated_seats;
                $allocation->is_full = $allocation->available_seats <= 0;
            }
            
            // Update status based on availability
            if ($allocation->is_full && $allocation->status !== 'full') {
                $allocation->status = 'full';
            } elseif (!$allocation->is_full && $allocation->status === 'full') {
                $allocation->status = 'available';
            }
            
            // Update updated_by
            if (Auth::check()) {
                $allocation->updated_by = Auth::id();
            }
        });

        static::saved(function ($allocation) {
            // Clear relevant cache
            Cache::forget("exam_hall_allocation_{$allocation->id}");
            Cache::tags([
                "exam_hall_allocations_exam_{$allocation->exam_id}",
                "exam_hall_allocations_hall_{$allocation->hall_id}",
                "exam_hall_allocations_date_{$allocation->date}"
            ])->flush();
        });

        static::deleted(function ($allocation) {
            // Clear cache
            Cache::forget("exam_hall_allocation_{$allocation->id}");
            Cache::tags([
                "exam_hall_allocations_exam_{$allocation->exam_id}",
                "exam_hall_allocations_hall_{$allocation->hall_id}",
                "exam_hall_allocations_date_{$allocation->date}"
            ])->flush();
        });
    }

    /**
     * Get the exam for this allocation.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function exam()
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    /**
     * Get the hall for this allocation.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function hall()
    {
        return $this->belongsTo(ExamHall::class, 'hall_id');
    }

    /**
     * Get the supervisor for this allocation.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function supervisor()
    {
        return $this->belongsTo(Teacher::class, 'supervisor_id');
    }

    /**
     * Get the assistant supervisor for this allocation.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function assistantSupervisor()
    {
        return $this->belongsTo(Teacher::class, 'assistant_supervisor_id');
    }

    /**
     * Get the user who created this allocation.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this allocation.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get the seat allocations for this hall allocation.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function seatAllocations()
    {
        return $this->hasMany(ExamSeatAllocation::class, 'hall_allocation_id');
    }

    /**
     * Get the invigilators for this hall allocation.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function invigilators()
    {
        return $this->hasMany(ExamInvigilator::class, 'hall_allocation_id');
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
            'available' => 'Available',
            'full' => 'Full',
            'cancelled' => 'Cancelled',
            'completed' => 'Completed'
        ];
        
        return $statuses[$this->status] ?? ucfirst($this->status);
    }

    /**
     * Check if allocation is available.
     *
     * @return bool
     */
    public function getIsAvailableAttribute()
    {
        return $this->available_seats > 0 && $this->status === 'available';
    }

    /**
     * Get occupancy rate.
     *
     * @return float
     */
    public function getOccupancyRateAttribute()
    {
        if ($this->capacity === 0) {
            return 0;
        }
        
        return round(($this->allocated_seats / $this->capacity) * 100, 2);
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
     * Allocate a seat for a student.
     *
     * @param  int  $studentId
     * @param  string|null  $seatNumber
     * @return ExamSeatAllocation
     */
    public function allocateSeat($studentId, $seatNumber = null)
    {
        // Check if hall is available
        if (!$this->is_available) {
            throw new \Exception('Hall allocation is not available');
        }
        
        // Check if student already has a seat allocation for this exam
        $existingAllocation = ExamSeatAllocation::where('exam_id', $this->exam_id)
            ->where('student_id', $studentId)
            ->first();
            
        if ($existingAllocation) {
            throw new \Exception('Student already has a seat allocation for this exam');
        }
        
        // Generate seat number if not provided
        if (!$seatNumber) {
            $seatNumber = $this->generateSeatNumber();
        }
        
        // Check if seat number is available
        if ($this->seatAllocations()->where('seat_number', $seatNumber)->exists()) {
            throw new \Exception("Seat {$seatNumber} is already allocated");
        }
        
        // Create seat allocation
        $seatAllocation = $this->seatAllocations()->create([
            'exam_id' => $this->exam_id,
            'student_id' => $studentId,
            'seat_number' => $seatNumber,
            'allocated_by' => Auth::id(),
            'allocated_at' => now()
        ]);
        
        // Update allocation counts
        $this->allocated_seats++;
        $this->available_seats = $this->capacity - $this->allocated_seats;
        $this->is_full = $this->available_seats <= 0;
        
        if ($this->is_full) {
            $this->status = 'full';
        }
        
        $this->save();
        
        return $seatAllocation;
    }

    /**
     * Deallocate a seat.
     *
     * @param  int  $studentId
     * @return bool
     */
    public function deallocateSeat($studentId)
    {
        $seatAllocation = $this->seatAllocations()
            ->where('student_id', $studentId)
            ->first();
            
        if (!$seatAllocation) {
            throw new \Exception('No seat allocation found for this student');
        }
        
        $seatAllocation->delete();
        
        // Update allocation counts
        $this->allocated_seats--;
        $this->available_seats = $this->capacity - $this->allocated_seats;
        $this->is_full = false;
        
        if ($this->status === 'full') {
            $this->status = 'available';
        }
        
        $this->save();
        
        return true;
    }

    /**
     * Generate a seat number.
     *
     * @return string
     */
    private function generateSeatNumber()
    {
        $allocatedSeats = $this->seatAllocations()->pluck('seat_number')->toArray();
        
        // Simple seat numbering: Row A, seats 1-30, Row B, seats 1-30, etc.
        $rows = range('A', 'Z');
        $seatsPerRow = 30;
        
        foreach ($rows as $row) {
            for ($seat = 1; $seat <= $seatsPerRow; $seat++) {
                $seatNumber = $row . str_pad($seat, 2, '0', STR_PAD_LEFT);
                
                if (!in_array($seatNumber, $allocatedSeats)) {
                    return $seatNumber;
                }
            }
        }
        
        throw new \Exception('No available seats in this hall');
    }

    /**
     * Get hall allocation statistics.
     *
     * @return array
     */
    public function getStatistics()
    {
        return [
            'total_seats' => $this->capacity,
            'allocated_seats' => $this->allocated_seats,
            'available_seats' => $this->available_seats,
            'occupancy_rate' => $this->occupancy_rate,
            'seat_allocations' => $this->seatAllocations()->count(),
            'invigilators_count' => $this->invigilators()->count()
        ];
    }

    /**
     * Validate hall allocation.
     *
     * @param  ExamHallAllocation  $allocation
     * @return void
     * @throws \Exception
     */
    private static function validateAllocation($allocation)
    {
        // Check if hall exists and is available
        if (!$allocation->hall) {
            throw new \Exception('Hall not found');
        }
        
        // Check hall capacity
        if ($allocation->capacity > $allocation->hall->capacity) {
            throw new \Exception("Allocation capacity ({$allocation->capacity}) exceeds hall capacity ({$allocation->hall->capacity})");
        }
        
        // Check for scheduling conflicts
        $conflictingAllocation = self::where('hall_id', $allocation->hall_id)
            ->where('id', '!=', $allocation->id)
            ->where('date', $allocation->date)
            ->where(function($q) use ($allocation) {
                $q->whereBetween('start_time', [$allocation->start_time, $allocation->end_time])
                  ->orWhereBetween('end_time', [$allocation->start_time, $allocation->end_time])
                  ->orWhere(function($q2) use ($allocation) {
                      $q2->where('start_time', '<', $allocation->start_time)
                         ->where('end_time', '>', $allocation->end_time);
                  });
            })
            ->where('status', '!=', 'cancelled')
            ->first();
            
        if ($conflictingAllocation) {
            throw new \Exception("Hall scheduling conflict with allocation ID: {$conflictingAllocation->id}");
        }
    }

    /**
     * Get hall allocations for a specific date.
     *
     * @param  string  $date
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getForDate($date, $filters = [])
    {
        $cacheKey = "exam_hall_allocations_date_{$date}_" . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHours(6), function () use ($date, $filters) {
            $query = self::where('date', $date)
                ->with(['exam', 'hall', 'supervisor.user'])
                ->orderBy('start_time')
                ->orderBy('hall_id');
            
            // Apply filters
            if (isset($filters['hall_id'])) {
                $query->where('hall_id', $filters['hall_id']);
            }
            
            if (isset($filters['exam_id'])) {
                $query->where('exam_id', $filters['exam_id']);
            }
            
            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            
            return $query->get();
        });
    }

    /**
     * Scope a query to only include available allocations.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAvailable($query)
    {
        return $query->where('status', 'available')
                     ->where('available_seats', '>', 0);
    }

    /**
     * Scope a query to only include full allocations.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFull($query)
    {
        return $query->where('status', 'full')
                     ->orWhere('available_seats', '<=', 0);
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
     * Scope a query to only include allocations for a specific hall.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $hallId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForHall($query, $hallId)
    {
        return $query->where('hall_id', $hallId);
    }
}