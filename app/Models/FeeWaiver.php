<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class FeeWaiver extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'fee_waivers';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'student_id',
        'fee_structure_id',
        'academic_year',
        'term',
        'waiver_type',
        'waiver_amount',
        'waiver_percentage',
        'reason',
        'approved_by',
        'approved_at',
        'status',
        'start_date',
        'end_date',
        'notes',
        'attachments',
        'created_by',
        'updated_by'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'waiver_amount' => 'decimal:2',
        'waiver_percentage' => 'decimal:2',
        'approved_at' => 'datetime',
        'start_date' => 'date',
        'end_date' => 'date',
        'attachments' => 'json',
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
        'total_waiver_amount',
        'status_display',
        'waiver_type_display',
        'is_active',
        'remaining_days',
        'student_info',
        'fee_structure_info'
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $with = ['student', 'feeStructure', 'approver', 'creator', 'updater'];

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($waiver) {
            // Set default status
            if (empty($waiver->status)) {
                $waiver->status = 'pending';
            }
            
            // Set created_by if not set
            if (empty($waiver->created_by) && Auth::check()) {
                $waiver->created_by = Auth::id();
            }
            
            // Validate waiver
            self::validateWaiver($waiver);
            
            Log::info('Fee waiver creating', [
                'student_id' => $waiver->student_id,
                'fee_structure_id' => $waiver->fee_structure_id,
                'waiver_type' => $waiver->waiver_type,
                'created_by' => $waiver->created_by
            ]);
        });

        static::updating(function ($waiver) {
            // Update updated_by
            if (Auth::check()) {
                $waiver->updated_by = Auth::id();
            }
            
            // Set approved_at if being approved
            if ($waiver->isDirty('status') && $waiver->status === 'approved') {
                $waiver->approved_at = now();
                $waiver->approved_by = Auth::id();
            }
            
            // Validate waiver on update
            self::validateWaiver($waiver);
        });

        static::saved(function ($waiver) {
            // Clear relevant cache
            Cache::forget("fee_waiver_{$waiver->id}");
            Cache::tags([
                "fee_waivers_student_{$waiver->student_id}",
                "fee_waivers_academic_year_{$waiver->academic_year}",
                "fee_waivers_status_{$waiver->status}"
            ])->flush();
        });

        static::deleted(function ($waiver) {
            // Clear cache
            Cache::forget("fee_waiver_{$waiver->id}");
            Cache::tags([
                "fee_waivers_student_{$waiver->student_id}",
                "fee_waivers_academic_year_{$waiver->academic_year}",
                "fee_waivers_status_{$waiver->status}"
            ])->flush();
        });
    }

    /**
     * Get the student for this waiver.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    /**
     * Get the fee structure for this waiver.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function feeStructure()
    {
        return $this->belongsTo(FeesStructure::class, 'fee_structure_id');
    }

    /**
     * Get the user who approved this waiver.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the user who created this waiver.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this waiver.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get total waiver amount.
     *
     * @return float
     */
    public function getTotalWaiverAmountAttribute()
    {
        if ($this->waiver_amount) {
            return $this->waiver_amount;
        }
        
        if ($this->waiver_percentage && $this->feeStructure) {
            return ($this->feeStructure->total_amount * $this->waiver_percentage) / 100;
        }
        
        return 0;
    }

    /**
     * Get status display name.
     *
     * @return string
     */
    public function getStatusDisplayAttribute()
    {
        $statuses = [
            'pending' => 'Pending Approval',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'expired' => 'Expired',
            'cancelled' => 'Cancelled'
        ];
        
        return $statuses[$this->status] ?? ucfirst($this->status);
    }

    /**
     * Get waiver type display name.
     *
     * @return string
     */
    public function getWaiverTypeDisplayAttribute()
    {
        $types = [
            'merit' => 'Merit-based',
            'need' => 'Need-based',
            'sports' => 'Sports Scholarship',
            'academic' => 'Academic Scholarship',
            'staff' => 'Staff Discount',
            'sibling' => 'Sibling Discount',
            'early_payment' => 'Early Payment Discount',
            'other' => 'Other'
        ];
        
        return $types[$this->waiver_type] ?? ucfirst($this->waiver_type);
    }

    /**
     * Check if waiver is active.
     *
     * @return bool
     */
    public function getIsActiveAttribute()
    {
        if ($this->status !== 'approved') {
            return false;
        }
        
        $today = now();
        
        if ($this->start_date && $today < $this->start_date) {
            return false;
        }
        
        if ($this->end_date && $today > $this->end_date) {
            return false;
        }
        
        return true;
    }

    /**
     * Get remaining days for waiver.
     *
     * @return int|null
     */
    public function getRemainingDaysAttribute()
    {
        if (!$this->end_date || !$this->is_active) {
            return null;
        }
        
        return now()->diffInDays($this->end_date, false);
    }

    /**
     * Get student information.
     *
     * @return array|null
     */
    public function getStudentInfoAttribute()
    {
        if (!$this->student) {
            return null;
        }
        
        return [
            'id' => $this->student->id,
            'name' => $this->student->name,
            'admission_number' => $this->student->admission_number,
            'grade_level' => $this->student->gradeLevel->name ?? null,
            'parent_name' => $this->student->parent_name,
            'parent_phone' => $this->student->parent_phone
        ];
    }

    /**
     * Get fee structure information.
     *
     * @return array|null
     */
    public function getFeeStructureInfoAttribute()
    {
        if (!$this->feeStructure) {
            return null;
        }
        
        return [
            'id' => $this->feeStructure->id,
            'name' => $this->feeStructure->name,
            'academic_year' => $this->feeStructure->academic_year,
            'term' => $this->feeStructure->term,
            'total_amount' => $this->feeStructure->total_amount
        ];
    }

    /**
     * Approve the waiver.
     *
     * @param  User  $approver
     * @param  string|null  $notes
     * @return bool
     */
    public function approve($approver, $notes = null)
    {
        if ($this->status === 'approved') {
            throw new \Exception('Waiver is already approved');
        }
        
        if ($this->status === 'rejected') {
            throw new \Exception('Cannot approve a rejected waiver');
        }
        
        $this->status = 'approved';
        $this->approved_by = $approver->id;
        $this->approved_at = now();
        
        if ($notes) {
            $this->notes = ($this->notes ? $this->notes . "\n" : '') . "Approval notes: {$notes}";
        }
        
        $this->save();
        
        // Create waiver payment record
        $this->createWaiverPayment();
        
        Log::info('Fee waiver approved', [
            'waiver_id' => $this->id,
            'student_id' => $this->student_id,
            'approved_by' => $approver->id,
            'waiver_amount' => $this->total_waiver_amount
        ]);
        
        return true;
    }

    /**
     * Reject the waiver.
     *
     * @param  User  $rejector
     * @param  string  $reason
     * @return bool
     */
    public function reject($rejector, $reason)
    {
        if ($this->status === 'rejected') {
            throw new \Exception('Waiver is already rejected');
        }
        
        if ($this->status === 'approved') {
            throw new \Exception('Cannot reject an approved waiver');
        }
        
        $this->status = 'rejected';
        $this->notes = ($this->notes ? $this->notes . "\n" : '') . "Rejection reason: {$reason}";
        $this->save();
        
        Log::info('Fee waiver rejected', [
            'waiver_id' => $this->id,
            'student_id' => $this->student_id,
            'rejected_by' => $rejector->id,
            'reason' => $reason
        ]);
        
        return true;
    }

    /**
     * Create waiver payment record.
     *
     * @return FeePayment|null
     */
    private function createWaiverPayment()
    {
        if (!$this->feeStructure || !$this->student) {
            return null;
        }
        
        $payment = FeePayment::create([
            'student_id' => $this->student_id,
            'fee_structure_id' => $this->fee_structure_id,
            'payment_type' => 'waiver',
            'amount' => $this->total_waiver_amount,
            'paid_amount' => 0,
            'balance' => -$this->total_waiver_amount, // Negative for waiver
            'payment_date' => now(),
            'status' => 'waived',
            'payment_method' => 'waiver',
            'reference_number' => 'WAIVER-' . $this->id,
            'notes' => "Fee waiver: {$this->reason}",
            'created_by' => $this->created_by
        ]);
        
        return $payment;
    }

    /**
     * Calculate effective fee after waiver.
     *
     * @return float
     */
    public function calculateEffectiveFee()
    {
        if (!$this->feeStructure || !$this->is_active) {
            return $this->feeStructure ? $this->feeStructure->total_amount : 0;
        }
        
        $originalFee = $this->feeStructure->total_amount;
        return $originalFee - $this->total_waiver_amount;
    }

    /**
     * Validate waiver.
     *
     * @param  FeeWaiver  $waiver
     * @return void
     * @throws \Exception
     */
    private static function validateWaiver($waiver)
    {
        // Check if waiver amount or percentage is provided
        if (!$waiver->waiver_amount && !$waiver->waiver_percentage) {
            throw new \Exception('Either waiver amount or percentage must be provided');
        }
        
        // Validate percentage range
        if ($waiver->waiver_percentage && ($waiver->waiver_percentage < 0 || $waiver->waiver_percentage > 100)) {
            throw new \Exception('Waiver percentage must be between 0 and 100');
        }
        
        // Validate dates
        if ($waiver->start_date && $waiver->end_date && $waiver->start_date > $waiver->end_date) {
            throw new \Exception('Start date must be before end date');
        }
        
        // Check for duplicate active waivers
        if ($waiver->status === 'approved' || $waiver->status === 'pending') {
            $existingWaiver = self::where('student_id', $waiver->student_id)
                ->where('fee_structure_id', $waiver->fee_structure_id)
                ->where('id', '!=', $waiver->id)
                ->whereIn('status', ['approved', 'pending'])
                ->first();
                
            if ($existingWaiver) {
                throw new \Exception('Student already has an active or pending waiver for this fee structure');
            }
        }
    }

    /**
     * Get waivers for a student.
     *
     * @param  int  $studentId
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getForStudent($studentId, $filters = [])
    {
        $cacheKey = "fee_waivers_student_{$studentId}_" . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHours(6), function () use ($studentId, $filters) {
            $query = self::where('student_id', $studentId)
                ->with(['feeStructure', 'approver', 'creator'])
                ->orderBy('created_at', 'desc');
            
            // Apply filters
            if (isset($filters['academic_year'])) {
                $query->where('academic_year', $filters['academic_year']);
            }
            
            if (isset($filters['term'])) {
                $query->where('term', $filters['term']);
            }
            
            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            
            if (isset($filters['waiver_type'])) {
                $query->where('waiver_type', $filters['waiver_type']);
            }
            
            if (isset($filters['is_active'])) {
                if ($filters['is_active']) {
                    $query->where('status', 'approved')
                          ->where(function($q) {
                              $q->whereNull('end_date')
                                ->orWhere('end_date', '>=', now());
                          });
                }
            }
            
            return $query->get();
        });
    }

    /**
     * Get total waiver amount for a student in an academic year.
     *
     * @param  int  $studentId
     * @param  string  $academicYear
     * @return float
     */
    public static function getTotalWaiverAmount($studentId, $academicYear)
    {
        $cacheKey = "total_waiver_amount_student_{$studentId}_year_{$academicYear}";
        
        return Cache::remember($cacheKey, now()->addHours(12), function () use ($studentId, $academicYear) {
            return self::where('student_id', $studentId)
                ->where('academic_year', $academicYear)
                ->where('status', 'approved')
                ->sum('waiver_amount');
        });
    }

    /**
     * Scope a query to only include approved waivers.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /**
     * Scope a query to only include pending waivers.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include active waivers.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'approved')
            ->where(function($q) {
                $q->whereNull('end_date')
                  ->orWhere('end_date', '>=', now());
            });
    }

    /**
     * Scope a query to only include waivers for a specific academic year.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $academicYear
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForAcademicYear($query, $academicYear)
    {
        return $query->where('academic_year', $academicYear);
    }
}