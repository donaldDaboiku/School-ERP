<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;        

class Classroom extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'classrooms';

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
        'room_type',
        'capacity',
        'available_seats',
        'area_sqft',
        'facilities',
        'equipment',
        'is_air_conditioned',
        'has_projector',
        'has_smart_board',
        'has_computers',
        'computer_count',
        'has_lab_equipment',
        'lab_type',
        'has_internet',
        'internet_speed',
        'security_features',
        'is_wheelchair_accessible',
        'status',
        'is_reservable',
        'max_reservation_hours',
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
        'area_sqft' => 'decimal:2',
        'facilities' => 'json',
        'equipment' => 'json',
        'is_air_conditioned' => 'boolean',
        'has_projector' => 'boolean',
        'has_smart_board' => 'boolean',
        'has_computers' => 'boolean',
        'computer_count' => 'integer',
        'has_lab_equipment' => 'boolean',
        'has_internet' => 'boolean',
        'internet_speed' => 'decimal:2',
        'is_wheelchair_accessible' => 'boolean',
        'is_reservable' => 'boolean',
        'max_reservation_hours' => 'integer',
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
        'room_type_display',
        'occupancy_rate',
        'is_available',
        'facilities_list',
        'equipment_list',
        'security_features_list',
        'location',
        'current_occupancy'
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $with = ['inCharge', 'assistantInCharge', 'creator', 'updater'];

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($classroom) {
            // Generate classroom code if not provided
            if (empty($classroom->code)) {
                $classroom->code = self::generateClassroomCode($classroom);
            }
            
            // Set available seats equal to capacity
            if (empty($classroom->available_seats)) {
                $classroom->available_seats = $classroom->capacity;
            }
            
            // Set default status
            if (empty($classroom->status)) {
                $classroom->status = 'available';
            }
            
            // Set default room_type
            if (empty($classroom->room_type)) {
                $classroom->room_type = 'classroom';
            }
            
            // Set default is_reservable
            if (is_null($classroom->is_reservable)) {
                $classroom->is_reservable = true;
            }
            
            // Set created_by if not set
            if (empty($classroom->created_by) && Auth::check()) {
                $classroom->created_by = Auth::id();
            }
            
            // Validate classroom
            self::validateClassroom($classroom);
            
            Log::info('Classroom creating', [
                'name' => $classroom->name,
                'code' => $classroom->code,
                'room_type' => $classroom->room_type,
                'capacity' => $classroom->capacity,
                'created_by' => $classroom->created_by
            ]);
        });

        static::updating(function ($classroom) {
            // Update available seats if capacity changes
            if ($classroom->isDirty('capacity')) {
                $classroom->available_seats = $classroom->capacity;
            }
            
            // Update updated_by
            if (Auth::check()) {
                $classroom->updated_by = Auth::id();
            }
            
            // Validate classroom on update
            self::validateClassroom($classroom);
            
            // Prevent status change if classroom is currently occupied
            if ($classroom->isDirty('status') && $classroom->status !== 'available') {
                $currentOccupancy = $classroom->getCurrentOccupancy();
                if ($currentOccupancy > 0) {
                    throw new \Exception("Cannot change status while classroom has {$currentOccupancy} active sessions");
                }
            }
        });

        static::saved(function ($classroom) {
            // Clear relevant cache
            Cache::forget("classroom_{$classroom->id}");
            Cache::forget("classroom_code_{$classroom->code}");
            Cache::tags([
                "classrooms_building_{$classroom->building}",
                "classrooms_type_{$classroom->room_type}",
                "classrooms_available",
                "classrooms_reservable"
            ])->flush();
        });

        static::deleted(function ($classroom) {
            // Clear cache
            Cache::forget("classroom_{$classroom->id}");
            Cache::forget("classroom_code_{$classroom->code}");
            Cache::tags([
                "classrooms_building_{$classroom->building}",
                "classrooms_type_{$classroom->room_type}",
                "classrooms_available",
                "classrooms_reservable"
            ])->flush();
        });
    }

    /**
     * Get the in-charge (teacher) for this classroom.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function inCharge()
    {
        return $this->belongsTo(Teacher::class, 'in_charge_id');
    }

    /**
     * Get the assistant in-charge for this classroom.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function assistantInCharge()
    {
        return $this->belongsTo(Teacher::class, 'assistant_in_charge_id');
    }

    /**
     * Get the user who created this classroom.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this classroom.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get the timetable entries for this classroom.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function timetableEntries()
    {
        return $this->hasMany(TimetableEntry::class, 'classroom_id');
    }

    /**
     * Get the reservations for this classroom.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function reservations()
    {
        return $this->hasMany(ClassroomReservation::class, 'classroom_id');
    }

    /**
     * Get the maintenance records for this classroom.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function maintenanceRecords()
    {
        return $this->hasMany(ClassroomMaintenance::class, 'classroom_id');
    }

    /**
     * Get the equipment maintenance records.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function equipmentMaintenance()
    {
        return $this->hasMany(EquipmentMaintenance::class, 'classroom_id');
    }

    /**
     * Get the grade levels assigned to this classroom.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function gradeLevels()
    {
        return $this->belongsToMany(GradeLevel::class, 'classroom_grade_levels', 'classroom_id', 'grade_level_id')
            ->withPivot(['academic_year', 'term'])
            ->withTimestamps();
    }

    /**
     * Get the full classroom name.
     *
     * @return string
     */
    public function getFullNameAttribute()
    {
        $name = $this->name;
        
        if ($this->room_number) {
            $name .= " (Room {$this->room_number})";
        }
        
        if ($this->building) {
            $name .= ", {$this->building}";
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
            'cleaning' => 'Cleaning in Progress',
            'closed' => 'Closed'
        ];
        
        return $statuses[$this->status] ?? ucfirst($this->status);
    }

    /**
     * Get room type display name.
     *
     * @return string
     */
    public function getRoomTypeDisplayAttribute()
    {
        $types = [
            'classroom' => 'Regular Classroom',
            'laboratory' => 'Laboratory',
            'computer_lab' => 'Computer Lab',
            'physics_lab' => 'Physics Laboratory',
            'chemistry_lab' => 'Chemistry Laboratory',
            'biology_lab' => 'Biology Laboratory',
            'art_room' => 'Art Room',
            'music_room' => 'Music Room',
            'library' => 'Library',
            'auditorium' => 'Auditorium',
            'conference_room' => 'Conference Room',
            'staff_room' => 'Staff Room',
            'principal_office' => 'Principal Office',
            'cafeteria' => 'Cafeteria'
        ];
        
        return $types[$this->room_type] ?? ucfirst($this->room_type);
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
     * Check if classroom is available.
     *
     * @return bool
     */
    public function getIsAvailableAttribute()
    {
        return $this->status === 'available' && $this->available_seats > 0;
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
            $facilities[] = 'Air Conditioning';
        }
        
        if ($this->has_projector) {
            $facilities[] = 'Projector';
        }
        
        if ($this->has_smart_board) {
            $facilities[] = 'Smart Board';
        }
        
        if ($this->has_computers && $this->computer_count > 0) {
            $facilities[] = "Computers ({$this->computer_count})";
        }
        
        if ($this->has_internet) {
            $facilities[] = "Internet ({$this->internet_speed} Mbps)";
        }
        
        if ($this->is_wheelchair_accessible) {
            $facilities[] = 'Wheelchair Accessible';
        }
        
        if ($this->facilities && is_array($this->facilities)) {
            $facilities = array_merge($facilities, $this->facilities);
        }
        
        return array_unique($facilities);
    }

    /**
     * Get equipment list.
     *
     * @return array
     */
    public function getEquipmentListAttribute()
    {
        if (!$this->equipment || !is_array($this->equipment)) {
            return [];
        }
        
        $equipmentList = [];
        foreach ($this->equipment as $item) {
            $equipmentList[] = [
                'name' => $item['name'] ?? 'Unknown',
                'quantity' => $item['quantity'] ?? 1,
                'condition' => $item['condition'] ?? 'good',
                'last_maintenance' => isset($item['last_maintenance']) ? \Carbon\Carbon::parse($item['last_maintenance']) : null
            ];
        }
        
        return $equipmentList;
    }

    /**
     * Get security features list.
     *
     * @return array
     */
    public function getSecurityFeaturesListAttribute()
    {
        if (!$this->security_features) {
            return [];
        }
        
        return is_array($this->security_features) ? $this->security_features : [$this->security_features];
    }

    /**
     * Get classroom location.
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
     * Get current occupancy.
     *
     * @return int
     */
    public function getCurrentOccupancyAttribute()
    {
        return $this->getCurrentOccupancy();
    }

    /**
     * Get current occupancy count.
     *
     * @return int
     */
    public function getCurrentOccupancy()
    {
        $now = now();
        $currentDay = $now->format('l');
        $currentTime = $now->format('H:i:s');
        
        // Count active timetable entries
        $activeEntries = $this->timetableEntries()
            ->where('day', $currentDay)
            ->where('status', 'scheduled')
            ->where('start_time', '<=', $currentTime)
            ->where('end_time', '>=', $currentTime)
            ->count();
        
        return $activeEntries;
    }

    /**
     * Check classroom availability for a specific time.
     *
     * @param  string  $day
     * @param  string  $startTime
     * @param  string  $endTime
     * @param  int|null  $excludeEntryId
     * @return array
     */
    public function checkAvailability($day, $startTime, $endTime, $excludeEntryId = null)
    {
        if (!$this->is_available) {
            return [
                'available' => false,
                'reason' => 'Classroom is not available'
            ];
        }
        
        if ($this->status !== 'available') {
            return [
                'available' => false,
                'reason' => "Classroom status is: {$this->status_display}"
            ];
        }
        
        // Check for timetable conflicts
        $conflict = $this->timetableEntries()
            ->where('day', $day)
            ->where('id', '!=', $excludeEntryId)
            ->where(function($query) use ($startTime, $endTime) {
                $query->whereBetween('start_time', [$startTime, $endTime])
                      ->orWhereBetween('end_time', [$startTime, $endTime])
                      ->orWhere(function($q) use ($startTime, $endTime) {
                          $q->where('start_time', '<', $startTime)
                            ->where('end_time', '>', $endTime);
                      });
            })
            ->where('status', '!=', 'cancelled')
            ->first();
        
        if ($conflict) {
            return [
                'available' => false,
                'reason' => 'Classroom already scheduled for this time period',
                'conflict_with' => $conflict
            ];
        }
        
        // Check for reservations
        $reservationConflict = $this->reservations()
            ->where('reservation_date', $day)
            ->where('status', 'approved')
            ->where(function($query) use ($startTime, $endTime) {
                $query->whereBetween('start_time', [$startTime, $endTime])
                      ->orWhereBetween('end_time', [$startTime, $endTime]);
            })
            ->first();
        
        if ($reservationConflict) {
            return [
                'available' => false,
                'reason' => 'Classroom reserved for this time period',
                'reservation' => $reservationConflict
            ];
        }
        
        return [
            'available' => true,
            'available_seats' => $this->available_seats,
            'facilities' => $this->facilities_list
        ];
    }

    /**
     * Reserve classroom for a specific period.
     *
     * @param  User  $requester
     * @param  string  $date
     * @param  string  $startTime
     * @param  string  $endTime
     * @param  string  $purpose
     * @param  string  $eventType
     * @param  int|null  $expectedAttendees
     * @return ClassroomReservation
     */
    public function reserve($requester, $date, $startTime, $endTime, $purpose, $eventType = 'meeting', $expectedAttendees = null)
    {
        if (!$this->is_reservable) {
            throw new \Exception('Classroom is not reservable');
        }
        
        // Check availability
        $availability = $this->checkAvailability($date, $startTime, $endTime);
        if (!$availability['available']) {
            throw new \Exception($availability['reason']);
        }
        
        // Validate reservation duration
        $start = \Carbon\Carbon::parse($startTime);
        $end = \Carbon\Carbon::parse($endTime);
        $durationHours = $start->diffInHours($end);
        
        if ($this->max_reservation_hours && $durationHours > $this->max_reservation_hours) {
            throw new \Exception("Reservation duration exceeds maximum allowed {$this->max_reservation_hours} hours");
        }
        
        // Validate attendees
        if ($expectedAttendees && $expectedAttendees > $this->capacity) {
            throw new \Exception("Expected attendees exceed classroom capacity of {$this->capacity}");
        }
        
        $reservation = ClassroomReservation::create([
            'classroom_id' => $this->id,
            'requester_id' => $requester->id,
            'reservation_date' => $date,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'purpose' => $purpose,
            'event_type' => $eventType,
            'expected_attendees' => $expectedAttendees,
            'status' => 'pending',
            'requested_at' => now()
        ]);
        
        Log::info('Classroom reservation requested', [
            'classroom_id' => $this->id,
            'reservation_id' => $reservation->id,
            'requester_id' => $requester->id,
            'purpose' => $purpose
        ]);
        
        return $reservation;
    }

    /**
     * Report equipment issue.
     *
     * @param  User  $reporter
     * @param  string  $equipmentName
     * @param  string  $issueDescription
     * @param  string  $priority
     * @return EquipmentMaintenance
     */
    public function reportEquipmentIssue($reporter, $equipmentName, $issueDescription, $priority = 'medium')
    {
        $maintenance = EquipmentMaintenance::create([
            'classroom_id' => $this->id,
            'reported_by' => $reporter->id,
            'equipment_name' => $equipmentName,
            'issue_description' => $issueDescription,
            'priority' => $priority,
            'status' => 'reported',
            'reported_at' => now()
        ]);
        
        Log::info('Equipment issue reported', [
            'classroom_id' => $this->id,
            'equipment_name' => $equipmentName,
            'issue_description' => $issueDescription,
            'reported_by' => $reporter->id
        ]);
        
        return $maintenance;
    }

    /**
     * Put classroom under maintenance.
     *
     * @param  User  $reporter
     * @param  string  $reason
     * @param  \DateTime|null  $expectedCompletion
     * @param  string  $maintenanceType
     * @return ClassroomMaintenance
     */
    public function putUnderMaintenance($reporter, $reason, $expectedCompletion = null, $maintenanceType = 'routine')
    {
        if ($this->status === 'maintenance') {
            throw new \Exception('Classroom is already under maintenance');
        }
        
        $oldStatus = $this->status;
        $this->status = 'maintenance';
        $this->save();
        
        $maintenance = ClassroomMaintenance::create([
            'classroom_id' => $this->id,
            'reported_by' => $reporter->id,
            'maintenance_type' => $maintenanceType,
            'reason' => $reason,
            'start_date' => now(),
            'expected_completion' => $expectedCompletion,
            'status' => 'in_progress',
            'notes' => "Previous status: {$oldStatus}"
        ]);
        
        Log::info('Classroom put under maintenance', [
            'classroom_id' => $this->id,
            'maintenance_id' => $maintenance->id,
            'reason' => $reason,
            'reported_by' => $reporter->id
        ]);
        
        return $maintenance;
    }

    /**
     * Complete maintenance.
     *
     * @param  User  $completer
     * @param  string|null  $notes
     * @param  array|null  $workDone
     * @return bool
     */
    public function completeMaintenance($completer, $notes = null, $workDone = null)
    {
        if ($this->status !== 'maintenance') {
            throw new \Exception('Classroom is not under maintenance');
        }
        
        $this->status = 'available';
        $this->save();
        
        // Update maintenance record
        $maintenance = $this->maintenanceRecords()
            ->where('status', 'in_progress')
            ->latest()
            ->first();
            
        if ($maintenance) {
            $maintenance->update([
                'completion_date' => now(),
                'completed_by' => $completer->id,
                'work_done' => $workDone,
                'notes' => ($maintenance->notes ? $maintenance->notes . "\n" : '') . ($notes ?? ''),
                'status' => 'completed'
            ]);
        }
        
        Log::info('Classroom maintenance completed', [
            'classroom_id' => $this->id,
            'completed_by' => $completer->id,
            'work_done' => $workDone
        ]);
        
        return true;
    }

    /**
     * Get classroom statistics.
     *
     * @return array
     */
    public function getStatistics()
    {
        $totalEntries = $this->timetableEntries()->count();
        $completedEntries = $this->timetableEntries()->where('status', 'completed')->count();
        $cancelledEntries = $this->timetableEntries()->where('status', 'cancelled')->count();
        
        $totalReservations = $this->reservations()->count();
        $approvedReservations = $this->reservations()->where('status', 'approved')->count();
        
        $totalMaintenance = $this->maintenanceRecords()->count();
        $activeMaintenance = $this->maintenanceRecords()->where('status', 'in_progress')->count();
        
        // Calculate utilization rate
        $utilizationRate = 0;
        if ($totalEntries > 0) {
            $utilizationRate = round(($completedEntries / $totalEntries) * 100, 2);
        }
        
        // Get usage by day of week
        $usageByDay = $this->timetableEntries()
            ->selectRaw('day, COUNT(*) as count')
            ->groupBy('day')
            ->orderByRaw('FIELD(day, "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday")')
            ->pluck('count', 'day')
            ->toArray();
        
        // Get equipment status
        $equipmentStatus = [];
        foreach ($this->equipment_list as $equipment) {
            $equipmentStatus[] = [
                'name' => $equipment['name'],
                'condition' => $equipment['condition'],
                'quantity' => $equipment['quantity']
            ];
        }
        
        return [
            'total_entries' => $totalEntries,
            'completed_entries' => $completedEntries,
            'cancelled_entries' => $cancelledEntries,
            'total_reservations' => $totalReservations,
            'approved_reservations' => $approvedReservations,
            'total_maintenance' => $totalMaintenance,
            'active_maintenance' => $activeMaintenance,
            'utilization_rate' => $utilizationRate,
            'occupancy_rate' => $this->occupancy_rate,
            'available_seats' => $this->available_seats,
            'current_occupancy' => $this->current_occupancy,
            'usage_by_day' => $usageByDay,
            'equipment_status' => $equipmentStatus,
            'last_maintenance' => $this->maintenanceRecords()->latest()->first()
        ];
    }

    /**
     * Generate classroom code.
     *
     * @param  Classroom  $classroom
     * @return string
     */
    private static function generateClassroomCode($classroom)
    {
        $buildingCode = strtoupper(substr($classroom->building ?: 'MAIN', 0, 3));
        $floorCode = $classroom->floor ? "F{$classroom->floor}" : 'F0';
        $roomCode = $classroom->room_number ? "R{$classroom->room_number}" : 'R000';
        $typeCode = strtoupper(substr($classroom->room_type ?: 'CLASS', 0, 4));
        
        do {
            $random = strtoupper(\Illuminate\Support\Str::random(2));
            $code = "CR{$buildingCode}{$floorCode}{$roomCode}{$typeCode}{$random}";
        } while (self::where('code', $code)->exists());
        
        return $code;
    }

    /**
     * Validate classroom.
     *
     * @param  Classroom  $classroom
     * @return void
     * @throws \Exception
     */
    private static function validateClassroom($classroom)
    {
        // Check if classroom code is unique
        if ($classroom->code) {
            $existingClassroom = self::where('code', $classroom->code)
                ->where('id', '!=', $classroom->id)
                ->first();
                
            if ($existingClassroom) {
                throw new \Exception('Classroom code already exists');
            }
        }
        
        // Validate capacity
        if ($classroom->capacity <= 0) {
            throw new \Exception('Capacity must be greater than 0');
        }
        
        // Validate available seats
        if ($classroom->available_seats > $classroom->capacity) {
            throw new \Exception('Available seats cannot exceed capacity');
        }
        
        // Validate area
        if ($classroom->area_sqft && $classroom->area_sqft <= 0) {
            throw new \Exception('Area must be greater than 0');
        }
        
        // Validate computer count
        if ($classroom->has_computers && (!$classroom->computer_count || $classroom->computer_count <= 0)) {
            throw new \Exception('Computer count must be specified when computers are available');
        }
        
        // Validate internet speed
        if ($classroom->has_internet && (!$classroom->internet_speed || $classroom->internet_speed <= 0)) {
            throw new \Exception('Internet speed must be specified when internet is available');
        }
    }

    /**
     * Get available classrooms.
     *
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getAvailable($filters = [])
    {
        $cacheKey = 'classrooms_available_' . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHour(), function () use ($filters) {
            $query = self::where('status', 'available')
                ->where('available_seats', '>', 0)
                ->orderBy('building')
                ->orderBy('floor')
                ->orderBy('room_number');
            
            // Apply filters
            if (isset($filters['min_capacity'])) {
                $query->where('capacity', '>=', $filters['min_capacity']);
            }
            
            if (isset($filters['building'])) {
                $query->where('building', $filters['building']);
            }
            
            if (isset($filters['room_type'])) {
                $query->where('room_type', $filters['room_type']);
            }
            
            if (isset($filters['has_projector'])) {
                $query->where('has_projector', $filters['has_projector']);
            }
            
            if (isset($filters['has_smart_board'])) {
                $query->where('has_smart_board', $filters['has_smart_board']);
            }
            
            if (isset($filters['is_wheelchair_accessible'])) {
                $query->where('is_wheelchair_accessible', $filters['is_wheelchair_accessible']);
            }
            
            if (isset($filters['has_computers'])) {
                $query->where('has_computers', $filters['has_computers']);
            }
            
            if (isset($filters['is_air_conditioned'])) {
                $query->where('is_air_conditioned', $filters['is_air_conditioned']);
            }
            
            return $query->get();
        });
    }

    /**
     * Get classrooms by type.
     *
     * @param  string  $roomType
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getByType($roomType, $filters = [])
    {
        $cacheKey = "classrooms_type_{$roomType}_" . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHours(6), function () use ($roomType, $filters) {
            $query = self::where('room_type', $roomType)
                ->orderBy('building')
                ->orderBy('floor')
                ->orderBy('room_number');
            
            // Apply filters
            if (isset($filters['building'])) {
                $query->where('building', $filters['building']);
            }
            
            if (isset($filters['is_available'])) {
                if ($filters['is_available']) {
                    $query->where('status', 'available')
                          ->where('available_seats', '>', 0);
                }
            }
            
            if (isset($filters['min_capacity'])) {
                $query->where('capacity', '>=', $filters['min_capacity']);
            }
            
            return $query->get();
        });
    }

    /**
     * Get classroom by code.
     *
     * @param  string  $code
     * @return Classroom|null
     */
    public static function getByCode($code)
    {
        return Cache::remember("classroom_code_{$code}", now()->addHours(12), function () use ($code) {
            return self::where('code', $code)->first();
        });
    }

    /**
     * Import classroom from CSV data.
     *
     * @param  array  $data
     * @param  User  $importer
     * @return Classroom
     */
    public static function importFromCSV($data, $importer)
    {
        $classroom = new self($data);
        $classroom->created_by = $importer->id;
        $classroom->save();
        
        Log::info('Classroom imported from CSV', [
            'classroom_id' => $classroom->id,
            'classroom_name' => $classroom->name,
            'importer_id' => $importer->id
        ]);
        
        return $classroom;
    }

    /**
     * Export classroom data to array.
     *
     * @return array
     */
    public function exportToArray()
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'building' => $this->building,
            'floor' => $this->floor,
            'room_number' => $this->room_number,
            'room_type' => $this->room_type,
            'room_type_display' => $this->room_type_display,
            'capacity' => $this->capacity,
            'available_seats' => $this->available_seats,
            'area_sqft' => $this->area_sqft,
            'facilities' => $this->facilities_list,
            'equipment' => $this->equipment_list,
            'is_air_conditioned' => $this->is_air_conditioned,
            'has_projector' => $this->has_projector,
            'has_smart_board' => $this->has_smart_board,
            'has_computers' => $this->has_computers,
            'computer_count' => $this->computer_count,
            'has_internet' => $this->has_internet,
            'internet_speed' => $this->internet_speed,
            'is_wheelchair_accessible' => $this->is_wheelchair_accessible,
            'security_features' => $this->security_features_list,
            'status' => $this->status,
            'status_display' => $this->status_display,
            'is_reservable' => $this->is_reservable,
            'max_reservation_hours' => $this->max_reservation_hours,
            'in_charge' => $this->inCharge ? $this->inCharge->name : null,
            'assistant_in_charge' => $this->assistantInCharge ? $this->assistantInCharge->name : null,
            'occupancy_rate' => $this->occupancy_rate,
            'current_occupancy' => $this->current_occupancy,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at
        ];
    }

    /**
     * Scope a query to only include available classrooms.
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
     * Scope a query to only include reservable classrooms.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeReservable($query)
    {
        return $query->where('is_reservable', true);
    }

    /**
     * Scope a query to only include classrooms in a specific building.
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
     * Scope a query to only include classrooms of a specific type.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $roomType
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfType($query, $roomType)
    {
        return $query->where('room_type', $roomType);
    }

    /**
     * Scope a query to only include classrooms with minimum capacity.
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
     * Scope a query to only include classrooms with specific facilities.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $facility
     * @param  bool  $hasFacility
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithFacility($query, $facility, $hasFacility = true)
    {
        switch ($facility) {
            case 'projector':
                return $query->where('has_projector', $hasFacility);
            case 'smart_board':
                return $query->where('has_smart_board', $hasFacility);
            case 'air_conditioning':
                return $query->where('is_air_conditioned', $hasFacility);
            case 'computers':
                return $query->where('has_computers', $hasFacility);
            case 'internet':
                return $query->where('has_internet', $hasFacility);
            case 'wheelchair_access':
                return $query->where('is_wheelchair_accessible', $hasFacility);
            default:
                return $query;
        }
    }
}