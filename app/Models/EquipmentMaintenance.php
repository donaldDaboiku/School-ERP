<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;    

class EquipmentMaintenance extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'equipment_maintenances';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'classroom_id',
        'equipment_id',
        'equipment_name',
        'equipment_type',
        'serial_number',
        'maintenance_type',
        'issue_type',
        'priority',
        'issue_description',
        'reported_by',
        'reported_at',
        'assigned_to',
        'assigned_at',
        'diagnosis',
        'root_cause',
        'estimated_hours',
        'estimated_cost',
        'parts_required',
        'parts_ordered',
        'parts_received',
        'repair_started',
        'repair_completed',
        'completed_by',
        'testing_date',
        'test_results',
        'next_maintenance_date',
        'maintenance_cost',
        'warranty_covered',
        'warranty_details',
        'status',
        'resolution_notes',
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
        'maintenance_cost' => 'decimal:2',
        'reported_at' => 'datetime',
        'assigned_at' => 'datetime',
        'repair_started' => 'datetime',
        'repair_completed' => 'datetime',
        'testing_date' => 'datetime',
        'next_maintenance_date' => 'datetime',
        'parts_required' => 'json',
        'parts_ordered' => 'json',
        'parts_received' => 'json',
        'test_results' => 'json',
        'warranty_covered' => 'boolean',
        'warranty_details' => 'json',
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
        'issue_type_display',
        'equipment_type_display',
        'repair_duration_hours',
        'is_overdue',
        'requires_parts',
        'parts_status',
        'reporter_name',
        'assignee_name',
        'completer_name',
        'equipment_details'
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $with = ['classroom', 'equipment', 'reporter', 'assignee', 'completer', 'creator', 'updater'];

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
            
            // Validate equipment maintenance
            self::validateEquipmentMaintenance($maintenance);
            
            Log::info('Equipment maintenance creating', [
                'equipment_id' => $maintenance->equipment_id,
                'equipment_name' => $maintenance->equipment_name,
                'issue_type' => $maintenance->issue_type,
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
            
            // Set repair_started if starting repair
            if ($maintenance->isDirty('status') && $maintenance->status === 'in_progress') {
                if (empty($maintenance->repair_started)) {
                    $maintenance->repair_started = now();
                }
            }
            
            // Set repair_completed if completing repair
            if ($maintenance->isDirty('status') && $maintenance->status === 'completed') {
                if (empty($maintenance->repair_completed)) {
                    $maintenance->repair_completed = now();
                }
            }
            
            // Set testing_date if testing
            if ($maintenance->isDirty('status') && $maintenance->status === 'testing') {
                if (empty($maintenance->testing_date)) {
                    $maintenance->testing_date = now();
                }
            }
            
            // Update next maintenance date for preventive maintenance
            if ($maintenance->isDirty('status') && $maintenance->status === 'completed' && 
                $maintenance->maintenance_type === 'preventive') {
                if (empty($maintenance->next_maintenance_date)) {
                    // Set next maintenance for 6 months later
                    $maintenance->next_maintenance_date = now()->addMonths(6);
                }
            }
            
            // Validate equipment maintenance on update
            self::validateEquipmentMaintenance($maintenance);
        });

        static::saved(function ($maintenance) {
            // Clear relevant cache
            Cache::forget("equipment_maintenance_{$maintenance->id}");
            Cache::tags([
                "equipment_maintenances_classroom_{$maintenance->classroom_id}",
                "equipment_maintenances_equipment_{$maintenance->equipment_id}",
                "equipment_maintenances_status_{$maintenance->status}",
                "equipment_maintenances_assigned_{$maintenance->assigned_to}"
            ])->flush();
            
            // Update equipment status
            $maintenance->updateEquipmentStatus();
        });

        static::deleted(function ($maintenance) {
            // Clear cache
            Cache::forget("equipment_maintenance_{$maintenance->id}");
            Cache::tags([
                "equipment_maintenances_classroom_{$maintenance->classroom_id}",
                "equipment_maintenances_equipment_{$maintenance->equipment_id}",
                "equipment_maintenances_status_{$maintenance->status}",
                "equipment_maintenances_assigned_{$maintenance->assigned_to}"
            ])->flush();
        });
    }

    /**
     * Get the classroom for this equipment maintenance.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function classroom()
    {
        return $this->belongsTo(Classroom::class, 'classroom_id');
    }

    /**
     * Get the equipment record.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function equipment()
    {
        return $this->belongsTo(Equipment::class, 'equipment_id');
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
     * Get the parts used in this maintenance.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function partsUsed()
    {
        return $this->hasMany(MaintenancePart::class, 'equipment_maintenance_id');
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
            'diagnosing' => 'Diagnosing',
            'awaiting_parts' => 'Awaiting Parts',
            'in_progress' => 'In Progress',
            'testing' => 'Testing',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'irreparable' => 'Irreparable'
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
            'critical' => 'Critical'
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
            'preventive' => 'Preventive Maintenance',
            'corrective' => 'Corrective Maintenance',
            'emergency' => 'Emergency Repair',
            'calibration' => 'Calibration',
            'inspection' => 'Inspection',
            'upgrade' => 'Upgrade',
            'installation' => 'Installation',
            'warranty' => 'Warranty Service'
        ];
        
        return $types[$this->maintenance_type] ?? ucfirst($this->maintenance_type);
    }

    /**
     * Get issue type display name.
     *
     * @return string
     */
    public function getIssueTypeDisplayAttribute()
    {
        $types = [
            'hardware' => 'Hardware Failure',
            'software' => 'Software Issue',
            'electrical' => 'Electrical Problem',
            'mechanical' => 'Mechanical Failure',
            'calibration' => 'Calibration Needed',
            'wear_and_tear' => 'Wear and Tear',
            'accidental_damage' => 'Accidental Damage',
            'preventive' => 'Preventive Service',
            'other' => 'Other'
        ];
        
        return $types[$this->issue_type] ?? ucfirst($this->issue_type);
    }

    /**
     * Get equipment type display name.
     *
     * @return string
     */
    public function getEquipmentTypeDisplayAttribute()
    {
        $types = [
            'computer' => 'Computer',
            'projector' => 'Projector',
            'smart_board' => 'Smart Board',
            'printer' => 'Printer',
            'scanner' => 'Scanner',
            'audio_system' => 'Audio System',
            'lab_equipment' => 'Laboratory Equipment',
            'furniture' => 'Furniture',
            'air_conditioner' => 'Air Conditioner',
            'other' => 'Other'
        ];
        
        return $types[$this->equipment_type] ?? ucfirst($this->equipment_type);
    }

    /**
     * Get repair duration in hours.
     *
     * @return float|null
     */
    public function getRepairDurationHoursAttribute()
    {
        if (!$this->repair_started || !$this->repair_completed) {
            return null;
        }
        
        return $this->repair_started->diffInHours($this->repair_completed, true);
    }

    /**
     * Check if maintenance is overdue.
     *
     * @return bool
     */
    public function getIsOverdueAttribute()
    {
        if ($this->status !== 'in_progress' || !$this->estimated_hours) {
            return false;
        }
        
        if (!$this->repair_started) {
            return false;
        }
        
        $expectedCompletion = $this->repair_started->addHours($this->estimated_hours);
        return now()->gt($expectedCompletion);
    }

    /**
     * Check if maintenance requires parts.
     *
     * @return bool
     */
    public function getRequiresPartsAttribute()
    {
        return !empty($this->parts_required) && is_array($this->parts_required);
    }

    /**
     * Get parts status.
     *
     * @return array
     */
    public function getPartsStatusAttribute()
    {
        $required = $this->parts_required ?? [];
        $ordered = $this->parts_ordered ?? [];
        $received = $this->parts_received ?? [];
        
        $status = [
            'total_required' => count($required),
            'ordered' => count($ordered),
            'received' => count($received),
            'pending' => count($required) - count($received),
            'all_received' => count($required) === count($received) && count($required) > 0
        ];
        
        return $status;
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
     * Get equipment details.
     *
     * @return array|null
     */
    public function getEquipmentDetailsAttribute()
    {
        if ($this->equipment) {
            return [
                'id' => $this->equipment->id,
                'name' => $this->equipment->name,
                'model' => $this->equipment->model,
                'serial_number' => $this->equipment->serial_number,
                'purchase_date' => $this->equipment->purchase_date,
                'warranty_expiry' => $this->equipment->warranty_expiry
            ];
        }
        
        return [
            'name' => $this->equipment_name,
            'type' => $this->equipment_type_display,
            'serial_number' => $this->serial_number
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
        
        Log::info('Equipment maintenance assigned to technician', [
            'maintenance_id' => $this->id,
            'technician_id' => $technician->id,
            'equipment_id' => $this->equipment_id,
            'assigned_by' => Auth::id()
        ]);
        
        return true;
    }

    /**
     * Start diagnosis.
     *
     * @param  string  $initialDiagnosis
     * @return bool
     */
    public function startDiagnosis($initialDiagnosis)
    {
        if ($this->status !== 'assigned') {
            throw new \Exception('Maintenance must be assigned before diagnosis');
        }
        
        $this->status = 'diagnosing';
        $this->diagnosis = $initialDiagnosis;
        $this->save();
        
        Log::info('Equipment diagnosis started', [
            'maintenance_id' => $this->id,
            'equipment_id' => $this->equipment_id,
            'diagnosis' => $initialDiagnosis,
            'started_by' => Auth::id()
        ]);
        
        return true;
    }

    /**
     * Complete diagnosis.
     *
     * @param  string  $rootCause
     * @param  array  $partsNeeded
     * @param  float  $estimatedHours
     * @param  float  $estimatedCost
     * @return bool
     */
    public function completeDiagnosis($rootCause, $partsNeeded = [], $estimatedHours = null, $estimatedCost = null)
    {
        if ($this->status !== 'diagnosing') {
            throw new \Exception('Maintenance must be in diagnosis phase');
        }
        
        $this->root_cause = $rootCause;
        $this->parts_required = $partsNeeded;
        
        if ($estimatedHours) {
            $this->estimated_hours = $estimatedHours;
        }
        
        if ($estimatedCost) {
            $this->estimated_cost = $estimatedCost;
        }
        
        // If parts are needed, move to awaiting parts
        if (!empty($partsNeeded)) {
            $this->status = 'awaiting_parts';
        } else {
            $this->status = 'in_progress';
            if (empty($this->repair_started)) {
                $this->repair_started = now();
            }
        }
        
        $this->save();
        
        Log::info('Equipment diagnosis completed', [
            'maintenance_id' => $this->id,
            'equipment_id' => $this->equipment_id,
            'root_cause' => $rootCause,
            'parts_needed' => $partsNeeded,
            'completed_by' => Auth::id()
        ]);
        
        return true;
    }

    /**
     * Order parts.
     *
     * @param  array  $partsOrdered
     * @return bool
     */
    public function orderParts($partsOrdered)
    {
        if ($this->status !== 'awaiting_parts') {
            throw new \Exception('Maintenance must be awaiting parts');
        }
        
        $this->parts_ordered = $partsOrdered;
        $this->save();
        
        Log::info('Parts ordered for equipment maintenance', [
            'maintenance_id' => $this->id,
            'equipment_id' => $this->equipment_id,
            'parts_ordered' => $partsOrdered,
            'ordered_by' => Auth::id()
        ]);
        
        return true;
    }

    /**
     * Receive parts.
     *
     * @param  array  $partsReceived
     * @return bool
     */
    public function receiveParts($partsReceived)
    {
        if ($this->status !== 'awaiting_parts') {
            throw new \Exception('Maintenance must be awaiting parts');
        }
        
        $this->parts_received = $partsReceived;
        
        // Check if all parts have been received
        $requiredParts = $this->parts_required ?? [];
        $receivedAll = count($requiredParts) === count($partsReceived);
        
        if ($receivedAll) {
            $this->status = 'in_progress';
            if (empty($this->repair_started)) {
                $this->repair_started = now();
            }
        }
        
        $this->save();
        
        Log::info('Parts received for equipment maintenance', [
            'maintenance_id' => $this->id,
            'equipment_id' => $this->equipment_id,
            'parts_received' => $partsReceived,
            'received_by' => Auth::id()
        ]);
        
        return true;
    }

    /**
     * Start repair.
     *
     * @return bool
     */
    public function startRepair()
    {
        if ($this->status !== 'in_progress' || !empty($this->repair_started)) {
            throw new \Exception('Repair already started or not ready');
        }
        
        $this->repair_started = now();
        $this->save();
        
        Log::info('Equipment repair started', [
            'maintenance_id' => $this->id,
            'equipment_id' => $this->equipment_id,
            'started_by' => Auth::id()
        ]);
        
        return true;
    }

    /**
     * Complete repair.
     *
     * @param  User  $completer
     * @param  string  $resolutionNotes
     * @param  float  $maintenanceCost
     * @param  array  $testResults
     * @return bool
     */
    public function completeRepair($completer, $resolutionNotes, $maintenanceCost = null, $testResults = null)
    {
        if ($this->status !== 'in_progress') {
            throw new \Exception('Maintenance must be in progress');
        }
        
        $this->status = 'testing';
        $this->repair_completed = now();
        $this->completed_by = $completer->id;
        $this->resolution_notes = $resolutionNotes;
        
        if ($maintenanceCost) {
            $this->maintenance_cost = $maintenanceCost;
        }
        
        if ($testResults) {
            $this->test_results = $testResults;
        }
        
        $this->save();
        
        Log::info('Equipment repair completed', [
            'maintenance_id' => $this->id,
            'equipment_id' => $this->equipment_id,
            'completer_id' => $completer->id,
            'resolution_notes' => $resolutionNotes,
            'maintenance_cost' => $maintenanceCost
        ]);
        
        return true;
    }

    /**
     * Complete testing.
     *
     * @param  array  $testResults
     * @param  string  $testerNotes
     * @return bool
     */
    public function completeTesting($testResults, $testerNotes = '')
    {
        if ($this->status !== 'testing') {
            throw new \Exception('Equipment must be in testing phase');
        }
        
        $this->status = 'completed';
        $this->testing_date = now();
        $this->test_results = $testResults;
        $this->resolution_notes = ($this->resolution_notes ? $this->resolution_notes . "\n" : '') . 
                                  "Testing results: {$testerNotes}";
        $this->save();
        
        Log::info('Equipment testing completed', [
            'maintenance_id' => $this->id,
            'equipment_id' => $this->equipment_id,
            'test_results' => $testResults,
            'tester_notes' => $testerNotes,
            'tested_by' => Auth::id()
        ]);
        
        return true;
    }

    /**
     * Mark as irreparable.
     *
     * @param  string  $reason
     * @return bool
     */
    public function markIrreparable($reason)
    {
        if ($this->status === 'completed' || $this->status === 'irreparable') {
            throw new \Exception('Maintenance is already completed or marked irreparable');
        }
        
        $this->status = 'irreparable';
        $this->resolution_notes = ($this->resolution_notes ? $this->resolution_notes . "\n" : '') . 
                                  "Marked irreparable: {$reason}";
        $this->save();
        
        Log::info('Equipment marked as irreparable', [
            'maintenance_id' => $this->id,
            'equipment_id' => $this->equipment_id,
            'reason' => $reason,
            'marked_by' => Auth::id()
        ]);
        
        return true;
    }

    /**
     * Update equipment status based on maintenance.
     *
     * @return void
     */
    public function updateEquipmentStatus()
    {
        if (!$this->equipment) {
            return;
        }
        
        $equipment = $this->equipment;
        
        // Update equipment status based on maintenance status
        switch ($this->status) {
            case 'reported':
            case 'assigned':
            case 'diagnosing':
            case 'awaiting_parts':
            case 'in_progress':
            case 'testing':
                $equipment->status = 'under_maintenance';
                break;
            case 'completed':
                $equipment->status = 'operational';
                // Update last maintenance date
                $equipment->last_maintenance_date = $this->repair_completed;
                // Update next maintenance date if set
                if ($this->next_maintenance_date) {
                    $equipment->next_maintenance_date = $this->next_maintenance_date;
                }
                break;
            case 'irreparable':
                $equipment->status = 'irreparable';
                break;
            case 'cancelled':
                $equipment->status = 'operational';
                break;
        }
        
        $equipment->save();
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
                'code' => $this->classroom->code
            ] : null,
            'equipment' => $this->equipment_details,
            'maintenance_type' => $this->maintenance_type,
            'maintenance_type_display' => $this->maintenance_type_display,
            'issue_type' => $this->issue_type,
            'issue_type_display' => $this->issue_type_display,
            'priority' => $this->priority,
            'priority_display' => $this->priority_display,
            'issue_description' => $this->issue_description,
            'diagnosis' => $this->diagnosis,
            'root_cause' => $this->root_cause,
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
            'timeline' => [
                'reported_at' => $this->reported_at,
                'assigned_at' => $this->assigned_at,
                'repair_started' => $this->repair_started,
                'repair_completed' => $this->repair_completed,
                'testing_date' => $this->testing_date,
                'next_maintenance_date' => $this->next_maintenance_date
            ],
            'cost' => [
                'estimated_hours' => $this->estimated_hours,
                'estimated_cost' => $this->estimated_cost,
                'maintenance_cost' => $this->maintenance_cost
            ],
            'parts' => [
                'required' => $this->parts_required,
                'ordered' => $this->parts_ordered,
                'received' => $this->parts_received,
                'status' => $this->parts_status
            ],
            'repair_duration_hours' => $this->repair_duration_hours,
            'is_overdue' => $this->is_overdue,
            'requires_parts' => $this->requires_parts,
            'warranty_covered' => $this->warranty_covered,
            'warranty_details' => $this->warranty_details,
            'test_results' => $this->test_results,
            'resolution_notes' => $this->resolution_notes,
            'parts_used' => $this->partsUsed()->get()->map(function($part) {
                return $part->getDetails();
            })
        ];
    }

    /**
     * Validate equipment maintenance.
     *
     * @param  EquipmentMaintenance  $maintenance
     * @return void
     * @throws \Exception
     */
    private static function validateEquipmentMaintenance($maintenance)
    {
        // Validate priority
        $validPriorities = ['low', 'medium', 'high', 'urgent', 'critical'];
        if (!in_array($maintenance->priority, $validPriorities)) {
            throw new \Exception('Invalid priority level');
        }
        
        // Validate maintenance type
        $validTypes = ['preventive', 'corrective', 'emergency', 'calibration', 
                      'inspection', 'upgrade', 'installation', 'warranty'];
        if (!in_array($maintenance->maintenance_type, $validTypes)) {
            throw new \Exception('Invalid maintenance type');
        }
        
        // Validate issue type
        $validIssueTypes = ['hardware', 'software', 'electrical', 'mechanical', 
                           'calibration', 'wear_and_tear', 'accidental_damage', 
                           'preventive', 'other'];
        if (!in_array($maintenance->issue_type, $validIssueTypes)) {
            throw new \Exception('Invalid issue type');
        }
        
        // Validate equipment type
        $validEquipmentTypes = ['computer', 'projector', 'smart_board', 'printer', 
                               'scanner', 'audio_system', 'lab_equipment', 
                               'furniture', 'air_conditioner', 'other'];
        if (!in_array($maintenance->equipment_type, $validEquipmentTypes)) {
            throw new \Exception('Invalid equipment type');
        }
        
        // Validate dates
        if ($maintenance->repair_started && $maintenance->repair_completed) {
            if ($maintenance->repair_started > $maintenance->repair_completed) {
                throw new \Exception('Repair start must be before completion');
            }
        }
        
        // Validate costs
        if ($maintenance->estimated_hours && $maintenance->estimated_hours < 0) {
            throw new \Exception('Estimated hours cannot be negative');
        }
        
        if ($maintenance->estimated_cost && $maintenance->estimated_cost < 0) {
            throw new \Exception('Estimated cost cannot be negative');
        }
        
        if ($maintenance->maintenance_cost && $maintenance->maintenance_cost < 0) {
            throw new \Exception('Maintenance cost cannot be negative');
        }
    }

    /**
     * Get equipment maintenances for a classroom.
     *
     * @param  int  $classroomId
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getForClassroom($classroomId, $filters = [])
    {
        $cacheKey = "equipment_maintenances_classroom_{$classroomId}_" . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHours(6), function () use ($classroomId, $filters) {
            $query = self::where('classroom_id', $classroomId)
                ->with(['classroom', 'equipment', 'reporter', 'assignee'])
                ->orderBy('created_at', 'desc');
            
            // Apply filters
            if (isset($filters['equipment_id'])) {
                $query->where('equipment_id', $filters['equipment_id']);
            }
            
            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            
            if (isset($filters['maintenance_type'])) {
                $query->where('maintenance_type', $filters['maintenance_type']);
            }
            
            if (isset($filters['priority'])) {
                $query->where('priority', $filters['priority']);
            }
            
            if (isset($filters['issue_type'])) {
                $query->where('issue_type', $filters['issue_type']);
            }
            
            if (isset($filters['assigned_to'])) {
                $query->where('assigned_to', $filters['assigned_to']);
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
     * Get equipment maintenances for a specific equipment.
     *
     * @param  int  $equipmentId
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getForEquipment($equipmentId, $filters = [])
    {
        $cacheKey = "equipment_maintenances_equipment_{$equipmentId}_" . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHours(6), function () use ($equipmentId, $filters) {
            $query = self::where('equipment_id', $equipmentId)
                ->with(['classroom', 'reporter', 'assignee', 'completer'])
                ->orderBy('created_at', 'desc');
            
            // Apply filters
            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            
            if (isset($filters['maintenance_type'])) {
                $query->where('maintenance_type', $filters['maintenance_type']);
            }
            
            return $query->get();
        });
    }

    /**
     * Get active equipment maintenances.
     *
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getActive($filters = [])
    {
        $cacheKey = 'equipment_maintenances_active_' . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHour(), function () use ($filters) {
            $query = self::whereIn('status', ['reported', 'assigned', 'diagnosing', 'awaiting_parts', 'in_progress', 'testing'])
                ->with(['classroom', 'equipment', 'reporter', 'assignee'])
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
     * Get equipment maintenance statistics.
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
        
        if (isset($filters['equipment_type'])) {
            $query->where('equipment_type', $filters['equipment_type']);
        }
        
        $total = $query->count();
        $completed = $query->where('status', 'completed')->count();
        $inProgress = $query->whereIn('status', ['assigned', 'diagnosing', 'in_progress', 'testing'])->count();
        $awaitingParts = $query->where('status', 'awaiting_parts')->count();
        $reported = $query->where('status', 'reported')->count();
        $irreparable = $query->where('status', 'irreparable')->count();
        
        // Get by equipment type
        $byEquipmentType = $query->clone()
            ->selectRaw('equipment_type, COUNT(*) as count')
            ->groupBy('equipment_type')
            ->pluck('count', 'equipment_type')
            ->toArray();
        
        // Get by issue type
        $byIssueType = $query->clone()
            ->selectRaw('issue_type, COUNT(*) as count')
            ->groupBy('issue_type')
            ->pluck('count', 'issue_type')
            ->toArray();
        
        // Get by maintenance type
        $byMaintenanceType = $query->clone()
            ->selectRaw('maintenance_type, COUNT(*) as count')
            ->groupBy('maintenance_type')
            ->pluck('count', 'maintenance_type')
            ->toArray();
        
        // Get average repair time
        $avgRepairTime = $query->clone()
            ->where('status', 'completed')
            ->whereNotNull('repair_started')
            ->whereNotNull('repair_completed')
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, repair_started, repair_completed)) as avg_hours')
            ->first();
        
        // Get total maintenance cost
        $totalCost = $query->clone()
            ->where('status', 'completed')
            ->sum('maintenance_cost');
        
        // Get warranty coverage
        $warrantyCovered = $query->clone()
            ->where('warranty_covered', true)
            ->count();
        
        return [
            'total' => $total,
            'completed' => $completed,
            'in_progress' => $inProgress,
            'awaiting_parts' => $awaitingParts,
            'reported' => $reported,
            'irreparable' => $irreparable,
            'completion_rate' => $total > 0 ? round(($completed / $total) * 100, 2) : 0,
            'by_equipment_type' => $byEquipmentType,
            'by_issue_type' => $byIssueType,
            'by_maintenance_type' => $byMaintenanceType,
            'average_repair_hours' => $avgRepairTime ? round($avgRepairTime->avg_hours, 2) : 0,
            'total_maintenance_cost' => $totalCost,
            'average_maintenance_cost' => $completed > 0 ? round($totalCost / $completed, 2) : 0,
            'warranty_covered_count' => $warrantyCovered,
            'warranty_coverage_rate' => $total > 0 ? round(($warrantyCovered / $total) * 100, 2) : 0
        ];
    }

    /**
     * Export equipment maintenance data to array.
     *
     * @return array
     */
    public function exportToArray()
    {
        return [
            'id' => $this->id,
            'classroom' => $this->classroom ? $this->classroom->full_name : null,
            'equipment' => $this->equipment_name,
            'equipment_type' => $this->equipment_type_display,
            'serial_number' => $this->serial_number,
            'maintenance_type' => $this->maintenance_type_display,
            'issue_type' => $this->issue_type_display,
            'priority' => $this->priority_display,
            'issue_description' => $this->issue_description,
            'diagnosis' => $this->diagnosis,
            'root_cause' => $this->root_cause,
            'status' => $this->status_display,
            'reporter' => $this->reporter_name,
            'assignee' => $this->assignee_name,
            'completer' => $this->completer_name,
            'reported_at' => $this->reported_at,
            'assigned_at' => $this->assigned_at,
            'repair_started' => $this->repair_started,
            'repair_completed' => $this->repair_completed,
            'testing_date' => $this->testing_date,
            'next_maintenance_date' => $this->next_maintenance_date,
            'estimated_hours' => $this->estimated_hours,
            'estimated_cost' => $this->estimated_cost,
            'maintenance_cost' => $this->maintenance_cost,
            'repair_duration_hours' => $this->repair_duration_hours,
            'is_overdue' => $this->is_overdue,
            'requires_parts' => $this->requires_parts,
            'parts_required' => $this->parts_required,
            'parts_ordered' => $this->parts_ordered,
            'parts_received' => $this->parts_received,
            'warranty_covered' => $this->warranty_covered,
            'warranty_details' => $this->warranty_details,
            'test_results' => $this->test_results,
            'resolution_notes' => $this->resolution_notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at
        ];
    }

    /**
     * Scope a query to only include active equipment maintenances.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['reported', 'assigned', 'diagnosing', 'awaiting_parts', 'in_progress', 'testing']);
    }

    /**
     * Scope a query to only include completed equipment maintenances.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope a query to only include preventive maintenances.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePreventive($query)
    {
        return $query->where('maintenance_type', 'preventive');
    }

    /**
     * Scope a query to only include corrective maintenances.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCorrective($query)
    {
        return $query->where('maintenance_type', 'corrective');
    }

    /**
     * Scope a query to only include equipment maintenances with specific equipment type.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $equipmentType
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithEquipmentType($query, $equipmentType)
    {
        return $query->where('equipment_type', $equipmentType);
    }

    /**
     * Scope a query to only include equipment maintenances with warranty coverage.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithWarranty($query)
    {
        return $query->where('warranty_covered', true);
    }

    /**
     * Scope a query to only include overdue equipment maintenances.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOverdue($query)
    {
        return $query->where('status', 'in_progress')
                     ->whereNotNull('repair_started')
                     ->whereNotNull('estimated_hours')
                     ->whereRaw('DATE_ADD(repair_started, INTERVAL estimated_hours HOUR) < NOW()');
    }
}