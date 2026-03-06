<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;    

class FeesStructure extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'fees_structures';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'code',
        'description',
        'academic_year',
        'term',
        'grade_level_id',
        'fees_type',
        'currency',
        'amount',
        'breakdown',
        'due_date',
        'late_fee_per_day',
        'max_late_fee',
        'discount_rules',
        'payment_methods',
        'is_active',
        'is_default',
        'applicable_from',
        'applicable_to',
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
        'amount' => 'decimal:2',
        'late_fee_per_day' => 'decimal:2',
        'max_late_fee' => 'decimal:2',
        'breakdown' => 'json',
        'discount_rules' => 'json',
        'payment_methods' => 'json',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'due_date' => 'date',
        'applicable_from' => 'date',
        'applicable_to' => 'date',
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
        'total_amount',
        'breakdown_list',
        'is_overdue',
        'days_until_due',
        'applied_count',
        'total_collected'
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $with = ['gradeLevel', 'creator', 'updater'];

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($feesStructure) {
            // Generate code if not provided
            if (empty($feesStructure->code)) {
                $feesStructure->code = self::generateFeesCode($feesStructure);
            }
            
            // Set default currency
            if (empty($feesStructure->currency)) {
                $feesStructure->currency = config('app.currency', 'USD');
            }
            
            // Set default status
            if (empty($feesStructure->status)) {
                $feesStructure->status = 'active';
            }
            
            // Set default is_active
            if (is_null($feesStructure->is_active)) {
                $feesStructure->is_active = true;
            }
            
            // Set created_by if not set
            if (empty($feesStructure->created_by) && Auth::check()) {
                $feesStructure->created_by = Auth::id();
            }
            
            // Validate fees structure
            self::validateFeesStructure($feesStructure);
            
            // Set as default if specified
            if ($feesStructure->is_default) {
                self::where('grade_level_id', $feesStructure->grade_level_id)
                    ->where('academic_year', $feesStructure->academic_year)
                    ->where('term', $feesStructure->term)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
            
            Log::info('Fees structure creating', [
                'name' => $feesStructure->name,
                'code' => $feesStructure->code,
                'academic_year' => $feesStructure->academic_year,
                'amount' => $feesStructure->amount,
                'created_by' => $feesStructure->created_by
            ]);
        });

        static::updating(function ($feesStructure) {
            // Update updated_by
            if (Auth::check()) {
                $feesStructure->updated_by = Auth::id();
            }
            
            // Validate fees structure on update
            self::validateFeesStructure($feesStructure);
            
            // Set as default if specified
            if ($feesStructure->isDirty('is_default') && $feesStructure->is_default) {
                self::where('grade_level_id', $feesStructure->grade_level_id)
                    ->where('academic_year', $feesStructure->academic_year)
                    ->where('term', $feesStructure->term)
                    ->where('id', '!=', $feesStructure->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
            
            // Prevent updates if there are existing payments
            if (!$feesStructure->is_active && $feesStructure->getOriginal('is_active')) {
                $existingPayments = $feesStructure->payments()->count();
                if ($existingPayments > 0) {
                    throw new \Exception("Cannot deactivate fees structure with {$existingPayments} existing payments");
                }
            }
        });

        static::saved(function ($feesStructure) {
            // Clear relevant cache
            Cache::forget("fees_structure_{$feesStructure->id}");
            Cache::forget("fees_structure_code_{$feesStructure->code}");
            Cache::tags([
                "fees_structures_grade_{$feesStructure->grade_level_id}",
                "fees_structures_academic_year_{$feesStructure->academic_year}",
                "fees_structures_default"
            ])->flush();
        });

        static::deleted(function ($feesStructure) {
            // Clear cache
            Cache::forget("fees_structure_{$feesStructure->id}");
            Cache::forget("fees_structure_code_{$feesStructure->code}");
            Cache::tags([
                "fees_structures_grade_{$feesStructure->grade_level_id}",
                "fees_structures_academic_year_{$feesStructure->academic_year}",
                "fees_structures_default"
            ])->flush();
        });
    }

    /**
     * Get the grade level for this fees structure.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function gradeLevel()
    {
        return $this->belongsTo(GradeLevel::class, 'grade_level_id');
    }

    /**
     * Get the user who created this fees structure.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this fees structure.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get the payments for this fees structure.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function payments()
    {
        return $this->hasMany(FeePayment::class, 'fees_structure_id');
    }

    /**
     * Get the waivers for this fees structure.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function waivers()
    {
        return $this->hasMany(FeeWaiver::class, 'fees_structure_id');
    }

    /**
     * Get the full fees structure name.
     *
     * @return string
     */
    public function getFullNameAttribute()
    {
        $name = $this->name;
        
        if ($this->gradeLevel) {
            $name .= " - {$this->gradeLevel->name}";
        }
        
        if ($this->academic_year) {
            $name .= " ({$this->academic_year}";
            
            if ($this->term) {
                $name .= " - {$this->term}";
            }
            
            $name .= ")";
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
            'active' => 'Active',
            'inactive' => 'Inactive',
            'archived' => 'Archived',
            'expired' => 'Expired'
        ];
        
        return $statuses[$this->status] ?? ucfirst($this->status);
    }

    /**
     * Get total amount including breakdown.
     *
     * @return float
     */
    public function getTotalAmountAttribute()
    {
        $total = $this->amount;
        
        if ($this->breakdown && is_array($this->breakdown)) {
            foreach ($this->breakdown as $item) {
                $total += $item['amount'] ?? 0;
            }
        }
        
        return $total;
    }

    /**
     * Get breakdown list.
     *
     * @return array
     */
    public function getBreakdownListAttribute()
    {
        $breakdown = [];
        
        // Add main fee
        $breakdown[] = [
            'name' => 'Base Fee',
            'amount' => $this->amount,
            'description' => $this->description
        ];
        
        // Add breakdown items
        if ($this->breakdown && is_array($this->breakdown)) {
            foreach ($this->breakdown as $item) {
                $breakdown[] = [
                    'name' => $item['name'] ?? 'Additional Fee',
                    'amount' => $item['amount'] ?? 0,
                    'description' => $item['description'] ?? ''
                ];
            }
        }
        
        return $breakdown;
    }

    /**
     * Check if fees are overdue.
     *
     * @return bool
     */
    public function getIsOverdueAttribute()
    {
        if (!$this->due_date) {
            return false;
        }
        
        return now()->gt($this->due_date);
    }

    /**
     * Get days until due date.
     *
     * @return int|null
     */
    public function getDaysUntilDueAttribute()
    {
        if (!$this->due_date) {
            return null;
        }
        
        return now()->diffInDays($this->due_date, false);
    }

    /**
     * Get count of students who have applied this fees structure.
     *
     * @return int
     */
    public function getAppliedCountAttribute()
    {
        return $this->payments()->distinct('student_id')->count('student_id');
    }

    /**
     * Get total amount collected.
     *
     * @return float
     */
    public function getTotalCollectedAttribute()
    {
        return $this->payments()->where('status', 'paid')->sum('amount_paid');
    }

    /**
     * Calculate late fee for a given payment date.
     *
     * @param  \Carbon\Carbon|null  $paymentDate
     * @return float
     */
    public function calculateLateFee($paymentDate = null)
    {
        if (!$this->due_date || !$this->late_fee_per_day) {
            return 0;
        }
        
        $paymentDate = $paymentDate ?: now();
        
        if ($paymentDate <= $this->due_date) {
            return 0;
        }
        
        $daysLate = $this->due_date->diffInDays($paymentDate);
        $lateFee = $daysLate * $this->late_fee_per_day;
        
        if ($this->max_late_fee && $lateFee > $this->max_late_fee) {
            return $this->max_late_fee;
        }
        
        return $lateFee;
    }

    /**
     * Calculate discount for a student.
     *
     * @param  int  $studentId
     * @return array
     */
    public function calculateDiscount($studentId)
    {
        $discountAmount = 0;
        $discountPercentage = 0;
        $discountReason = '';
        
        if ($this->discount_rules && is_array($this->discount_rules)) {
            // Check each discount rule
            foreach ($this->discount_rules as $rule) {
                $applicable = $this->evaluateDiscountRule($rule, $studentId);
                
                if ($applicable) {
                    if ($rule['type'] === 'percentage') {
                        $discountPercentage = max($discountPercentage, $rule['value']);
                    } elseif ($rule['type'] === 'fixed') {
                        $discountAmount = max($discountAmount, $rule['value']);
                    }
                    
                    if (!$discountReason) {
                        $discountReason = $rule['reason'] ?? '';
                    }
                }
            }
        }
        
        return [
            'percentage' => $discountPercentage,
            'amount' => $discountAmount,
            'reason' => $discountReason
        ];
    }

    /**
     * Evaluate a discount rule for a student.
     *
     * @param  array  $rule
     * @param  int  $studentId
     * @return bool
     */
    private function evaluateDiscountRule($rule, $studentId)
    {
        // Implementation depends on your discount rule structure
        // This is a placeholder for rule evaluation logic
        return false;
    }

    /**
     * Check if fees structure is applicable for a student.
     *
     * @param  int  $studentId
     * @return array
     */
    public function isApplicableForStudent($studentId)
    {
        $student = Student::find($studentId);
        
        if (!$student) {
            return ['applicable' => false, 'reason' => 'Student not found'];
        }
        
        // Check grade level
        if ($this->grade_level_id && $student->grade_level_id != $this->grade_level_id) {
            return ['applicable' => false, 'reason' => 'Fees structure not applicable for student grade level'];
        }
        
        // Check date applicability
        $today = now();
        if ($this->applicable_from && $today < $this->applicable_from) {
            return ['applicable' => false, 'reason' => 'Fees structure not yet applicable'];
        }
        
        if ($this->applicable_to && $today > $this->applicable_to) {
            return ['applicable' => false, 'reason' => 'Fees structure expired'];
        }
        
        // Check if student has already paid
        $existingPayment = $this->payments()
            ->where('student_id', $studentId)
            ->where('status', 'paid')
            ->first();
            
        if ($existingPayment) {
            return ['applicable' => false, 'reason' => 'Fees already paid'];
        }
        
        return ['applicable' => true, 'reason' => ''];
    }

    /**
     * Generate fees code.
     *
     * @param  FeesStructure  $feesStructure
     * @return string
     */
    private static function generateFeesCode($feesStructure)
    {
        $academicYear = substr(str_replace('/', '', $feesStructure->academic_year), 0, 4);
        $gradeCode = $feesStructure->gradeLevel ? $feesStructure->gradeLevel->code : 'GEN';
        $feeType = $feesStructure->fees_type ? strtoupper(substr($feesStructure->fees_type, 0, 3)) : 'FEE';
        
        do {
            $random = strtoupper(\Illuminate\Support\Str::random(4));
            $code = "FS{$academicYear}{$gradeCode}{$feeType}{$random}";
        } while (self::where('code', $code)->exists());
        
        return $code;
    }

    /**
     * Validate fees structure.
     *
     * @param  FeesStructure  $feesStructure
     * @return void
     * @throws \Exception
     */
    private static function validateFeesStructure($feesStructure)
    {
        // Check if fees code is unique
        if ($feesStructure->code) {
            $existingFees = self::where('code', $feesStructure->code)
                ->where('id', '!=', $feesStructure->id)
                ->first();
                
            if ($existingFees) {
                throw new \Exception('Fees structure code already exists');
            }
        }
        
        // Validate amount
        if ($feesStructure->amount <= 0) {
            throw new \Exception('Amount must be greater than 0');
        }
        
        // Validate due date
        if ($feesStructure->due_date && $feesStructure->applicable_from) {
            if ($feesStructure->due_date < $feesStructure->applicable_from) {
                throw new \Exception('Due date must be after applicable from date');
            }
        }
        
        // Validate applicable dates
        if ($feesStructure->applicable_from && $feesStructure->applicable_to) {
            if ($feesStructure->applicable_from > $feesStructure->applicable_to) {
                throw new \Exception('Applicable from date must be before applicable to date');
            }
        }
    }

    /**
     * Get fees structures for a specific grade level.
     *
     * @param  int  $gradeLevelId
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getForGradeLevel($gradeLevelId, $filters = [])
    {
        $cacheKey = "fees_structures_grade_{$gradeLevelId}_" . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHours(6), function () use ($gradeLevelId, $filters) {
            $query = self::where('grade_level_id', $gradeLevelId)
                ->with(['gradeLevel', 'creator'])
                ->orderBy('academic_year', 'desc')
                ->orderBy('term', 'desc');
            
            // Apply filters
            if (isset($filters['academic_year'])) {
                $query->where('academic_year', $filters['academic_year']);
            }
            
            if (isset($filters['term'])) {
                $query->where('term', $filters['term']);
            }
            
            if (isset($filters['fees_type'])) {
                $query->where('fees_type', $filters['fees_type']);
            }
            
            if (isset($filters['is_active'])) {
                $query->where('is_active', $filters['is_active']);
            }
            
            if (isset($filters['is_default'])) {
                $query->where('is_default', $filters['is_default']);
            }
            
            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            
            return $query->get();
        });
    }

    /**
     * Get default fees structure for a grade level.
     *
     * @param  int  $gradeLevelId
     * @param  string  $academicYear
     * @param  string|null  $term
     * @return FeesStructure|null
     */
    public static function getDefault($gradeLevelId, $academicYear, $term = null)
    {
        $cacheKey = "default_fees_structure_grade_{$gradeLevelId}_year_{$academicYear}_term_" . ($term ?? 'all');
        
        return Cache::remember($cacheKey, now()->addHours(12), function () use ($gradeLevelId, $academicYear, $term) {
            $query = self::where('grade_level_id', $gradeLevelId)
                ->where('academic_year', $academicYear)
                ->where('is_active', true)
                ->where('is_default', true);
            
            if ($term) {
                $query->where('term', $term);
            }
            
            return $query->first();
        });
    }

    /**
     * Clone fees structure.
     *
     * @param  string  $newName
     * @param  array  $overrides
     * @return FeesStructure
     */
    public function clone($newName, $overrides = [])
    {
        $newFeesStructure = $this->replicate();
        $newFeesStructure->name = $newName;
        $newFeesStructure->code = self::generateFeesCode($newFeesStructure);
        $newFeesStructure->status = 'active';
        $newFeesStructure->is_default = false;
        
        // Apply overrides
        foreach ($overrides as $key => $value) {
            if (in_array($key, $newFeesStructure->fillable)) {
                $newFeesStructure->$key = $value;
            }
        }
        
        $newFeesStructure->save();
        
        Log::info('Fees structure cloned', [
            'original_id' => $this->id,
            'new_id' => $newFeesStructure->id,
            'new_name' => $newName,
            'cloned_by' => Auth::id()
        ]);
        
        return $newFeesStructure;
    }

    /**
     * Get statistics for this fees structure.
     *
     * @return array
     */
    public function getStatistics()
    {
        $totalStudents = $this->gradeLevel ? $this->gradeLevel->students()->active()->count() : 0;
        $applicableCount = $this->applied_count;
        $paidCount = $this->payments()->where('status', 'paid')->distinct('student_id')->count('student_id');
        $pendingCount = $this->payments()->where('status', 'pending')->distinct('student_id')->count('student_id');
        $overdueCount = $this->payments()->where('status', 'overdue')->distinct('student_id')->count('student_id');
        
        $totalPayable = $this->total_amount * $totalStudents;
        $totalCollected = $this->total_collected;
        $collectionRate = $totalPayable > 0 ? round(($totalCollected / $totalPayable) * 100, 2) : 0;
        
        // Get payment distribution by method
        $paymentMethods = $this->payments()
            ->selectRaw('payment_method, SUM(amount_paid) as total')
            ->where('status', 'paid')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method')
            ->toArray();
        
        return [
            'total_students' => $totalStudents,
            'applicable_count' => $applicableCount,
            'paid_count' => $paidCount,
            'pending_count' => $pendingCount,
            'overdue_count' => $overdueCount,
            'total_payable' => $totalPayable,
            'total_collected' => $totalCollected,
            'collection_rate' => $collectionRate,
            'payment_methods_distribution' => $paymentMethods,
            'average_payment_amount' => $paidCount > 0 ? round($totalCollected / $paidCount, 2) : 0
        ];
    }

    /**
     * Scope a query to only include active fees structures.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include default fees structures.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope a query to only include fees structures for a specific academic year.
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
     * Scope a query to only include fees structures for a specific term.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $term
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForTerm($query, $term)
    {
        return $query->where('term', $term);
    }
}