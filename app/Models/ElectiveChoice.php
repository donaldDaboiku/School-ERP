<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;  

class ElectiveChoice extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'elective_choices';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'elective_group_id',
        'student_id',
        'subject_id',
        'preference_order',
        'status',
        'selected_at',
        'selected_by',
        'allocated_at',
        'allocated_by',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
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
        'preference_order' => 'integer',
        'selected_at' => 'datetime',
        'allocated_at' => 'datetime',
        'rejected_at' => 'datetime',
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
        'is_allocated',
        'is_pending',
        'is_rejected',
        'selected_by_name',
        'allocated_by_name',
        'rejected_by_name',
        'waiting_list_position',
        'selection_timestamp'
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $with = ['electiveGroup', 'student', 'subject', 'selector', 'allocator', 'rejector', 'creator', 'updater'];

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($choice) {
            // Set default status
            if (empty($choice->status)) {
                $choice->status = 'pending';
            }
            
            // Set selected_at if not set
            if (empty($choice->selected_at)) {
                $choice->selected_at = now();
            }
            
            // Set selected_by if not set
            if (empty($choice->selected_by) && Auth::check()) {
                $choice->selected_by = Auth::id();
            }
            
            // Set created_by if not set
            if (empty($choice->created_by) && Auth::check()) {
                $choice->created_by = Auth::id();
            }
            
            // Validate elective choice
            self::validateElectiveChoice($choice);
            
            Log::info('Elective choice creating', [
                'elective_group_id' => $choice->elective_group_id,
                'student_id' => $choice->student_id,
                'subject_id' => $choice->subject_id,
                'preference_order' => $choice->preference_order,
                'created_by' => $choice->created_by
            ]);
        });

        static::updating(function ($choice) {
            // Update updated_by
            if (Auth::check()) {
                $choice->updated_by = Auth::id();
            }
            
            // Set allocated_at if being allocated
            if ($choice->isDirty('status') && $choice->status === 'allocated') {
                $choice->allocated_at = now();
                $choice->allocated_by = Auth::id();
            }
            
            // Set rejected_at if being rejected
            if ($choice->isDirty('status') && $choice->status === 'rejected') {
                $choice->rejected_at = now();
                $choice->rejected_by = Auth::id();
            }
            
            // Validate elective choice on update
            self::validateElectiveChoice($choice);
            
            // Update subject enrollment when choice is allocated
            if ($choice->isDirty('status') && $choice->status === 'allocated') {
                $choice->updateSubjectEnrollment();
            }
        });

        static::saved(function ($choice) {
            // Clear relevant cache
            Cache::forget("elective_choice_{$choice->id}");
            Cache::tags([
                "elective_choices_group_{$choice->elective_group_id}",
                "elective_choices_student_{$choice->student_id}",
                "elective_choices_subject_{$choice->subject_id}",
                "elective_choices_status_{$choice->status}"
            ])->flush();
        });

        static::deleted(function ($choice) {
            // Clear cache
            Cache::forget("elective_choice_{$choice->id}");
            Cache::tags([
                "elective_choices_group_{$choice->elective_group_id}",
                "elective_choices_student_{$choice->student_id}",
                "elective_choices_subject_{$choice->subject_id}",
                "elective_choices_status_{$choice->status}"
            ])->flush();
            
            // Update subject enrollment if choice was allocated
            if ($choice->status === 'allocated') {
                $choice->updateSubjectEnrollment(-1);
            }
        });
    }

    /**
     * Get the elective group for this choice.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function electiveGroup()
    {
        return $this->belongsTo(ElectiveGroup::class, 'elective_group_id');
    }

    /**
     * Get the student for this choice.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    /**
     * Get the subject for this choice.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    /**
     * Get the user who selected this choice.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function selector()
    {
        return $this->belongsTo(User::class, 'selected_by');
    }

    /**
     * Get the user who allocated this choice.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function allocator()
    {
        return $this->belongsTo(User::class, 'allocated_by');
    }

    /**
     * Get the user who rejected this choice.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function rejector()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * Get the user who created this choice.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this choice.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get status display name.
     *
     * @return string
     */
    public function getStatusDisplayAttribute()
    {
        $statuses = [
            'pending' => 'Pending',
            'allocated' => 'Allocated',
            'waiting_list' => 'Waiting List',
            'rejected' => 'Rejected',
            'cancelled' => 'Cancelled',
            'changed' => 'Changed'
        ];
        
        return $statuses[$this->status] ?? ucfirst($this->status);
    }

    /**
     * Check if choice is allocated.
     *
     * @return bool
     */
    public function getIsAllocatedAttribute()
    {
        return $this->status === 'allocated';
    }

    /**
     * Check if choice is pending.
     *
     * @return bool
     */
    public function getIsPendingAttribute()
    {
        return $this->status === 'pending';
    }

    /**
     * Check if choice is rejected.
     *
     * @return bool
     */
    public function getIsRejectedAttribute()
    {
        return $this->status === 'rejected';
    }

    /**
     * Get selector name.
     *
     * @return string|null
     */
    public function getSelectedByNameAttribute()
    {
        return $this->selector ? $this->selector->name : null;
    }

    /**
     * Get allocator name.
     *
     * @return string|null
     */
    public function getAllocatedByNameAttribute()
    {
        return $this->allocator ? $this->allocator->name : null;
    }

    /**
     * Get rejector name.
     *
     * @return string|null
     */
    public function getRejectedByNameAttribute()
    {
        return $this->rejector ? $this->rejector->name : null;
    }

    /**
     * Get waiting list position.
     *
     * @return int|null
     */
    public function getWaitingListPositionAttribute()
    {
        if ($this->status !== 'waiting_list') {
            return null;
        }
        
        return self::where('subject_id', $this->subject_id)
            ->where('status', 'waiting_list')
            ->where('selected_at', '<', $this->selected_at)
            ->count() + 1;
    }

    /**
     * Get selection timestamp.
     *
     * @return string|null
     */
    public function getSelectionTimestampAttribute()
    {
        return $this->selected_at ? $this->selected_at->format('Y-m-d H:i:s') : null;
    }

    /**
     * Allocate this choice.
     *
     * @param  User  $allocator
     * @param  string|null  $notes
     * @return bool
     */
    public function allocate($allocator, $notes = null)
    {
        if ($this->status === 'allocated') {
            throw new \Exception('Choice is already allocated');
        }
        
        if ($this->status === 'rejected') {
            throw new \Exception('Cannot allocate a rejected choice');
        }
        
        if ($this->status === 'cancelled') {
            throw new \Exception('Cannot allocate a cancelled choice');
        }
        
        // Check if subject has capacity
        if (!$this->checkSubjectCapacity()) {
            throw new \Exception('Subject has reached maximum capacity');
        }
        
        // Check if student already has allocated subject in this group
        $existingAllocation = self::where('elective_group_id', $this->elective_group_id)
            ->where('student_id', $this->student_id)
            ->where('status', 'allocated')
            ->where('id', '!=', $this->id)
            ->first();
            
        if ($existingAllocation) {
            throw new \Exception('Student already has an allocated subject in this elective group');
        }
        
        $this->status = 'allocated';
        $this->allocated_by = $allocator->id;
        $this->allocated_at = now();
        
        if ($notes) {
            $this->notes = ($this->notes ? $this->notes . "\n" : '') . "Allocation notes: {$notes}";
        }
        
        $this->save();
        
        Log::info('Elective choice allocated', [
            'choice_id' => $this->id,
            'elective_group_id' => $this->elective_group_id,
            'student_id' => $this->student_id,
            'subject_id' => $this->subject_id,
            'allocator_id' => $allocator->id,
            'allocated_by' => Auth::id()
        ]);
        
        return true;
    }

    /**
     * Reject this choice.
     *
     * @param  User  $rejector
     * @param  string  $reason
     * @return bool
     */
    public function reject($rejector, $reason)
    {
        if ($this->status === 'rejected') {
            throw new \Exception('Choice is already rejected');
        }
        
        if ($this->status === 'allocated') {
            throw new \Exception('Cannot reject an allocated choice');
        }
        
        $this->status = 'rejected';
        $this->rejected_by = $rejector->id;
        $this->rejected_at = now();
        $this->rejection_reason = $reason;
        $this->save();
        
        Log::info('Elective choice rejected', [
            'choice_id' => $this->id,
            'elective_group_id' => $this->elective_group_id,
            'student_id' => $this->student_id,
            'subject_id' => $this->subject_id,
            'rejector_id' => $rejector->id,
            'reason' => $reason
        ]);
        
        return true;
    }

    /**
     * Cancel this choice.
     *
     * @param  User  $canceller
     * @param  string  $reason
     * @return bool
     */
    public function cancel($canceller, $reason)
    {
        if ($this->status === 'cancelled') {
            throw new \Exception('Choice is already cancelled');
        }
        
        if ($this->status === 'allocated') {
            // If allocated, need to update subject enrollment
            $this->updateSubjectEnrollment(-1);
        }
        
        $this->status = 'cancelled';
        $this->notes = ($this->notes ? $this->notes . "\n" : '') . "Cancelled: {$reason}";
        $this->save();
        
        Log::info('Elective choice cancelled', [
            'choice_id' => $this->id,
            'elective_group_id' => $this->elective_group_id,
            'student_id' => $this->student_id,
            'subject_id' => $this->subject_id,
            'canceller_id' => $canceller->id,
            'reason' => $reason
        ]);
        
        return true;
    }

    /**
     * Move to waiting list.
     *
     * @return bool
     */
    public function moveToWaitingList()
    {
        if ($this->status === 'waiting_list') {
            throw new \Exception('Choice is already on waiting list');
        }
        
        if ($this->status === 'allocated') {
            throw new \Exception('Cannot move allocated choice to waiting list');
        }
        
        if ($this->status === 'rejected') {
            throw new \Exception('Cannot move rejected choice to waiting list');
        }
        
        $this->status = 'waiting_list';
        $this->save();
        
        Log::info('Elective choice moved to waiting list', [
            'choice_id' => $this->id,
            'elective_group_id' => $this->elective_group_id,
            'student_id' => $this->student_id,
            'subject_id' => $this->subject_id,
            'moved_by' => Auth::id()
        ]);
        
        return true;
    }

    /**
     * Check subject capacity.
     *
     * @return bool
     */
    public function checkSubjectCapacity()
    {
        if (!$this->subject || !$this->electiveGroup) {
            return false;
        }
        
        // Get subject capacity from pivot table
        $subjectInGroup = $this->electiveGroup->subjects()
            ->where('subjects.id', $this->subject_id)
            ->first();
            
        if (!$subjectInGroup) {
            return false;
        }
        
        $capacity = $subjectInGroup->pivot->capacity ?? 0;
        $currentEnrollment = $subjectInGroup->pivot->current_enrollment ?? 0;
        
        return $currentEnrollment < $capacity;
    }

    /**
     * Update subject enrollment.
     *
     * @param  int  $change
     * @return bool
     */
    public function updateSubjectEnrollment($change = 1)
    {
        if (!$this->subject || !$this->electiveGroup) {
            return false;
        }
        
        $subjectInGroup = $this->electiveGroup->subjects()
            ->where('subjects.id', $this->subject_id)
            ->first();
            
        if (!$subjectInGroup) {
            return false;
        }
        
        $currentEnrollment = $subjectInGroup->pivot->current_enrollment ?? 0;
        $newEnrollment = max(0, $currentEnrollment + $change);
        
        // Update the pivot table
        $this->electiveGroup->subjects()->updateExistingPivot($this->subject_id, [
            'current_enrollment' => $newEnrollment
        ]);
        
        return true;
    }

    /**
     * Get choice details.
     *
     * @return array
     */
    public function getDetails()
    {
        return [
            'id' => $this->id,
            'elective_group' => $this->electiveGroup ? [
                'id' => $this->electiveGroup->id,
                'name' => $this->electiveGroup->name,
                'academic_year' => $this->electiveGroup->academic_year
            ] : null,
            'student' => $this->student ? [
                'id' => $this->student->id,
                'name' => $this->student->name,
                'admission_number' => $this->student->admission_number,
                'grade_level' => $this->student->gradeLevel ? $this->student->gradeLevel->name : null
            ] : null,
            'subject' => $this->subject ? [
                'id' => $this->subject->id,
                'name' => $this->subject->name,
                'code' => $this->subject->code,
                'description' => $this->subject->description
            ] : null,
            'preference_order' => $this->preference_order,
            'status' => $this->status,
            'status_display' => $this->status_display,
            'is_allocated' => $this->is_allocated,
            'is_pending' => $this->is_pending,
            'is_rejected' => $this->is_rejected,
            'selector' => $this->selector ? [
                'id' => $this->selector->id,
                'name' => $this->selector->name
            ] : null,
            'allocator' => $this->allocator ? [
                'id' => $this->allocator->id,
                'name' => $this->allocator->name
            ] : null,
            'rejector' => $this->rejector ? [
                'id' => $this->rejector->id,
                'name' => $this->rejector->name
            ] : null,
            'timeline' => [
                'selected_at' => $this->selected_at,
                'allocated_at' => $this->allocated_at,
                'rejected_at' => $this->rejected_at
            ],
            'rejection_reason' => $this->rejection_reason,
            'waiting_list_position' => $this->waiting_list_position,
            'selection_timestamp' => $this->selection_timestamp,
            'notes' => $this->notes
        ];
    }

    /**
     * Validate elective choice.
     *
     * @param  ElectiveChoice  $choice
     * @return void
     * @throws \Exception
     */
    private static function validateElectiveChoice($choice)
    {
        // Check for duplicate choices
        $duplicateChoice = self::where('elective_group_id', $choice->elective_group_id)
            ->where('student_id', $choice->student_id)
            ->where('subject_id', $choice->subject_id)
            ->where('id', '!=', $choice->id)
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->first();
            
        if ($duplicateChoice) {
            throw new \Exception('Student has already selected this subject in this elective group');
        }
        
        // Validate preference order
        if ($choice->preference_order && $choice->preference_order < 1) {
            throw new \Exception('Preference order must be at least 1');
        }
        
        // Check if subject exists in elective group
        if ($choice->electiveGroup && $choice->subject) {
            $subjectInGroup = $choice->electiveGroup->subjects()
                ->where('subjects.id', $choice->subject_id)
                ->exists();
                
            if (!$subjectInGroup) {
                throw new \Exception('Subject is not available in this elective group');
            }
        }
        
        // Validate student eligibility
        if ($choice->electiveGroup && $choice->student) {
            if ($choice->electiveGroup->grade_level_id && 
                $choice->student->grade_level_id != $choice->electiveGroup->grade_level_id) {
                throw new \Exception('Student is not in the required grade level for this elective group');
            }
        }
    }

    /**
     * Get choices for an elective group.
     *
     * @param  int  $electiveGroupId
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getForElectiveGroup($electiveGroupId, $filters = [])
    {
        $cacheKey = "elective_choices_group_{$electiveGroupId}_" . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHours(6), function () use ($electiveGroupId, $filters) {
            $query = self::where('elective_group_id', $electiveGroupId)
                ->with(['student', 'subject', 'selector', 'allocator'])
                ->orderBy('subject_id')
                ->orderBy('preference_order')
                ->orderBy('selected_at');
            
            // Apply filters
            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            
            if (isset($filters['student_id'])) {
                $query->where('student_id', $filters['student_id']);
            }
            
            if (isset($filters['subject_id'])) {
                $query->where('subject_id', $filters['subject_id']);
            }
            
            if (isset($filters['is_allocated'])) {
                $query->where('status', 'allocated');
            }
            
            if (isset($filters['is_pending'])) {
                $query->where('status', 'pending');
            }
            
            return $query->get();
        });
    }

    /**
     * Get choices for a student.
     *
     * @param  int  $studentId
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getForStudent($studentId, $filters = [])
    {
        $cacheKey = "elective_choices_student_{$studentId}_" . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHours(6), function () use ($studentId, $filters) {
            $query = self::where('student_id', $studentId)
                ->with(['electiveGroup', 'subject', 'allocator'])
                ->orderBy('selected_at', 'desc');
            
            // Apply filters
            if (isset($filters['elective_group_id'])) {
                $query->where('elective_group_id', $filters['elective_group_id']);
            }
            
            if (isset($filters['academic_year'])) {
                $query->whereHas('electiveGroup', function($q) use ($filters) {
                    $q->where('academic_year', $filters['academic_year']);
                });
            }
            
            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            
            if (isset($filters['is_allocated'])) {
                $query->where('status', 'allocated');
            }
            
            return $query->get();
        });
    }

    /**
     * Get choices for a subject.
     *
     * @param  int  $subjectId
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getForSubject($subjectId, $filters = [])
    {
        $cacheKey = "elective_choices_subject_{$subjectId}_" . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHours(6), function () use ($subjectId, $filters) {
            $query = self::where('subject_id', $subjectId)
                ->with(['student', 'electiveGroup', 'selector'])
                ->orderBy('status')
                ->orderBy('preference_order')
                ->orderBy('selected_at');
            
            // Apply filters
            if (isset($filters['elective_group_id'])) {
                $query->where('elective_group_id', $filters['elective_group_id']);
            }
            
            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            
            return $query->get();
        });
    }

    /**
     * Get waiting list for a subject.
     *
     * @param  int  $subjectId
     * @param  int  $electiveGroupId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getWaitingList($subjectId, $electiveGroupId)
    {
        $cacheKey = "elective_choices_waiting_list_subject_{$subjectId}_group_{$electiveGroupId}";
        
        return Cache::remember($cacheKey, now()->addHour(), function () use ($subjectId, $electiveGroupId) {
            return self::where('subject_id', $subjectId)
                ->where('elective_group_id', $electiveGroupId)
                ->where('status', 'waiting_list')
                ->with(['student'])
                ->orderBy('selected_at', 'asc')
                ->get();
        });
    }

    /**
     * Get allocation statistics for an elective group.
     *
     * @param  int  $electiveGroupId
     * @return array
     */
    public static function getAllocationStatistics($electiveGroupId)
    {
        $cacheKey = "elective_allocation_statistics_group_{$electiveGroupId}";
        
        return Cache::remember($cacheKey, now()->addHours(6), function () use ($electiveGroupId) {
            $totalChoices = self::where('elective_group_id', $electiveGroupId)->count();
            $allocatedChoices = self::where('elective_group_id', $electiveGroupId)
                ->where('status', 'allocated')->count();
            $pendingChoices = self::where('elective_group_id', $electiveGroupId)
                ->where('status', 'pending')->count();
            $waitingListChoices = self::where('elective_group_id', $electiveGroupId)
                ->where('status', 'waiting_list')->count();
            $rejectedChoices = self::where('elective_group_id', $electiveGroupId)
                ->where('status', 'rejected')->count();
            
            // Get subject-wise allocation
            $subjectAllocations = DB::table('elective_choices')
                ->join('subjects', 'elective_choices.subject_id', '=', 'subjects.id')
                ->where('elective_choices.elective_group_id', $electiveGroupId)
                ->where('elective_choices.status', 'allocated')
                ->selectRaw('subjects.id, subjects.name, COUNT(*) as allocated_count')
                ->groupBy('subjects.id', 'subjects.name')
                ->orderBy('allocated_count', 'desc')
                ->get();
            
            // Get preference distribution
            $preferenceDistribution = DB::table('elective_choices')
                ->where('elective_group_id', $electiveGroupId)
                ->whereNotNull('preference_order')
                ->selectRaw('preference_order, COUNT(*) as count')
                ->groupBy('preference_order')
                ->orderBy('preference_order')
                ->pluck('count', 'preference_order')
                ->toArray();
            
            return [
                'total_choices' => $totalChoices,
                'allocated' => $allocatedChoices,
                'pending' => $pendingChoices,
                'waiting_list' => $waitingListChoices,
                'rejected' => $rejectedChoices,
                'allocation_rate' => $totalChoices > 0 ? round(($allocatedChoices / $totalChoices) * 100, 2) : 0,
                'subject_allocations' => $subjectAllocations,
                'preference_distribution' => $preferenceDistribution,
                'average_preferences_per_student' => $totalChoices > 0 ? round($totalChoices / self::where('elective_group_id', $electiveGroupId)->distinct('student_id')->count('student_id'), 2) : 0
            ];
        });
    }

    /**
     * Export choice data to array.
     *
     * @return array
     */
    public function exportToArray()
    {
        return [
            'id' => $this->id,
            'elective_group' => $this->electiveGroup ? $this->electiveGroup->name : null,
            'academic_year' => $this->electiveGroup ? $this->electiveGroup->academic_year : null,
            'student' => $this->student ? $this->student->name : null,
            'admission_number' => $this->student ? $this->student->admission_number : null,
            'grade_level' => $this->student && $this->student->gradeLevel ? $this->student->gradeLevel->name : null,
            'subject' => $this->subject ? $this->subject->name : null,
            'subject_code' => $this->subject ? $this->subject->code : null,
            'preference_order' => $this->preference_order,
            'status' => $this->status_display,
            'selected_at' => $this->selected_at,
            'selected_by' => $this->selected_by_name,
            'allocated_at' => $this->allocated_at,
            'allocated_by' => $this->allocated_by_name,
            'rejected_at' => $this->rejected_at,
            'rejected_by' => $this->rejected_by_name,
            'rejection_reason' => $this->rejection_reason,
            'waiting_list_position' => $this->waiting_list_position,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at
        ];
    }

    /**
     * Scope a query to only include allocated choices.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAllocated($query)
    {
        return $query->where('status', 'allocated');
    }

    /**
     * Scope a query to only include pending choices.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include waiting list choices.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWaitingList($query)
    {
        return $query->where('status', 'waiting_list');
    }

    /**
     * Scope a query to only include choices with specific preference order.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $preferenceOrder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithPreference($query, $preferenceOrder)
    {
        return $query->where('preference_order', $preferenceOrder);
    }

    /**
     * Scope a query to only include choices for specific elective group.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $electiveGroupId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForElectiveGroup($query, $electiveGroupId)
    {
        return $query->where('elective_group_id', $electiveGroupId);
    }

    /**
     * Scope a query to only include choices for specific student.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $studentId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForStudent($query, $studentId)
    {
        return $query->where('student_id', $studentId);
    }

    /**
     * Scope a query to only include choices for specific subject.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $subjectId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForSubject($query, $subjectId)
    {
        return $query->where('subject_id', $subjectId);
    }

    /**
     * Scope a query to order by selection time (oldest first).
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOldestFirst($query)
    {
        return $query->orderBy('selected_at', 'asc');
    }

    /**
     * Scope a query to order by preference order.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByPreference($query)
    {
        return $query->orderBy('preference_order', 'asc');
    }
}