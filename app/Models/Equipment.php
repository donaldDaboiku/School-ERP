<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;  
use Carbon\Carbon;

class Equipment extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'equipment';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'code',
        'model',
        'serial_number',
        'equipment_type',
        'category',
        'manufacturer',
        'supplier',
        'purchase_date',
        'purchase_price',
        'current_value',
        'depreciation_rate',
        'warranty_expiry',
        'location',
        'classroom_id',
        'assigned_to',
        'status',
        'condition',
        'last_maintenance_date',
        'next_maintenance_date',
        'maintenance_interval_days',
        'usage_hours',
        'total_usage_hours',
        'calibration_date',
        'next_calibration_date',
        'calibration_interval_days',
        'specifications',
        'features',
        'accessories',
        'documentation',
        'is_portable',
        'power_requirements',
        'weight',
        'dimensions',
        'insurance_details',
        'disposal_date',
        'disposal_reason',
        'disposal_method',
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
        'purchase_date' => 'date',
        'purchase_price' => 'decimal:2',
        'current_value' => 'decimal:2',
        'depreciation_rate' => 'decimal:2',
        'warranty_expiry' => 'date',
        'last_maintenance_date' => 'date',
        'next_maintenance_date' => 'date',
        'maintenance_interval_days' => 'integer',
        'usage_hours' => 'decimal:2',
        'total_usage_hours' => 'decimal:2',
        'calibration_date' => 'date',
        'next_calibration_date' => 'date',
        'calibration_interval_days' => 'integer',
        'specifications' => 'json',
        'features' => 'json',
        'accessories' => 'json',
        'documentation' => 'json',
        'is_portable' => 'boolean',
        'power_requirements' => 'json',
        'weight' => 'decimal:2',
        'dimensions' => 'json',
        'insurance_details' => 'json',
        'disposal_date' => 'date',
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
        'equipment_type_display',
        'category_display',
        'condition_display',
        'age_years',
        'depreciated_value',
        'is_warranty_valid',
        'days_until_warranty_expiry',
        'needs_maintenance',
        'days_until_maintenance',
        'needs_calibration',
        'days_until_calibration',
        'usage_rate',
        'assigned_to_name',
        'location_details',
        'maintenance_history_count',
        'total_maintenance_cost'
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $with = ['classroom', 'assignedUser', 'creator', 'updater'];

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($equipment) {
            // Generate equipment code if not provided
            if (empty($equipment->code)) {
                $equipment->code = self::generateEquipmentCode($equipment);
            }

            // Set default status
            if (empty($equipment->status)) {
                $equipment->status = 'operational';
            }

            // Set default condition
            if (empty($equipment->condition)) {
                $equipment->condition = 'excellent';
            }

            // Set default depreciation rate
            if (is_null($equipment->depreciation_rate)) {
                $equipment->depreciation_rate = 10.0; // 10% per year
            }

            // Set default current value to purchase price
            if (is_null($equipment->current_value) && $equipment->purchase_price) {
                $equipment->current_value = $equipment->purchase_price;
            }

            // Set created_by if not set
            if (empty($equipment->created_by) && Auth::check()) {
                $equipment->created_by = Auth::id();
            }

            // Validate equipment
            self::validateEquipment($equipment);

            Log::info('Equipment creating', [
                'name' => $equipment->name,
                'code' => $equipment->code,
                'equipment_type' => $equipment->equipment_type,
                'created_by' => $equipment->created_by
            ]);
        });

        static::updating(function ($equipment) {
            // Update updated_by
            if (Auth::check()) {
                $equipment->updated_by = Auth::id();
            }

            // Calculate depreciated value if purchase date or price changes
            if (
                $equipment->isDirty('purchase_date') || $equipment->isDirty('purchase_price') ||
                $equipment->isDirty('depreciation_rate')
            ) {
                $equipment->current_value = $equipment->calculateDepreciatedValue();
            }

            // Set next maintenance date if maintenance interval changes
            if ($equipment->isDirty('maintenance_interval_days') && $equipment->last_maintenance_date) {
                $equipment->next_maintenance_date = $equipment->calculateNextMaintenanceDate();
            }

            // Set next calibration date if calibration interval changes
            if ($equipment->isDirty('calibration_interval_days') && $equipment->calibration_date) {
                $equipment->next_calibration_date = $equipment->calculateNextCalibrationDate();
            }

            // Validate equipment on update
            self::validateEquipment($equipment);

            // Prevent status change if equipment is currently in use
            if ($equipment->isDirty('status') && $equipment->status === 'in_use') {
                // Check if equipment is actually in use (could check reservations or usage logs)
            }
        });

        static::saved(function ($equipment) {
            // Clear relevant cache
            Cache::forget("equipment_{$equipment->id}");
            Cache::forget("equipment_code_{$equipment->code}");
            Cache::tags([
                "equipment_classroom_{$equipment->classroom_id}",
                "equipment_type_{$equipment->equipment_type}",
                "equipment_status_{$equipment->status}",
                "equipment_assigned_{$equipment->assigned_to}"
            ])->flush();
        });

        static::deleted(function ($equipment) {
            // Clear cache
            Cache::forget("equipment_{$equipment->id}");
            Cache::forget("equipment_code_{$equipment->code}");
            Cache::tags([
                "equipment_classroom_{$equipment->classroom_id}",
                "equipment_type_{$equipment->equipment_type}",
                "equipment_status_{$equipment->status}",
                "equipment_assigned_{$equipment->assigned_to}"
            ])->flush();
        });
    }

    /**
     * Get the classroom where this equipment is located.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function classroom()
    {
        return $this->belongsTo(Classroom::class, 'classroom_id');
    }

    /**
     * Get the user assigned to this equipment.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Get the user who created this equipment.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this equipment.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get the maintenance records for this equipment.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function maintenanceRecords()
    {
        return $this->hasMany(EquipmentMaintenance::class, 'equipment_id');
    }

    /**
     * Get the usage logs for this equipment.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function usageLogs()
    {
        return $this->hasMany(EquipmentUsageLog::class, 'equipment_id');
    }

    /**
     * Get the calibration records for this equipment.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function calibrationRecords()
    {
        return $this->hasMany(CalibrationRecord::class, 'equipment_id');
    }

    /**
     * Get the reservations for this equipment.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function reservations()
    {
        return $this->hasMany(EquipmentReservation::class, 'equipment_id');
    }

    /**
     * Get the full equipment name.
     *
     * @return string
     */
    public function getFullNameAttribute()
    {
        $name = $this->name;

        if ($this->model) {
            $name .= " ({$this->model})";
        }

        if ($this->code) {
            $name .= " [{$this->code}]";
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
            'operational' => 'Operational',
            'in_use' => 'In Use',
            'available' => 'Available',
            'under_maintenance' => 'Under Maintenance',
            'reserved' => 'Reserved',
            'out_of_service' => 'Out of Service',
            'retired' => 'Retired',
            'disposed' => 'Disposed',
            'lost' => 'Lost',
            'stolen' => 'Stolen'
        ];

        return $statuses[$this->status] ?? ucfirst($this->status);
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
            'video_system' => 'Video System',
            'lab_equipment' => 'Laboratory Equipment',
            'sports_equipment' => 'Sports Equipment',
            'music_instrument' => 'Music Instrument',
            'furniture' => 'Furniture',
            'tool' => 'Tool',
            'vehicle' => 'Vehicle',
            'other' => 'Other'
        ];

        return $types[$this->equipment_type] ?? ucfirst($this->equipment_type);
    }

    /**
     * Get category display name.
     *
     * @return string
     */
    public function getCategoryDisplayAttribute()
    {
        $categories = [
            'it_equipment' => 'IT Equipment',
            'audio_visual' => 'Audio Visual',
            'lab_equipment' => 'Laboratory',
            'office_equipment' => 'Office Equipment',
            'sports' => 'Sports',
            'music' => 'Music',
            'furniture' => 'Furniture',
            'vehicle' => 'Vehicle',
            'other' => 'Other'
        ];

        return $categories[$this->category] ?? ucfirst($this->category);
    }

    /**
     * Get condition display name.
     *
     * @return string
     */
    public function getConditionDisplayAttribute()
    {
        $conditions = [
            'excellent' => 'Excellent',
            'good' => 'Good',
            'fair' => 'Fair',
            'poor' => 'Poor',
            'needs_repair' => 'Needs Repair',
            'unusable' => 'Unusable'
        ];

        return $conditions[$this->condition] ?? ucfirst($this->condition);
    }

    /**
     * Get age in years.
     *
     * @return float|null
     */
    public function getAgeYearsAttribute()
    {
        if (!$this->purchase_date) {
            return null;
        }

        return round(now()->diffInYears($this->purchase_date), 1);
    }

    /**
     * Get depreciated value.
     *
     * @return float|null
     */
    public function getDepreciatedValueAttribute()
    {
        return $this->calculateDepreciatedValue();
    }

    /**
     * Check if warranty is valid.
     *
     * @return bool
     */
    public function getIsWarrantyValidAttribute()
    {
        if (!$this->warranty_expiry) {
            return false;
        }

        return now()->lt($this->warranty_expiry);
    }

    /**
     * Get days until warranty expiry.
     *
     * @return int|null
     */
    public function getDaysUntilWarrantyExpiryAttribute()
    {
        if (!$this->warranty_expiry) {
            return null;
        }

        return now()->diffInDays($this->warranty_expiry, false);
    }

    /**
     * Check if equipment needs maintenance.
     *
     * @return bool
     */
    public function getNeedsMaintenanceAttribute()
    {
        if (!$this->next_maintenance_date || $this->status === 'under_maintenance') {
            return false;
        }

        return now()->gt($this->next_maintenance_date);
    }

    /**
     * Get days until next maintenance.
     *
     * @return int|null
     */
    public function getDaysUntilMaintenanceAttribute()
    {
        if (!$this->next_maintenance_date) {
            return null;
        }

        return now()->diffInDays($this->next_maintenance_date, false);
    }

    /**
     * Check if equipment needs calibration.
     *
     * @return bool
     */
    public function getNeedsCalibrationAttribute()
    {
        if (!$this->next_calibration_date) {
            return false;
        }

        return now()->gt($this->next_calibration_date);
    }

    /**
     * Get days until next calibration.
     *
     * @return int|null
     */
    public function getDaysUntilCalibrationAttribute()
    {
        if (!$this->next_calibration_date) {
            return null;
        }

        return now()->diffInDays($this->next_calibration_date, false);
    }

    /**
     * Get usage rate (hours per day).
     *
     * @return float
     */
    public function getUsageRateAttribute()
    {
        if (!$this->purchase_date || $this->total_usage_hours <= 0) {
            return 0;
        }

        $daysOwned = max(1, now()->diffInDays($this->purchase_date));
        return round($this->total_usage_hours / $daysOwned, 2);
    }

    /**
     * Get assigned user name.
     *
     * @return string|null
     */
    public function getAssignedToNameAttribute()
    {
        return $this->assignedUser ? $this->assignedUser->name : null;
    }

    /**
     * Get location details.
     *
     * @return array|null
     */
    public function getLocationDetailsAttribute()
    {
        if ($this->classroom) {
            return [
                'type' => 'classroom',
                'id' => $this->classroom->id,
                'name' => $this->classroom->full_name,
                'building' => $this->classroom->building,
                'room_number' => $this->classroom->room_number
            ];
        }

        if ($this->location) {
            return [
                'type' => 'general',
                'name' => $this->location
            ];
        }

        return null;
    }

    /**
     * Get maintenance history count.
     *
     * @return int
     */
    public function getMaintenanceHistoryCountAttribute()
    {
        return $this->maintenanceRecords()->count();
    }

    /**
     * Get total maintenance cost.
     *
     * @return float
     */
    public function getTotalMaintenanceCostAttribute()
    {
        return $this->maintenanceRecords()->sum('maintenance_cost');
    }

    /**
     * Calculate depreciated value.
     *
     * @return float|null
     */
    public function calculateDepreciatedValue()
    {
        if (!$this->purchase_price || !$this->purchase_date) {
            return $this->current_value;
        }

        $ageYears = $this->age_years;
        if ($ageYears <= 0) {
            return $this->purchase_price;
        }

        $depreciationRate = $this->depreciation_rate / 100;
        $depreciatedValue = $this->purchase_price * pow((1 - $depreciationRate), $ageYears);

        // Ensure value doesn't go below 0
        return max(0, round($depreciatedValue, 2));
    }

    /**
     * Calculate next maintenance date.
     *
     * @return \Carbon\Carbon|null
     */
    public function calculateNextMaintenanceDate()
    {
        if (!$this->last_maintenance_date || !$this->maintenance_interval_days) {
            return null;
        }

        return $this->last_maintenance_date->copy()->addDays($this->maintenance_interval_days);
    }

    /**
     * Calculate next calibration date.
     *
     * @return \Carbon\Carbon|null
     */
    public function calculateNextCalibrationDate()
    {
        if (!$this->calibration_date || !$this->calibration_interval_days) {
            return null;
        }

        return $this->calibration_date->copy()->addDays($this->calibration_interval_days);
    }

    /**
     * Record equipment usage.
     *
     * @param  float  $hours
     * @param  User  $user
     * @param  string  $purpose
     * @param  string|null  $notes
     * @return EquipmentUsageLog
     */
    public function recordUsage($hours, $user, $purpose, $notes = null)
    {
        if ($hours <= 0) {
            throw new \Exception('Usage hours must be greater than 0');
        }

        if ($this->status !== 'operational' && $this->status !== 'in_use') {
            throw new \Exception("Equipment is not available for use. Current status: {$this->status_display}");
        }

        // Update equipment usage
        $this->usage_hours += $hours;
        $this->total_usage_hours += $hours;
        $this->status = 'in_use';
        $this->save();

        // Create usage log
        $log = EquipmentUsageLog::create([
            'equipment_id' => $this->id,
            'user_id' => $user->id,
            'usage_date' => now(),
            'hours_used' => $hours,
            'purpose' => $purpose,
            'notes' => $notes,
            'logged_by' => Auth::id()
        ]);

        Log::info('Equipment usage recorded', [
            'equipment_id' => $this->id,
            'equipment_name' => $this->name,
            'hours_used' => $hours,
            'user_id' => $user->id,
            'purpose' => $purpose,
            'logged_by' => Auth::id()
        ]);

        return $log;
    }

    /**
     * Complete usage session.
     *
     * @param  float  $hours
     * @param  string|null  $notes
     * @return bool
     */
    public function completeUsage($hours = null, $notes = null)
    {
        if ($this->status !== 'in_use') {
            throw new \Exception('Equipment is not currently in use');
        }

        // Update final hours if provided
        if ($hours !== null && $hours > 0) {
            $this->usage_hours = $hours;
        }

        $this->status = 'operational';
        $this->save();

        // Update the latest usage log
        $latestLog = $this->usageLogs()->latest()->first();
        if ($latestLog && !$latestLog->end_time) {
            $latestLog->end_time = now();
            $latestLog->total_hours = $hours ?? $this->usage_hours;
            if ($notes) {
                $latestLog->notes = ($latestLog->notes ? $latestLog->notes . "\n" : '') . "Completion: {$notes}";
            }
            $latestLog->save();
        }

        Log::info('Equipment usage completed', [
            'equipment_id' => $this->id,
            'equipment_name' => $this->name,
            'total_hours' => $this->usage_hours,
            'completed_by' => Auth::id()
        ]);

        return true;
    }

    /**
     * Schedule maintenance.
     *
     * @param  User  $scheduler
     * @param  \DateTime  $scheduledDate
     * @param  string  $maintenanceType
     * @param  string  $reason
     * @param  string|null  $notes
     * @return EquipmentMaintenance
     */
    public function scheduleMaintenance($scheduler, $scheduledDate, $maintenanceType, $reason, $notes = null)
    {
        if ($this->status === 'under_maintenance') {
            throw new \Exception('Equipment is already under maintenance');
        }

        $maintenance = EquipmentMaintenance::create([
            'equipment_id' => $this->id,
            'equipment_name' => $this->name,
            'equipment_type' => $this->equipment_type,
            'serial_number' => $this->serial_number,
            'maintenance_type' => $maintenanceType,
            'issue_type' => 'preventive',
            'priority' => 'medium',
            'issue_description' => $reason,
            'reported_by' => $scheduler->id,
            'reported_at' => now(),
            'status' => 'scheduled',
            'notes' => $notes
        ]);

        Log::info('Equipment maintenance scheduled', [
            'equipment_id' => $this->id,
            'equipment_name' => $this->name,
            'maintenance_id' => $maintenance->id,
            'scheduled_date' => $scheduledDate,
            'scheduled_by' => $scheduler->id
        ]);

        return $maintenance;
    }

    /**
     * Perform calibration.
     *
     * @param  User  $calibrator
     * @param  \DateTime  $calibrationDate
     * @param  array  $results
     * @param  string|null  $certificateNumber
     * @param  \DateTime|null  $nextCalibrationDate
     * @return CalibrationRecord
     */
    public function performCalibration($calibrator, $calibrationDate, $results, $certificateNumber = null, $nextCalibrationDate = null)
    {
        $record = CalibrationRecord::create([
            'equipment_id' => $this->id,
            'calibration_date' => $calibrationDate,
            'calibrated_by' => $calibrator->id,
            'results' => $results,
            'certificate_number' => $certificateNumber,
            'next_calibration_date' => $nextCalibrationDate,
            'status' => 'completed',
            'notes' => "Calibration performed for {$this->name}"
        ]);

        // Update equipment calibration dates
        $this->calibration_date = $calibrationDate;
        if ($nextCalibrationDate) {
            $this->next_calibration_date = $nextCalibrationDate;
        } elseif ($this->calibration_interval_days) {
            $this->next_calibration_date = Carbon::parse($calibrationDate)->addDays($this->calibration_interval_days);
        }
        $this->save();

        Log::info('Equipment calibration performed', [
            'equipment_id' => $this->id,
            'equipment_name' => $this->name,
            'calibration_id' => $record->id,
            'calibrated_by' => $calibrator->id,
            'next_calibration_date' => $this->next_calibration_date
        ]);

        return $record;
    }

    /**
     * Dispose equipment.
     *
     * @param  User  $disposer
     * @param  \DateTime  $disposalDate
     * @param  string  $reason
     * @param  string  $method
     * @param  float|null  $salePrice
     * @param  string|null  $buyer
     * @return bool
     */
    public function dispose($disposer, $disposalDate, $reason, $method, $salePrice = null, $buyer = null)
    {
        if (in_array($this->status, ['disposed', 'retired', 'lost', 'stolen'])) {
            throw new \Exception("Equipment is already {$this->status_display}");
        }

        $this->status = 'disposed';
        $this->disposal_date = $disposalDate;
        $this->disposal_reason = $reason;
        $this->disposal_method = $method;

        if ($salePrice) {
            $this->current_value = $salePrice;
        }

        $this->save();

        Log::info('Equipment disposed', [
            'equipment_id' => $this->id,
            'equipment_name' => $this->name,
            'disposal_date' => $disposalDate,
            'reason' => $reason,
            'method' => $method,
            'sale_price' => $salePrice,
            'disposed_by' => $disposer->id
        ]);

        return true;
    }

    /**
     * Get equipment details.
     *
     * @return array
     */
    public function getDetails()
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'full_name' => $this->full_name,
            'model' => $this->model,
            'serial_number' => $this->serial_number,
            'equipment_type' => $this->equipment_type,
            'equipment_type_display' => $this->equipment_type_display,
            'category' => $this->category,
            'category_display' => $this->category_display,
            'manufacturer' => $this->manufacturer,
            'supplier' => $this->supplier,
            'purchase_date' => $this->purchase_date,
            'purchase_price' => $this->purchase_price,
            'current_value' => $this->current_value,
            'depreciated_value' => $this->depreciated_value,
            'depreciation_rate' => $this->depreciation_rate,
            'age_years' => $this->age_years,
            'warranty_expiry' => $this->warranty_expiry,
            'is_warranty_valid' => $this->is_warranty_valid,
            'days_until_warranty_expiry' => $this->days_until_warranty_expiry,
            'location' => $this->location,
            'location_details' => $this->location_details,
            'classroom' => $this->classroom ? [
                'id' => $this->classroom->id,
                'name' => $this->classroom->full_name,
                'building' => $this->classroom->building
            ] : null,
            'assigned_to' => $this->assigned_to,
            'assigned_to_name' => $this->assigned_to_name,
            'status' => $this->status,
            'status_display' => $this->status_display,
            'condition' => $this->condition,
            'condition_display' => $this->condition_display,
            'last_maintenance_date' => $this->last_maintenance_date,
            'next_maintenance_date' => $this->next_maintenance_date,
            'maintenance_interval_days' => $this->maintenance_interval_days,
            'needs_maintenance' => $this->needs_maintenance,
            'days_until_maintenance' => $this->days_until_maintenance,
            'usage_hours' => $this->usage_hours,
            'total_usage_hours' => $this->total_usage_hours,
            'usage_rate' => $this->usage_rate,
            'calibration_date' => $this->calibration_date,
            'next_calibration_date' => $this->next_calibration_date,
            'calibration_interval_days' => $this->calibration_interval_days,
            'needs_calibration' => $this->needs_calibration,
            'days_until_calibration' => $this->days_until_calibration,
            'specifications' => $this->specifications,
            'features' => $this->features,
            'accessories' => $this->accessories,
            'documentation' => $this->documentation,
            'is_portable' => $this->is_portable,
            'power_requirements' => $this->power_requirements,
            'weight' => $this->weight,
            'dimensions' => $this->dimensions,
            'insurance_details' => $this->insurance_details,
            'disposal_date' => $this->disposal_date,
            'disposal_reason' => $this->disposal_reason,
            'disposal_method' => $this->disposal_method,
            'maintenance_history_count' => $this->maintenance_history_count,
            'total_maintenance_cost' => $this->total_maintenance_cost,
            'notes' => $this->notes
        ];
    }

    /**
     * Generate equipment code.
     *
     * @param  Equipment  $equipment
     * @return string
     */
    private static function generateEquipmentCode($equipment)
    {
        $typeCode = strtoupper(substr($equipment->equipment_type ?: 'EQP', 0, 3));
        $categoryCode = strtoupper(substr($equipment->category ?: 'GEN', 0, 3));

        do {
            $random = strtoupper(\Illuminate\Support\Str::random(6));
            $code = "EQ{$typeCode}{$categoryCode}{$random}";
        } while (self::where('code', $code)->exists());

        return $code;
    }

    /**
     * Validate equipment.
     *
     * @param  Equipment  $equipment
     * @return void
     * @throws \Exception
     */
    private static function validateEquipment($equipment)
    {
        // Check if equipment code is unique
        if ($equipment->code) {
            $existingEquipment = self::where('code', $equipment->code)
                ->where('id', '!=', $equipment->id)
                ->first();

            if ($existingEquipment) {
                throw new \Exception('Equipment code already exists');
            }
        }

        // Validate serial number uniqueness if provided
        if ($equipment->serial_number) {
            $existingBySerial = self::where('serial_number', $equipment->serial_number)
                ->where('id', '!=', $equipment->id)
                ->first();

            if ($existingBySerial) {
                throw new \Exception('Serial number already exists');
            }
        }

        // Validate purchase price
        if ($equipment->purchase_price && $equipment->purchase_price < 0) {
            throw new \Exception('Purchase price cannot be negative');
        }

        // Validate current value
        if ($equipment->current_value && $equipment->current_value < 0) {
            throw new \Exception('Current value cannot be negative');
        }

        // Validate depreciation rate
        if ($equipment->depreciation_rate && ($equipment->depreciation_rate < 0 || $equipment->depreciation_rate > 100)) {
            throw new \Exception('Depreciation rate must be between 0 and 100');
        }

        // Validate dates
        if ($equipment->purchase_date && $equipment->warranty_expiry) {
            if ($equipment->purchase_date > $equipment->warranty_expiry) {
                throw new \Exception('Purchase date must be before warranty expiry');
            }
        }

        if ($equipment->last_maintenance_date && $equipment->next_maintenance_date) {
            if ($equipment->last_maintenance_date > $equipment->next_maintenance_date) {
                throw new \Exception('Last maintenance date must be before next maintenance date');
            }
        }

        if ($equipment->calibration_date && $equipment->next_calibration_date) {
            if ($equipment->calibration_date > $equipment->next_calibration_date) {
                throw new \Exception('Calibration date must be before next calibration date');
            }
        }

        // Validate usage hours
        if ($equipment->usage_hours && $equipment->usage_hours < 0) {
            throw new \Exception('Usage hours cannot be negative');
        }

        if ($equipment->total_usage_hours && $equipment->total_usage_hours < 0) {
            throw new \Exception('Total usage hours cannot be negative');
        }
    }

    /**
     * Get equipment by code.
     *
     * @param  string  $code
     * @return Equipment|null
     */
    public static function getByCode($code)
    {
        return Cache::remember("equipment_code_{$code}", now()->addHours(12), function () use ($code) {
            return self::where('code', $code)->first();
        });
    }

    /**
     * Get equipment by serial number.
     *
     * @param  string  $serialNumber
     * @return Equipment|null
     */
    public static function getBySerialNumber($serialNumber)
    {
        return Cache::remember("equipment_serial_{$serialNumber}", now()->addHours(12), function () use ($serialNumber) {
            return self::where('serial_number', $serialNumber)->first();
        });
    }

    /**
     * Get equipment that needs maintenance.
     *
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getNeedsMaintenance($filters = [])
    {
        $cacheKey = 'equipment_needs_maintenance_' . md5(json_encode($filters));

        return Cache::remember($cacheKey, now()->addHour(), function () use ($filters) {
            $query = self::where('status', '!=', 'under_maintenance')
                ->whereNotNull('next_maintenance_date')
                ->whereDate('next_maintenance_date', '<=', now())
                ->orderBy('next_maintenance_date', 'asc');

            // Apply filters
            if (isset($filters['equipment_type'])) {
                $query->where('equipment_type', $filters['equipment_type']);
            }

            if (isset($filters['classroom_id'])) {
                $query->where('classroom_id', $filters['classroom_id']);
            }

            if (isset($filters['category'])) {
                $query->where('category', $filters['category']);
            }

            return $query->get();
        });
    }

    /**
     * Get equipment that needs calibration.
     *
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getNeedsCalibration($filters = [])
    {
        $cacheKey = 'equipment_needs_calibration_' . md5(json_encode($filters));

        return Cache::remember($cacheKey, now()->addHour(), function () use ($filters) {
            $query = self::whereNotNull('next_calibration_date')
                ->whereDate('next_calibration_date', '<=', now())
                ->orderBy('next_calibration_date', 'asc');

            // Apply filters
            if (isset($filters['equipment_type'])) {
                $query->where('equipment_type', $filters['equipment_type']);
            }

            if (isset($filters['category'])) {
                $query->where('category', $filters['category']);
            }

            return $query->get();
        });
    }

    /**
     * Get operational equipment.
     *
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getOperational($filters = [])
    {
        $cacheKey = 'equipment_operational_' . md5(json_encode($filters));

        return Cache::remember($cacheKey, now()->addHour(), function () use ($filters) {
            $query = self::whereIn('status', ['operational', 'available'])
                ->where('condition', '!=', 'unusable')
                ->orderBy('name', 'asc');

            // Apply filters
            if (isset($filters['equipment_type'])) {
                $query->where('equipment_type', $filters['equipment_type']);
            }

            if (isset($filters['classroom_id'])) {
                $query->where('classroom_id', $filters['classroom_id']);
            }

            if (isset($filters['category'])) {
                $query->where('category', $filters['category']);
            }

            if (isset($filters['is_portable'])) {
                $query->where('is_portable', $filters['is_portable']);
            }

            return $query->get();
        });
    }

    /**
     * Get equipment statistics.
     *
     * @param  array  $filters
     * @return array
     */
    public static function getStatistics($filters = [])
    {
        $query = self::query();

        // Apply filters
        if (isset($filters['date_from'])) {
            $query->whereDate('purchase_date', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->whereDate('purchase_date', '<=', $filters['date_to']);
        }

        if (isset($filters['equipment_type'])) {
            $query->where('equipment_type', $filters['equipment_type']);
        }

        if (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (isset($filters['classroom_id'])) {
            $query->where('classroom_id', $filters['classroom_id']);
        }

        $totalEquipment = $query->count();
        $totalValue = $query->sum('current_value');
        $totalPurchaseValue = $query->sum('purchase_price');
        $totalUsageHours = $query->sum('total_usage_hours');

        // Status breakdown
        $byStatus = $query->clone()
            ->selectRaw('status, COUNT(*) as count, SUM(current_value) as value')
            ->groupBy('status')
            ->orderBy('count', 'desc')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->status => [
                    'count' => $item->count,
                    'value' => $item->value
                ]];
            })
            ->toArray();

        // Condition breakdown
        $byCondition = $query->clone()
            ->selectRaw('condition, COUNT(*) as count')
            ->groupBy('condition')
            ->orderBy('count', 'desc')
            ->pluck('count', 'condition')
            ->toArray();

        // Type breakdown
        $byType = $query->clone()
            ->selectRaw('equipment_type, COUNT(*) as count, SUM(current_value) as value')
            ->groupBy('equipment_type')
            ->orderBy('value', 'desc')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->equipment_type => [
                    'count' => $item->count,
                    'value' => $item->value
                ]];
            })
            ->toArray();

        // Age distribution
        $ageDistribution = [
            'less_than_1_year' => $query->clone()->where('purchase_date', '>=', now()->subYear())->count(),
            '1_to_3_years' => $query->clone()->whereBetween('purchase_date', [now()->subYears(3), now()->subYear()])->count(),
            '3_to_5_years' => $query->clone()->whereBetween('purchase_date', [now()->subYears(5), now()->subYears(3)])->count(),
            '5_to_10_years' => $query->clone()->whereBetween('purchase_date', [now()->subYears(10), now()->subYears(5)])->count(),
            'more_than_10_years' => $query->clone()->where('purchase_date', '<', now()->subYears(10))->count()
        ];

        // Maintenance needs
        $needsMaintenance = $query->clone()
            ->whereNotNull('next_maintenance_date')
            ->whereDate('next_maintenance_date', '<=', now())
            ->count();

        $needsCalibration = $query->clone()
            ->whereNotNull('next_calibration_date')
            ->whereDate('next_calibration_date', '<=', now())
            ->count();

        return [
            'total_equipment' => $totalEquipment,
            'total_value' => round($totalValue, 2),
            'total_purchase_value' => round($totalPurchaseValue, 2),
            'depreciation' => round($totalPurchaseValue - $totalValue, 2),
            'total_usage_hours' => round($totalUsageHours, 2),
            'average_value' => $totalEquipment > 0 ? round($totalValue / $totalEquipment, 2) : 0,
            'average_age_years' => $totalEquipment > 0 ? round($query->avg(DB::raw('DATEDIFF(NOW(), purchase_date) / 365.25')), 1) : 0,
            'by_status' => $byStatus,
            'by_condition' => $byCondition,
            'by_type' => $byType,
            'age_distribution' => $ageDistribution,
            'needs_maintenance' => $needsMaintenance,
            'needs_calibration' => $needsCalibration,
            'under_warranty' => $query->clone()->whereDate('warranty_expiry', '>=', now())->count()
        ];
    }

    /**
     * Import equipment from CSV.
     *
     * @param  array  $data
     * @param  User  $importer
     * @return Equipment
     */
    public static function importFromCSV($data, $importer)
    {
        $equipment = new self($data);
        $equipment->created_by = $importer->id;
        $equipment->save();

        Log::info('Equipment imported from CSV', [
            'equipment_id' => $equipment->id,
            'equipment_code' => $equipment->code,
            'importer_id' => $importer->id
        ]);

        return $equipment;
    }

    /**
     * Export equipment data to array.
     *
     * @return array
     */
    public function exportToArray()
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'model' => $this->model,
            'serial_number' => $this->serial_number,
            'equipment_type' => $this->equipment_type_display,
            'category' => $this->category_display,
            'manufacturer' => $this->manufacturer,
            'supplier' => $this->supplier,
            'purchase_date' => $this->purchase_date,
            'purchase_price' => $this->purchase_price,
            'current_value' => $this->current_value,
            'depreciated_value' => $this->depreciated_value,
            'depreciation_rate' => $this->depreciation_rate,
            'age_years' => $this->age_years,
            'warranty_expiry' => $this->warranty_expiry,
            'is_warranty_valid' => $this->is_warranty_valid,
            'days_until_warranty_expiry' => $this->days_until_warranty_expiry,
            'location' => $this->location,
            'classroom' => $this->classroom ? $this->classroom->full_name : null,
            'assigned_to' => $this->assigned_to_name,
            'status' => $this->status_display,
            'condition' => $this->condition_display,
            'last_maintenance_date' => $this->last_maintenance_date,
            'next_maintenance_date' => $this->next_maintenance_date,
            'maintenance_interval_days' => $this->maintenance_interval_days,
            'needs_maintenance' => $this->needs_maintenance,
            'days_until_maintenance' => $this->days_until_maintenance,
            'usage_hours' => $this->usage_hours,
            'total_usage_hours' => $this->total_usage_hours,
            'usage_rate' => $this->usage_rate,
            'calibration_date' => $this->calibration_date,
            'next_calibration_date' => $this->next_calibration_date,
            'calibration_interval_days' => $this->calibration_interval_days,
            'needs_calibration' => $this->needs_calibration,
            'days_until_calibration' => $this->days_until_calibration,
            'is_portable' => $this->is_portable,
            'power_requirements' => $this->power_requirements,
            'weight' => $this->weight,
            'dimensions' => $this->dimensions,
            'maintenance_history_count' => $this->maintenance_history_count,
            'total_maintenance_cost' => $this->total_maintenance_cost,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at
        ];
    }

    /**
     * Scope a query to only include operational equipment.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOperational($query)
    {
        return $query->whereIn('status', ['operational', 'available']);
    }

    /**
     * Scope a query to only include equipment that needs maintenance.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeNeedsMaintenance($query)
    {
        return $query->whereNotNull('next_maintenance_date')
            ->whereDate('next_maintenance_date', '<=', now())
            ->where('status', '!=', 'under_maintenance');
    }

    /**
     * Scope a query to only include equipment that needs calibration.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeNeedsCalibration($query)
    {
        return $query->whereNotNull('next_calibration_date')
            ->whereDate('next_calibration_date', '<=', now());
    }

    /**
     * Scope a query to only include equipment under warranty.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUnderWarranty($query)
    {
        return $query->whereDate('warranty_expiry', '>=', now());
    }

    /**
     * Scope a query to only include equipment of specific type.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $equipmentType
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfType($query, $equipmentType)
    {
        return $query->where('equipment_type', $equipmentType);
    }

    /**
     * Scope a query to only include equipment in specific category.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $category
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope a query to only include portable equipment.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePortable($query)
    {
        return $query->where('is_portable', true);
    }

    /**
     * Scope a query to only include equipment assigned to specific user.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAssignedTo($query, $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    /**
     * Scope a query to only include equipment in specific classroom.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $classroomId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInClassroom($query, $classroomId)
    {
        return $query->where('classroom_id', $classroomId);
    }
}
