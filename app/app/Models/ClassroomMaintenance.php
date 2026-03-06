<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;    

class ClassroomMaintenance extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'classroom_maintenances';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'classroom_id',
        'maintenance_type',
        'priority',
        'reason',
        'description',
        'reported_by',
        'reported_at',
        'assigned_to',
        'assigned_at',
        'estimated_hours',
        'estimated_cost',
        'budget_approved',
        'approval_date',
        'approval_by',
        'start_date',
        'expected_completion',
        'actual_completion',
        'completed_by',
        'work_done',
        'materials_used',
        'labor_cost',
        'material_cost',
        'total_cost',
        'before_images',
        'after_images',
        'status',
        'notes',
        'created_by',
        'updated_by'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'estimated_hours' => 'decimal:2',
        'estimated_cost' => 'decimal:2',
        'budget_approved' => 'boolean',
        'approval_date' => 'datetime',
        'reported_at' => 'datetime',
        'assigned_at' => 'datetime',
        'start_date' => 'datetime',
        'expected_completion' => 'datetime',
        'actual_completion' => 'datetime',
        'labor_cost' => 'decimal:2',
        'material_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'before_images' => 'json',
        'after_images' => 'json',
        'work_done' => 'json',
        'materials_used' => 'json',
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
        'priority_display',
        'maintenance_type_display',
        'duration_days',
        'is_overdue',
        'estimated_completion_date',
        'reporter_name',
        'assignee_name',
        'completer_name',
        'approver_name',
        'cost_breakdown'
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $with = ['classroom', 'reporter', 'assignee', 'completer', 'approver', 'creator', 'updater'];

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($maintenance) {
            // Set default status
            if (empty($maintenance->status)) {
                $maintenance->status = 'reported';
            }
            
            // Set reported_at if not set
            if (empty($maintenance->reported_at)) {
                $maintenance->reported_at = now();
            }
            
            // Set priority if not set
            if (empty($maintenance->priority)) {
                $maintenance->priority = 'medium';
            }
            
            // Set created_by if not set
            if (empty($maintenance->created_by) && Auth::check()) {
                $maintenance->created_by = Auth::id();
            }
            
            // Validate maintenance
            self::validateMaintenance($maintenance);
            
            Log::info('Classroom maintenance creating', [
                'classroom_id' => $maintenance->classroom_id,
                'maintenance_type' => $maintenance->maintenance_type,
                'priority' => $maintenance->priority,
                'reported_by' => $maintenance->reported_by,
                'created_by' => $maintenance->created_by
            ]);
        });

        static::updating(function ($maintenance) {
            // Update updated_by
            if (Auth::check()) {
                $maintenance->updated_by = Auth::id();
            }
            
            // Set assigned_at if being assigned
            if ($maintenance->isDirty('assigned_to') && $maintenance->assigned_to) {
                $maintenance->assigned_at = now();
                $maintenance->status = 'assigned';
            }
            
            // Set start_date if starting work
            if ($maintenance->isDirty('status') && $maintenance->status === 'in_progress') {
                if (empty($maintenance->start_date)) {
                    $maintenance->start_date = now();
                }
            }
            
            // Set actual_completion if completing
            if ($maintenance->isDirty('status') && $maintenance->status === 'completed') {
                if (empty($maintenance->actual_completion)) {
                    $maintenance->actual_completion = now();
                }
            }
            
            // Calculate total cost if labor or material cost changes
            if ($maintenance->isDirty('labor_cost') || $maintenance->isDirty('material_cost')) {
                $maintenance->total_cost = ($maintenance->labor_cost ?? 0) + ($maintenance->material_cost ?? 0);
            }
            
            // Validate maintenance on update
            self::validateMaintenance($maintenance);
        });

        static::saved(function ($maintenance) {
            // Clear relevant cache
            Cache::forget("classroom_maintenance_{$maintenance->id}");
            Cache::tags([
                "classroom_maintenances_classroom_{$maintenance->classroom_id}",
                "classroom_maintenances_status_{$maintenance->status}",
                "classroom_maintenances_assigned_{$maintenance->assigned_to}"
            ])->flush();
            
            // Update classroom status if needed
            $maintenance->updateClassroomStatus();
        });

        static::deleted(function ($maintenance) {
            // Clear cache
            Cache::forget("classroom_maintenance_{$maintenance->id}");
            Cache::tags([
                "classroom_maintenances_classroom_{$maintenance->classroom_id}",
                "classroom_maintenances_status_{$maintenance->status}",
                "classroom_maintenances_assigned_{$maintenance->assigned_to}"
            ])->flush();
        });
    }

    /**
     * Get the classroom for this maintenance.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function classroom()
    {
        return $this->belongsTo(Classroom::class, 'classroom_id');
    }

    /**
     * Get the user who reported this maintenance.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /**
     * Get the user assigned to this maintenance.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Get the user who completed this maintenance.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function completer()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * Get the user who approved the budget.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function approver()
    {
        return $this->belongsTo(User::class, 'approval_by');
    }

    /**
     * Get the user who created this record.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this record.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get the maintenance parts used.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function parts()
    {
        return $this->hasMany(MaintenancePart::class, 'maintenance_id');
    }

    /**
     * Get status display name.
     *
     * @return string
     */
    public function getStatusDisplayAttribute()
    {
        $statuses = [
            'reported' => 'Reported',
            'assigned' => 'Assigned',
            'in_progress' => 'In Progress',
            'on_hold' => 'On Hold',
            'awaiting_parts' => 'Awaiting Parts',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'rejected' => 'Rejected'
        ];
        
        return $statuses[$this->status] ?? ucfirst($this->status);
    }

    /**
     * Get priority display name.
     *
     * @return string
     */
    public function getPriorityDisplayAttribute()
    {
        $priorities = [
            'low' => 'Low',
            'medium' => 'Medium',
            'high' => 'High',
            'urgent' => 'Urgent',
            'emergency' => 'Emergency'
        ];
        
        return $priorities[$this->priority] ?? ucfirst($this->priority);
    }

    /**
     * Get maintenance type display name.
     *
     * @return string
     */
    public function getMaintenanceTypeDisplayAttribute()
    {
        $types = [
            'routine' => 'Routine Maintenance',
            'preventive' => 'Preventive Maintenance',
            'corrective' => 'Corrective Maintenance',
            'emergency' => 'Emergency Repair',
            'renovation' => 'Renovation',
            'deep_cleaning' => 'Deep Cleaning',
            'equipment' => 'Equipment Repair',
            'electrical' => 'Electrical Work',
            'plumbing' => 'Plumbing Work',
            'carpentry' => 'Carpentry Work',
            'painting' => 'Painting',
            'other' => 'Other'
        ];
        
        return $types[$this->maintenance_type] ?? ucfirst($this->maintenance_type);
    }

    /**
     * Get duration in days.
     *
     * @return int|null
     */
    public function getDurationDaysAttribute()
    {
        if (!$this->start_date || !$this->actual_completion) {
            return null;
        }
        
        return $this->start_date->diffInDays($this->actual_completion);
    }

    /**
     * Check if maintenance is overdue.
     *
     * @return bool
     */
    public function getIsOverdueAttribute()
    {
        if ($this->status !== 'in_progress' || !$this->expected_completion) {
            return false;
        }
        
        return now()->gt($this->expected_completion);
    }

    /**
     * Get estimated completion date.
     *
     * @return string|null
     */
    public function getEstimatedCompletionDateAttribute()
    {
        if (!$this->expected_completion) {
            return null;
        }
        
        return $this->expected_completion->format('M d, Y');
    }

    /**
     * Get reporter name.
     *
     * @return string|null
     */
    public function getReporterNameAttribute()
    {
        return $this->reporter ? $this->reporter->name : null;
    }

    /**
     * Get assignee name.
     *
     * @return string|null
     */
    public function getAssigneeNameAttribute()
    {
        return $this->assignee ? $this->assignee->name : null;
    }

    /**
     * Get completer name.
     *
     * @return string|null
     */
    public function getCompleterNameAttribute()
    {
        return $this->completer ? $this->completer->name : null;
    }

    /**
     * Get approver name.
     *
     * @return string|null
     */
    public function getApproverNameAttribute()
    {
        return $this->approver ? $this->approver->name : null;
    }

    /**
     * Get cost breakdown.
     *
     * @return array
     */
    public function getCostBreakdownAttribute()
    {
        return [
            'labor_cost' => $this->labor_cost ?? 0,
            'material_cost' => $this->material_cost ?? 0,
            'total_cost' => $this->total_cost ?? 0,
            'estimated_cost' => $this->estimated_cost ?? 0,
            'cost_variance' => $this->total_cost - $this->estimated_cost
        ];
    }

    /**
     * Assign maintenance to a technician.
     *
     * @param  User  $technician
     * @return bool
     */
    public function assignTo($technician)
    {
        if ($this->status === 'assigned' || $this->status === 'in_progress') {
            throw new \Exception('Maintenance is already assigned or in progress');
        }
        
        $this->assigned_to = $technician->id;
        $this->assigned_at = now();
        $this->status = 'assigned';
        $this->save();
        
        Log::info('Maintenance assigned to technician', [
            'maintenance_id' => $this->id,
            'technician_id' => $technician->id,
            'classroom_id' => $this->classroom_id,
            'assigned_by' => Auth::id()
        ]);
        
        return true;
    }

    /**
     * Start maintenance work.
     *
     * @return bool
     */
    public function startWork()
    {
        if ($this->status !== 'assigned') {
            throw new \Exception('Maintenance must be assigned before starting work');
        }
        
        $this->status = 'in_progress';
        $this->start_date = now();
        $this->save();
        
        Log::info('Maintenance work started', [
            'maintenance_id' => $this->id,
            'classroom_id' => $this->classroom_id,
            'started_by' => Auth::id()
        ]);
        
        return true;
    }

    /**
     * Put maintenance on hold.
     *
     * @param  string  $reason
     * @return bool
     */
    public function putOnHold($reason)
    {
        if ($this->status !== 'in_progress') {
            throw new \Exception('Only maintenance in progress can be put on hold');
        }
        
        $this->status = 'on_hold';
        $this->notes = ($this->notes ? $this->notes . "\n" : '') . "Put on hold: {$reason}";
        $this->save();
        
        Log::info('Maintenance put on hold', [
            'maintenance_id' => $this->id,
            'classroom_id' => $this->classroom_id,
            'reason' => $reason,
            'action_by' => Auth::id()
        ]);
        
        return true;
    }

    /**
     * Mark as awaiting parts.
     *
     * @param  array  $partsNeeded
     * @return bool
     */
    public function markAwaitingParts($partsNeeded)
    {
        if ($this->status !== 'in_progress' && $this->status !== 'assigned') {
            throw new \Exception('Maintenance must be in progress or assigned');
        }
        
        $this->status = 'awaiting_parts';
        $this->notes = ($this->notes ? $this->notes . "\n" : '') . "Awaiting parts: " . implode(', ', $partsNeeded);
        $this->save();
        
        // Create part requests
        foreach ($partsNeeded as $part) {
            MaintenancePart::create([
                'maintenance_id' => $this->id,
                'part_name' => $part['name'] ?? $part,
                'quantity' => $part['quantity'] ?? 1,
                'status' => 'ordered',
                'ordered_at' => now()
            ]);
        }
        
        Log::info('Maintenance marked as awaiting parts', [
            'maintenance_id' => $this->id,
            'classroom_id' => $this->classroom_id,
            'parts_needed' => $partsNeeded,
            'action_by' => Auth::id()
        ]);
        
        return true;
    }

    /**
     * Complete maintenance.
     *
     * @param  User  $completer
     * @param  array  $workDone
     * @param  array  $materialsUsed
     * @param  float  $laborCost
     * @param  float  $materialCost
     * @param  string|null  $notes
     * @return bool
     */
    public function complete($completer, $workDone, $materialsUsed, $laborCost, $materialCost, $notes = null)
    {
        if ($this->status !== 'in_progress') {
            throw new \Exception('Maintenance must be in progress to complete');
        }
        
        $this->status = 'completed';
        $this->completed_by = $completer->id;
        $this->actual_completion = now();
        $this->work_done = $workDone;
        $this->materials_used = $materialsUsed;
        $this->labor_cost = $laborCost;
        $this->material_cost = $materialCost;
        $this->total_cost = $laborCost + $materialCost;
        
        if ($notes) {
            $this->notes = ($this->notes ? $this->notes . "\n" : '') . "Completion notes: {$notes}";
        }
        
        $this->save();
        
        Log::info('Maintenance completed', [
            'maintenance_id' => $this->id,
            'classroom_id' => $this->classroom_id,
            'completer_id' => $completer->id,
            'total_cost' => $this->total_cost,
            'work_done' => $workDone
        ]);
        
        return true;
    }

    /**
     * Cancel maintenance.
     *
     * @param  string  $reason
     * @return bool
     */
    public function cancel($reason)
    {
        if ($this->status === 'completed' || $this->status === 'cancelled') {
            throw new \Exception('Maintenance is already completed or cancelled');
        }
        
        $this->status = 'cancelled';
        $this->notes = ($this->notes ? $this->notes . "\n" : '') . "Cancelled: {$reason}";
        $this->save();
        
        Log::info('Maintenance cancelled', [
            'maintenance_id' => $this->id,
            'classroom_id' => $this->classroom_id,
            'reason' => $reason,
            'cancelled_by' => Auth::id()
        ]);
        
        return true;
    }

    /**
     * Approve budget.
     *
     * @param  User  $approver
     * @param  float|null  $approvedAmount
     * @return bool
     */
    public function approveBudget($approver, $approvedAmount = null)
    {
        if ($this->budget_approved) {
            throw new \Exception('Budget already approved');
        }
        
        $this->budget_approved = true;
        $this->approval_by = $approver->id;
        $this->approval_date = now();
        
        if ($approvedAmount) {
            $this->estimated_cost = $approvedAmount;
        }
        
        $this->save();
        
        Log::info('Maintenance budget approved', [
            'maintenance_id' => $this->id,
            'classroom_id' => $this->classroom_id,
            'approver_id' => $approver->id,
            'approved_amount' => $approvedAmount ?? $this->estimated_cost
        ]);
        
        return true;
    }

    /**
     * Add before/after images.
     *
     * @param  array  $beforeImages
     * @param  array  $afterImages
     * @return bool
     */
    public function addImages($beforeImages = [], $afterImages = [])
    {
        $currentBefore = $this->before_images ?? [];
        $currentAfter = $this->after_images ?? [];
        
        $this->before_images = array_merge($currentBefore, $beforeImages);
        $this->after_images = array_merge($currentAfter, $afterImages);
        $this->save();
        
        return true;
    }

    /**
     * Update classroom status based on maintenance.
     *
     * @return void
     */
    public function updateClassroomStatus()
    {
        if (!$this->classroom) {
            return;
        }
        
        $classroom = $this->classroom;
        
        // If maintenance is in progress or reported, set classroom to maintenance
        if (in_array($this->status, ['reported', 'assigned', 'in_progress', 'on_hold', 'awaiting_parts'])) {
            if ($classroom->status !== 'maintenance') {
                $classroom->status = 'maintenance';
                $classroom->save();
            }
        } 
        // If maintenance is completed or cancelled, restore classroom status
        elseif (in_array($this->status, ['completed', 'cancelled', 'rejected'])) {
            // Check if there are other active maintenance records
            $activeMaintenance = ClassroomMaintenance::where('classroom_id', $classroom->id)
                ->where('id', '!=', $this->id)
                ->whereIn('status', ['reported', 'assigned', 'in_progress', 'on_hold', 'awaiting_parts'])
                ->exists();
                
            if (!$activeMaintenance && $classroom->status === 'maintenance') {
                $classroom->status = 'available';
                $classroom->save();
            }
        }
    }

    /**
     * Get maintenance details.
     *
     * @return array
     */
    public function getDetails()
    {
        return [
            'id' => $this->id,
            'classroom' => $this->classroom ? [
                'id' => $this->classroom->id,
                'name' => $this->classroom->name,
                'code' => $this->classroom->code,
                'building' => $this->classroom->building,
                'room_number' => $this->classroom->room_number
            ] : null,
            'maintenance_type' => $this->maintenance_type,
            'maintenance_type_display' => $this->maintenance_type_display,
            'priority' => $this->priority,
            'priority_display' => $this->priority_display,
            'reason' => $this->reason,
            'description' => $this->description,
            'status' => $this->status,
            'status_display' => $this->status_display,
            'reporter' => $this->reporter ? [
                'id' => $this->reporter->id,
                'name' => $this->reporter->name,
                'email' => $this->reporter->email
            ] : null,
            'assignee' => $this->assignee ? [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
                'email' => $this->assignee->email
            ] : null,
            'completer' => $this->completer ? [
                'id' => $this->completer->id,
                'name' => $this->completer->name,
                'email' => $this->completer->email
            ] : null,
            'approver' => $this->approver ? [
                'id' => $this->approver->id,
                'name' => $this->approver->name,
                'email' => $this->approver->email
            ] : null,
            'timeline' => [
                'reported_at' => $this->reported_at,
                'assigned_at' => $this->assigned_at,
                'start_date' => $this->start_date,
                'expected_completion' => $this->expected_completion,
                'actual_completion' => $this->actual_completion,
                'approval_date' => $this->approval_date
            ],
            'cost' => $this->cost_breakdown,
            'work_done' => $this->work_done,
            'materials_used' => $this->materials_used,
            'estimated_hours' => $this->estimated_hours,
            'duration_days' => $this->duration_days,
            'is_overdue' => $this->is_overdue,
            'budget_approved' => $this->budget_approved,
            'before_images' => $this->before_images,
            'after_images' => $this->after_images,
            'notes' => $this->notes,
            'parts' => $this->parts()->get()->map(function($part) {
                return $part->getDetails();
            })
        ];
    }

    /**
     * Validate maintenance.
     *
     * @param  ClassroomMaintenance  $maintenance
     * @return void
     * @throws \Exception
     */
    private static function validateMaintenance($maintenance)
    {
        // Validate priority
        $validPriorities = ['low', 'medium', 'high', 'urgent', 'emergency'];
        if (!in_array($maintenance->priority, $validPriorities)) {
            throw new \Exception('Invalid priority level');
        }
        
        // Validate maintenance type
        $validTypes = ['routine', 'preventive', 'corrective', 'emergency', 'renovation', 
                      'deep_cleaning', 'equipment', 'electrical', 'plumbing', 'carpentry', 
                      'painting', 'other'];
        if (!in_array($maintenance->maintenance_type, $validTypes)) {
            throw new \Exception('Invalid maintenance type');
        }
        
        // Validate dates
        if ($maintenance->start_date && $maintenance->expected_completion) {
            if ($maintenance->start_date > $maintenance->expected_completion) {
                throw new \Exception('Start date must be before expected completion');
            }
        }
        
        if ($maintenance->actual_completion && $maintenance->start_date) {
            if ($maintenance->actual_completion < $maintenance->start_date) {
                throw new \Exception('Actual completion cannot be before start date');
            }
        }
        
        // Validate costs
        if ($maintenance->labor_cost && $maintenance->labor_cost < 0) {
            throw new \Exception('Labor cost cannot be negative');
        }
        
        if ($maintenance->material_cost && $maintenance->material_cost < 0) {
            throw new \Exception('Material cost cannot be negative');
        }
        
        if ($maintenance->total_cost && $maintenance->total_cost < 0) {
            throw new \Exception('Total cost cannot be negative');
        }
    }

    /**
     * Get maintenances for a classroom.
     *
     * @param  int  $classroomId
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getForClassroom($classroomId, $filters = [])
    {
        $cacheKey = "classroom_maintenances_classroom_{$classroomId}_" . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHours(6), function () use ($classroomId, $filters) {
            $query = self::where('classroom_id', $classroomId)
                ->with(['classroom', 'reporter', 'assignee', 'completer'])
                ->orderBy('created_at', 'desc');
            
            // Apply filters
            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            
            if (isset($filters['maintenance_type'])) {
                $query->where('maintenance_type', $filters['maintenance_type']);
            }
            
            if (isset($filters['priority'])) {
                $query->where('priority', $filters['priority']);
            }
            
            if (isset($filters['assigned_to'])) {
                $query->where('assigned_to', $filters['assigned_to']);
            }
            
            if (isset($filters['reported_by'])) {
                $query->where('reported_by', $filters['reported_by']);
            }
            
            if (isset($filters['date_from'])) {
                $query->whereDate('created_at', '>=', $filters['date_from']);
            }
            
            if (isset($filters['date_to'])) {
                $query->whereDate('created_at', '<=', $filters['date_to']);
            }
            
            return $query->get();
        });
    }

    /**
     * Get active maintenances.
     *
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getActive($filters = [])
    {
        $cacheKey = 'classroom_maintenances_active_' . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHour(), function () use ($filters) {
            $query = self::whereIn('status', ['reported', 'assigned', 'in_progress', 'on_hold', 'awaiting_parts'])
                ->with(['classroom', 'reporter', 'assignee'])
                ->orderBy('priority', 'desc')
                ->orderBy('created_at', 'asc');
            
            // Apply filters
            if (isset($filters['classroom_id'])) {
                $query->where('classroom_id', $filters['classroom_id']);
            }
            
            if (isset($filters['assigned_to'])) {
                $query->where('assigned_to', $filters['assigned_to']);
            }
            
            if (isset($filters['priority'])) {
                $query->where('priority', $filters['priority']);
            }
            
            return $query->get();
        });
    }

    /**
     * Get maintenance statistics.
     *
     * @param  array  $filters
     * @return array
     */
    public static function getStatistics($filters = [])
    {
        $query = self::query();
        
        // Apply filters
        if (isset($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        
        if (isset($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }
        
        if (isset($filters['classroom_id'])) {
            $query->where('classroom_id', $filters['classroom_id']);
        }
        
        $total = $query->count();
        $completed = $query->where('status', 'completed')->count();
        $inProgress = $query->whereIn('status', ['assigned', 'in_progress'])->count();
        $pending = $query->where('status', 'reported')->count();
        $onHold = $query->where('status', 'on_hold')->count();
        $cancelled = $query->where('status', 'cancelled')->count();
        
        // Get by priority
        $byPriority = $query->clone()
            ->selectRaw('priority, COUNT(*) as count')
            ->groupBy('priority')
            ->pluck('count', 'priority')
            ->toArray();
        
        // Get by type
        $byType = $query->clone()
            ->selectRaw('maintenance_type, COUNT(*) as count')
            ->groupBy('maintenance_type')
            ->pluck('count', 'maintenance_type')
            ->toArray();
        
        // Get average completion time
        $avgCompletion = $query->clone()
            ->where('status', 'completed')
            ->whereNotNull('start_date')
            ->whereNotNull('actual_completion')
            ->selectRaw('AVG(DATEDIFF(actual_completion, start_date)) as avg_days')
            ->first();
        
        // Get total cost
        $totalCost = $query->clone()
            ->where('status', 'completed')
            ->sum('total_cost');
        
        return [
            'total' => $total,
            'completed' => $completed,
            'in_progress' => $inProgress,
            'pending' => $pending,
            'on_hold' => $onHold,
            'cancelled' => $cancelled,
            'completion_rate' => $total > 0 ? round(($completed / $total) * 100, 2) : 0,
            'by_priority' => $byPriority,
            'by_type' => $byType,
            'average_completion_days' => $avgCompletion ? round($avgCompletion->avg_days, 2) : 0,
            'total_cost' => $totalCost,
            'average_cost' => $completed > 0 ? round($totalCost / $completed, 2) : 0
        ];
    }

    /**
     * Export maintenance data to array.
     *
     * @return array
     */
    public function exportToArray()
    {
        return [
            'id' => $this->id,
            'classroom' => $this->classroom ? $this->classroom->full_name : null,
            'maintenance_type' => $this->maintenance_type_display,
            'priority' => $this->priority_display,
            'reason' => $this->reason,
            'description' => $this->description,
            'status' => $this->status_display,
            'reporter' => $this->reporter_name,
            'assignee' => $this->assignee_name,
            'completer' => $this->completer_name,
            'approver' => $this->approver_name,
            'reported_at' => $this->reported_at,
            'assigned_at' => $this->assigned_at,
            'start_date' => $this->start_date,
            'expected_completion' => $this->expected_completion,
            'actual_completion' => $this->actual_completion,
            'duration_days' => $this->duration_days,
            'estimated_hours' => $this->estimated_hours,
            'estimated_cost' => $this->estimated_cost,
            'labor_cost' => $this->labor_cost,
            'material_cost' => $this->material_cost,
            'total_cost' => $this->total_cost,
            'budget_approved' => $this->budget_approved,
            'approval_date' => $this->approval_date,
            'is_overdue' => $this->is_overdue,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at
        ];
    }

    /**
     * Scope a query to only include active maintenances.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['reported', 'assigned', 'in_progress', 'on_hold', 'awaiting_parts']);
    }

    /**
     * Scope a query to only include completed maintenances.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope a query to only include maintenances with a specific priority.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $priority
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithPriority($query, $priority)
    {
        return $query->where('priority', $priority);
    }

    /**
     * Scope a query to only include maintenances of a specific type.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $maintenanceType
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfType($query, $maintenanceType)
    {
        return $query->where('maintenance_type', $maintenanceType);
    }

    /**
     * Scope a query to only include overdue maintenances.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOverdue($query)
    {
        return $query->where('status', 'in_progress')
                     ->where('expected_completion', '<', now());
    }
}