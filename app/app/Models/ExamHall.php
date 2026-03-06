<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;    
use Illuminate\Support\Facades\DB;

class ExamHall extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'exam_halls';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'code',
        'description',
        'building',
        'floor',
        'room_number',
        'capacity',
        'available_seats',
        'rows',
        'columns',
        'seat_arrangement',
        'facilities',
        'is_air_conditioned',
        'has_projector',
        'has_sound_system',
        'has_special_needs_access',
        'special_needs_capacity',
        'status',
        'is_active',
        'in_charge_id',
        'assistant_in_charge_id',
        'created_by',
        'updated_by'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'capacity' => 'integer',
        'available_seats' => 'integer',
        'rows' => 'integer',
        'columns' => 'integer',
        'seat_arrangement' => 'array',
        'facilities' => 'array',
        'is_air_conditioned' => 'boolean',
        'has_projector' => 'boolean',
        'has_sound_system' => 'boolean',
        'has_special_needs_access' => 'boolean',
        'special_needs_capacity' => 'integer',
        'is_active' => 'boolean',
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
        'full_name',
        'status_display',
        'occupancy_rate',
        'is_available',
        'facilities_list',
        'location'
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $with = ['inCharge', 'assistantInCharge'];

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($hall) {
            // Generate hall code if not provided
            if (empty($hall->code)) {
                $hall->code = self::generateHallCode($hall);
            }
            
            // Set default capacity based on rows and columns if not provided
            if (empty($hall->capacity) && $hall->rows && $hall->columns) {
                $hall->capacity = $hall->rows * $hall->columns;
            }
            
            // Set available seats
            $hall->available_seats = $hall->capacity;
            
            // Set default status
            if (empty($hall->status)) {
                $hall->status = 'available';
            }
            
            // Set is_active default
            if (is_null($hall->is_active)) {
                $hall->is_active = true;
            }
            
            // Set created_by if not set
            if (empty($hall->created_by) && Auth::check()) {
                $hall->created_by = Auth::id();
            }
            
            // Validate hall
            self::validateHall($hall);
            
            Log::info('Exam hall creating', [
                'name' => $hall->name,
                'code' => $hall->code,
                'capacity' => $hall->capacity,
                'created_by' => $hall->created_by
            ]);
        });

        static::updating(function ($hall) {
            // Update available seats if capacity changes
            if ($hall->isDirty('capacity')) {
                $hall->available_seats = $hall->capacity;
            }
            
            // Update updated_by
            if (Auth::check()) {
                $hall->updated_by = Auth::id();
            }
            
            // Validate hall on update
            self::validateHall($hall);
        });

        static::saved(function ($hall) {
            // Clear relevant cache
            Cache::forget("exam_hall_{$hall->id}");
            Cache::forget("exam_hall_code_{$hall->code}");
            Cache::tags([
                "exam_halls_building_{$hall->building}",
                "exam_halls_active",
                "exam_halls_available"
            ])->flush();
        });

        static::deleted(function ($hall) {
            // Clear cache
            Cache::forget("exam_hall_{$hall->id}");
            Cache::forget("exam_hall_code_{$hall->code}");
            Cache::tags([
                "exam_halls_building_{$hall->building}",
                "exam_halls_active",
                "exam_halls_available"
            ])->flush();
        });
    }

    /**
     * Get the in-charge (teacher) for this hall.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function inCharge()
    {
        return $this->belongsTo(Teacher::class, 'in_charge_id');
    }

    /**
     * Get the assistant in-charge for this hall.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function assistantInCharge()
    {
        return $this->belongsTo(Teacher::class, 'assistant_in_charge_id');
    }

    /**
     * Get the user who created this hall.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this hall.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get the hall allocations.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function allocations()
    {
        return $this->hasMany(ExamHallAllocation::class, 'hall_id');
    }

    /**
     * Get the seat allocations for this hall.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function seatAllocations()
    {
        return $this->hasManyThrough(
            ExamSeatAllocation::class,
            ExamHallAllocation::class,
            'hall_id', // Foreign key on ExamHallAllocation table
            'hall_allocation_id', // Foreign key on ExamSeatAllocation table
            'id', // Local key on ExamHall table
            'id' // Local key on ExamHallAllocation table
        );
    }

    /**
     * Get full hall name.
     *
     * @return string
     */
    public function getFullNameAttribute()
    {
        $name = $this->name;
        
        if ($this->building) {
            $name .= ", {$this->building}";
        }
        
        if ($this->room_number) {
            $name .= " (Room {$this->room_number})";
        }
        
        return $name;
    }

    /**
     * Get status display name.
     *
     * @return string
     */
    public function getStatusDisplayAttribute()
    {
        $statuses = [
            'available' => 'Available',
            'occupied' => 'Occupied',
            'maintenance' => 'Under Maintenance',
            'reserved' => 'Reserved',
            'closed' => 'Closed'
        ];
        
        return $statuses[$this->status] ?? ucfirst($this->status);
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
        
        $occupiedSeats = $this->capacity - $this->available_seats;
        return round(($occupiedSeats / $this->capacity) * 100, 2);
    }

    /**
     * Check if hall is available.
     *
     * @return bool
     */
    public function getIsAvailableAttribute()
    {
        return $this->status === 'available' && $this->is_active;
    }

    /**
     * Get facilities list.
     *
     * @return array
     */
    public function getFacilitiesListAttribute()
    {
        $facilities = [];
        
        if ($this->is_air_conditioned) {
            $facilities[] = 'Air Conditioned';
        }
        
        if ($this->has_projector) {
            $facilities[] = 'Projector';
        }
        
        if ($this->has_sound_system) {
            $facilities[] = 'Sound System';
        }
        
        if ($this->has_special_needs_access) {
            $facilities[] = 'Special Needs Access';
        }
        
        if ($this->facilities && is_array($this->facilities)) {
            $facilities = array_merge($facilities, $this->facilities);
        }
        
        return array_unique($facilities);
    }

    /**
     * Get hall location.
     *
     * @return string
     */
    public function getLocationAttribute()
    {
        $location = [];
        
        if ($this->building) {
            $location[] = $this->building;
        }
        
        if ($this->floor) {
            $location[] = "Floor {$this->floor}";
        }
        
        if ($this->room_number) {
            $location[] = "Room {$this->room_number}";
        }
        
        return implode(', ', $location);
    }

    /**
     * Reserve the hall for a specific period.
     *
     * @param  \DateTime  $startDate
     * @param  \DateTime  $endDate
     * @param  string  $purpose
     * @return bool
     */
    public function reserve($startDate, $endDate, $purpose)
    {
        if (!$this->is_available) {
            throw new \Exception('Hall is not available for reservation');
        }
        
        $this->status = 'reserved';
        $this->save();
        
        // Create reservation record
        ExamHallReservation::create([
            'hall_id' => $this->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'purpose' => $purpose,
            'reserved_by' => Auth::id(),
            'status' => 'active'
        ]);
        
        return true;
    }

    /**
     * Release reservation.
     *
     * @return bool
     */
    public function releaseReservation()
    {
        if ($this->status !== 'reserved') {
            throw new \Exception('Hall is not currently reserved');
        }
        
        $this->status = 'available';
        $this->save();
        
        // Update active reservations
        ExamHallReservation::where('hall_id', $this->id)
            ->where('status', 'active')
            ->update(['status' => 'released']);
        
        return true;
    }

    /**
     * Put hall under maintenance.
     *
     * @param  string  $reason
     * @param  \DateTime|null  $expectedCompletion
     * @return bool
     */
    public function putUnderMaintenance($reason, $expectedCompletion = null)
    {
        if ($this->status === 'maintenance') {
            throw new \Exception('Hall is already under maintenance');
        }
        
        $this->status = 'maintenance';
        $this->save();
        
        // Create maintenance record
        ExamHallMaintenance::create([
            'hall_id' => $this->id,
            'reason' => $reason,
            'start_date' => now(),
            'expected_completion' => $expectedCompletion,
            'reported_by' => Auth::id(),
            'status' => 'in_progress'
        ]);
        
        return true;
    }

        /**
     * Complete maintenance.
     *
     * @param  string|null  $notes
     * @return bool
     */
    public function completeMaintenance($notes = null)
    {
        if ($this->status !== 'maintenance') {
            throw new \Exception('Hall is not under maintenance');
        }
        
        $this->status = 'available';
        $this->save();
        
        // Update maintenance record
        $maintenance = ExamHallMaintenance::where('hall_id', $this->id)
            ->where('status', 'in_progress')
            ->latest()
            ->first();
        
        if ($maintenance) {
            $maintenance->update([
                'completion_date' => now(),
                'notes' => $notes,
                'completed_by' => Auth::id(),
                'status' => 'completed'
            ]);
        }
        
        return true;
    }

    /**
     * Update seat arrangement.
     *
     * @param  array  $arrangement
     * @return bool
     */
    public function updateSeatArrangement(array $arrangement)
    {
        // Validate arrangement structure
        if (!self::validateSeatArrangement($arrangement, $this->rows, $this->columns)) {
            throw new \Exception('Invalid seat arrangement structure');
        }
        
        $this->seat_arrangement = $arrangement;
        $this->save();
        
        // Update capacity if arrangement indicates special seats
        $this->recalculateCapacityFromArrangement();
        
        return true;
    }

    /**
     * Recalculate capacity from seat arrangement.
     *
     * @return void
     */
    protected function recalculateCapacityFromArrangement()
    {
        if (!$this->seat_arrangement) {
            return;
        }
        
        $totalSeats = 0;
        $specialNeedsSeats = 0;
        
        foreach ($this->seat_arrangement as $row) {
            foreach ($row as $seat) {
                if ($seat['status'] === 'available' || $seat['status'] === 'special_needs') {
                    $totalSeats++;
                    if ($seat['status'] === 'special_needs') {
                        $specialNeedsSeats++;
                    }
                }
            }
        }
        
        $this->capacity = $totalSeats;
        $this->special_needs_capacity = $specialNeedsSeats;
        $this->available_seats = $totalSeats - $this->getOccupiedSeatsCount();
        $this->save();
    }

    /**
     * Get occupied seats count.
     *
     * @return int
     */
    public function getOccupiedSeatsCount()
    {
        return $this->seatAllocations()
            ->where('status', 'occupied')
            ->count();
    }

    /**
     * Allocate seats to students.
     *
     * @param  array  $studentIds
     * @param  int  $allocationId
     * @param  array  $options
     * @return array
     */
    public function allocateSeats(array $studentIds, int $allocationId, array $options = [])
    {
        if (count($studentIds) > $this->available_seats) {
            throw new \Exception('Not enough available seats in the hall');
        }
        
        $allocations = [];
        $seats = $this->getAvailableSeats(count($studentIds), $options);
        
        foreach ($studentIds as $index => $studentId) {
            $seat = $seats[$index] ?? null;
            if (!$seat) {
                continue;
            }
            
            $allocation = ExamSeatAllocation::create([
                'hall_allocation_id' => $allocationId,
                'student_id' => $studentId,
                'hall_id' => $this->id,
                'seat_row' => $seat['row'],
                'seat_column' => $seat['column'],
                'seat_number' => $seat['seat_number'] ?? null,
                'is_special_needs' => $seat['is_special_needs'] ?? false,
                'status' => 'occupied',
                'allocated_by' => Auth::id(),
                'allocated_at' => now()
            ]);
            
            $allocations[] = $allocation;
        }
        
        $this->available_seats -= count($allocations);
        $this->save();
        
        return $allocations;
    }

    /**
     * Get available seats.
     *
     * @param  int  $requiredSeats
     * @param  array  $options
     * @return array
     */
    public function getAvailableSeats(int $requiredSeats = 1, array $options = [])
    {
        $query = ExamSeatAllocation::where('hall_id', $this->id)
            ->where('status', 'occupied')
            ->select(['seat_row', 'seat_column']);
        
        $occupiedSeats = $query->get()
            ->pluck('seat_row', 'seat_column')
            ->toArray();
        
        $availableSeats = [];
        $specialNeedsRequested = $options['special_needs'] ?? false;
        $preferredRows = $options['preferred_rows'] ?? [];
        
        for ($row = 1; $row <= $this->rows; $row++) {
            for ($col = 1; $col <= $this->columns; $col++) {
                // Check if seat is occupied
                if (isset($occupiedSeats[$col]) && $occupiedSeats[$col] == $row) {
                    continue;
                }
                
                // Check if seat is special needs
                $seatInfo = $this->getSeatInfo($row, $col);
                $isSpecialNeeds = $seatInfo['is_special_needs'] ?? false;
                
                if ($specialNeedsRequested && !$isSpecialNeeds) {
                    continue;
                }
                
                // Check preferred rows
                if (!empty($preferredRows) && !in_array($row, $preferredRows)) {
                    continue;
                }
                
                $availableSeats[] = [
                    'row' => $row,
                    'column' => $col,
                    'seat_number' => $this->generateSeatNumber($row, $col),
                    'is_special_needs' => $isSpecialNeeds
                ];
                
                if (count($availableSeats) >= $requiredSeats) {
                    return $availableSeats;
                }
            }
        }
        
        return $availableSeats;
    }

    /**
     * Get seat information.
     *
     * @param  int  $row
     * @param  int  $column
     * @return array|null
     */
    public function getSeatInfo(int $row, int $column)
    {
        if (!$this->seat_arrangement) {
            return null;
        }
        
        if (isset($this->seat_arrangement[$row - 1][$column - 1])) {
            return $this->seat_arrangement[$row - 1][$column - 1];
        }
        
        return null;
    }

    /**
     * Generate seat number.
     *
     * @param  int  $row
     * @param  int  $column
     * @return string
     */
    protected function generateSeatNumber(int $row, int $column)
    {
        $rowLetter = chr(64 + $row); // A, B, C, etc.
        return "{$rowLetter}{$column}";
    }

    /**
     * Release allocated seats.
     *
     * @param  array  $allocationIds
     * @return int
     */
    public function releaseSeats(array $allocationIds)
    {
        $released = ExamSeatAllocation::whereIn('id', $allocationIds)
            ->where('hall_id', $this->id)
            ->where('status', 'occupied')
            ->update([
                'status' => 'released',
                'released_at' => now(),
                'released_by' => Auth::id()
            ]);
        
        $this->available_seats += $released;
        $this->save();
        
        return $released;
    }

    /**
     * Check if hall has available capacity.
     *
     * @param  int  $requiredSeats
     * @param  bool  $specialNeeds
     * @return bool
     */
    public function hasAvailableCapacity(int $requiredSeats = 1, bool $specialNeeds = false)
    {
        if ($specialNeeds && $requiredSeats > $this->getAvailableSpecialNeedsSeats()) {
            return false;
        }
        
        return $this->available_seats >= $requiredSeats;
    }

    /**
     * Get available special needs seats.
     *
     * @return int
     */
    public function getAvailableSpecialNeedsSeats()
    {
        $occupiedSpecialNeeds = $this->seatAllocations()
            ->where('status', 'occupied')
            ->where('is_special_needs', true)
            ->count();
        
        return $this->special_needs_capacity - $occupiedSpecialNeeds;
    }

    /**
     * Get hall schedule for a date range.
     *
     * @param  string  $startDate
     * @param  string  $endDate
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getSchedule($startDate, $endDate)
    {
        return $this->allocations()
            ->whereBetween('exam_date', [$startDate, $endDate])
            ->with(['exam', 'allocatedBy'])
            ->orderBy('exam_date')
            ->orderBy('start_time')
            ->get();
    }

    /**
     * Check if hall is free for a time slot.
     *
     * @param  string  $date
     * @param  string  $startTime
     * @param  string  $endTime
     * @param  int|null  $excludeAllocationId
     * @return bool
     */
    public function isFreeForTimeSlot($date, $startTime, $endTime, $excludeAllocationId = null)
    {
        $query = $this->allocations()
            ->where('exam_date', $date)
            ->where(function ($q) use ($startTime, $endTime) {
                $q->where(function ($q2) use ($startTime, $endTime) {
                    $q2->where('start_time', '<=', $startTime)
                       ->where('end_time', '>=', $startTime);
                })->orWhere(function ($q2) use ($startTime, $endTime) {
                    $q2->where('start_time', '<=', $endTime)
                       ->where('end_time', '>=', $endTime);
                })->orWhere(function ($q2) use ($startTime, $endTime) {
                    $q2->where('start_time', '>=', $startTime)
                       ->where('end_time', '<=', $endTime);
                });
            });
        
        if ($excludeAllocationId) {
            $query->where('id', '!=', $excludeAllocationId);
        }
        
        return $query->count() === 0;
    }

    /**
     * Generate hall code.
     *
     * @param  \App\Models\ExamHall  $hall
     * @return string
     */
    public static function generateHallCode($hall)
    {
        $buildingCode = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $hall->building), 0, 3));
        $buildingCode = $buildingCode ?: 'BLD';
        
        $year = date('y');
        $sequence = self::withTrashed()
            ->where('code', 'LIKE', "{$buildingCode}{$year}%")
            ->count() + 1;
        
        return sprintf('%s%s%03d', $buildingCode, $year, $sequence);
    }

    /**
     * Validate hall data.
     *
     * @param  \App\Models\ExamHall  $hall
     * @return void
     * @throws \Exception
     */
    protected static function validateHall($hall)
    {
        if ($hall->capacity < 1) {
            throw new \Exception('Hall capacity must be at least 1');
        }
        
        if ($hall->rows && $hall->columns) {
            $maxCapacity = $hall->rows * $hall->columns;
            if ($hall->capacity > $maxCapacity) {
                throw new \Exception("Capacity exceeds maximum possible seats based on rows and columns (max: {$maxCapacity})");
            }
        }
        
        if ($hall->special_needs_capacity > $hall->capacity) {
            throw new \Exception('Special needs capacity cannot exceed total capacity');
        }
        
        if ($hall->available_seats > $hall->capacity) {
            throw new \Exception('Available seats cannot exceed total capacity');
        }
        
        // Validate status
        $validStatuses = ['available', 'occupied', 'maintenance', 'reserved', 'closed'];
        if (!in_array($hall->status, $validStatuses)) {
            throw new \Exception('Invalid hall status');
        }
    }

    /**
     * Validate seat arrangement.
     *
     * @param  array  $arrangement
     * @param  int  $rows
     * @param  int  $columns
     * @return bool
     */
    public static function validateSeatArrangement(array $arrangement, int $rows, int $columns)
    {
        if (count($arrangement) !== $rows) {
            return false;
        }
        
        foreach ($arrangement as $row) {
            if (!is_array($row) || count($row) !== $columns) {
                return false;
            }
            
            foreach ($row as $seat) {
                if (!is_array($seat) || !isset($seat['status'])) {
                    return false;
                }
                
                $validStatuses = ['available', 'unavailable', 'special_needs', 'blocked'];
                if (!in_array($seat['status'], $validStatuses)) {
                    return false;
                }
            }
        }
        
        return true;
    }

    /**
     * Create default seat arrangement.
     *
     * @param  int  $rows
     * @param  int  $columns
     * @param  int  $specialNeedsSeats
     * @return array
     */
    public static function createDefaultSeatArrangement(int $rows, int $columns, int $specialNeedsSeats = 0)
    {
        $arrangement = [];
        $specialNeedsAllocated = 0;
        
        for ($row = 0; $row < $rows; $row++) {
            $arrangement[$row] = [];
            for ($col = 0; $col < $columns; $col++) {
                $status = 'available';
                
                // Allocate special needs seats (preferably in front rows)
                if ($specialNeedsAllocated < $specialNeedsSeats && $row < 2) {
                    $status = 'special_needs';
                    $specialNeedsAllocated++;
                }
                
                $arrangement[$row][$col] = [
                    'status' => $status,
                    'row' => $row + 1,
                    'column' => $col + 1,
                    'seat_number' => chr(65 + $row) . ($col + 1),
                    'is_aisle' => $col === 0 || $col === $columns - 1
                ];
            }
        }
        
        return $arrangement;
    }

    /**
     * Get halls with available capacity.
     *
     * @param  int  $requiredSeats
     * @param  bool  $specialNeeds
     * @param  string|null  $building
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getAvailableHalls($requiredSeats = 1, $specialNeeds = false, $building = null)
    {
        $cacheKey = "available_halls_{$requiredSeats}_" . ($specialNeeds ? 'sn' : 'reg') . "_{$building}";
        
        return Cache::remember($cacheKey, 300, function () use ($requiredSeats, $specialNeeds, $building) {
            $query = self::where('is_active', true)
                ->where('status', 'available')
                ->where('available_seats', '>=', $requiredSeats);
            
            if ($specialNeeds) {
                $query->where('has_special_needs_access', true)
                    ->whereColumn('special_needs_capacity', '>=', DB::raw($requiredSeats));
            }
            
            if ($building) {
                $query->where('building', $building);
            }
            
            return $query->orderBy('building')
                ->orderBy('floor')
                ->orderBy('room_number')
                ->get();
        });
    }

    /**
     * Get hall utilization statistics.
     *
     * @param  string  $startDate
     * @param  string  $endDate
     * @return array
     */
    public static function getUtilizationStatistics($startDate, $endDate)
    {
        $halls = self::with(['allocations' => function ($query) use ($startDate, $endDate) {
            $query->whereBetween('exam_date', [$startDate, $endDate]);
        }])->get();
        
        $statistics = [
            'total_halls' => 0,
            'available_halls' => 0,
            'occupied_halls' => 0,
            'total_capacity' => 0,
            'total_allocations' => 0,
            'average_occupancy_rate' => 0,
            'halls_by_building' => [],
            'halls_by_status' => []
        ];
        
        foreach ($halls as $hall) {
            $statistics['total_halls']++;
            
            if ($hall->is_available) {
                $statistics['available_halls']++;
            } else {
                $statistics['occupied_halls']++;
            }
            
            $statistics['total_capacity'] += $hall->capacity;
            $statistics['total_allocations'] += $hall->allocations->count();
            $statistics['average_occupancy_rate'] += $hall->occupancy_rate;
            
            // Group by building
            $building = $hall->building ?: 'Unknown';
            if (!isset($statistics['halls_by_building'][$building])) {
                $statistics['halls_by_building'][$building] = 0;
            }
            $statistics['halls_by_building'][$building]++;
            
            // Group by status
            $status = $hall->status;
            if (!isset($statistics['halls_by_status'][$status])) {
                $statistics['halls_by_status'][$status] = 0;
            }
            $statistics['halls_by_status'][$status]++;
        }
        
        if ($statistics['total_halls'] > 0) {
            $statistics['average_occupancy_rate'] = round(
                $statistics['average_occupancy_rate'] / $statistics['total_halls'], 
                2
            );
        }
        
        return $statistics;
    }

    /**
     * Scope a query to only include available halls.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAvailable($query)
    {
        return $query->where('is_active', true)
                    ->where('status', 'available');
    }

    /**
     * Scope a query to only include halls in a specific building.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $building
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInBuilding($query, $building)
    {
        return $query->where('building', $building);
    }

    /**
     * Scope a query to only include halls with minimum capacity.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $capacity
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithMinCapacity($query, $capacity)
    {
        return $query->where('capacity', '>=', $capacity);
    }

    /**
     * Scope a query to only include halls with special needs access.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithSpecialNeedsAccess($query)
    {
        return $query->where('has_special_needs_access', true);
    }

    /**
     * Scope a query to search halls.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $search
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'LIKE', "%{$search}%")
              ->orWhere('code', 'LIKE', "%{$search}%")
              ->orWhere('building', 'LIKE', "%{$search}%")
              ->orWhere('room_number', 'LIKE', "%{$search}%");
        });
    }

    /**
     * Get hall summary for dashboard.
     *
     * @return array
     */
    public function getSummary()
    {
        $today = now()->toDateString();
        $weekStart = now()->startOfWeek()->toDateString();
        $weekEnd = now()->endOfWeek()->toDateString();
        
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->full_name,
            'capacity' => $this->capacity,
            'available_seats' => $this->available_seats,
            'occupancy_rate' => $this->occupancy_rate,
            'status' => $this->status,
            'status_display' => $this->status_display,
            'is_available' => $this->is_available,
            'location' => $this->location,
            'facilities' => $this->facilities_list,
            'allocations_today' => $this->allocations()->where('exam_date', $today)->count(),
            'allocations_this_week' => $this->allocations()
                ->whereBetween('exam_date', [$weekStart, $weekEnd])
                ->count(),
            'special_needs_capacity' => $this->special_needs_capacity,
            'in_charge' => $this->inCharge ? $this->inCharge->name : null,
            'assistant_in_charge' => $this->assistantInCharge ? $this->assistantInCharge->name : null
        ];
    }

    /**
     * Export hall data for reporting.
     *
     * @return array
     */
    public function exportData()
    {
        return [
            'Hall ID' => $this->id,
            'Hall Code' => $this->code,
            'Hall Name' => $this->name,
            'Building' => $this->building,
            'Floor' => $this->floor,
            'Room Number' => $this->room_number,
            'Capacity' => $this->capacity,
            'Available Seats' => $this->available_seats,
            'Rows' => $this->rows,
            'Columns' => $this->columns,
            'Special Needs Capacity' => $this->special_needs_capacity,
            'Has Special Needs Access' => $this->has_special_needs_access ? 'Yes' : 'No',
            'Is Air Conditioned' => $this->is_air_conditioned ? 'Yes' : 'No',
            'Has Projector' => $this->has_projector ? 'Yes' : 'No',
            'Has Sound System' => $this->has_sound_system ? 'Yes' : 'No',
            'Facilities' => implode(', ', $this->facilities_list),
            'Status' => $this->status_display,
            'Is Active' => $this->is_active ? 'Yes' : 'No',
            'Occupancy Rate' => $this->occupancy_rate . '%',
            'In Charge' => $this->inCharge ? $this->inCharge->name : 'Not Assigned',
            'Assistant In Charge' => $this->assistantInCharge ? $this->assistantInCharge->name : 'Not Assigned',
            'Created At' => $this->created_at->format('Y-m-d H:i:s'),
            'Last Updated' => $this->updated_at->format('Y-m-d H:i:s')
        ];
    }
}