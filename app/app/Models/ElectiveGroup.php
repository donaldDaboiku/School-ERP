<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;    

class ElectiveGroup extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'elective_groups';

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
        'grade_level_id',
        'section_id',
        'min_electives',
        'max_electives',
        'selection_start_date',
        'selection_end_date',
        'is_active',
        'status',
        'capacity_per_subject',
        'allow_student_preference',
        'preference_priority_count',
        'allocation_method',
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
        'min_electives' => 'integer',
        'max_electives' => 'integer',
        'capacity_per_subject' => 'integer',
        'selection_start_date' => 'date',
        'selection_end_date' => 'date',
        'is_active' => 'boolean',
        'allow_student_preference' => 'boolean',
        'preference_priority_count' => 'integer',
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
        'allocation_method_display',
        'selection_period',
        'is_selection_open',
        'days_until_selection_end',
        'subjects_count',
        'students_enrolled_count',
        'available_slots'
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $with = ['gradeLevel', 'section', 'creator', 'updater'];

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($group) {
            // Generate code if not provided
            if (empty($group->code)) {
                $group->code = self::generateElectiveGroupCode($group);
            }
            
            // Set default min_electives
            if (is_null($group->min_electives)) {
                $group->min_electives = 1;
            }
            
            // Set default max_electives
            if (is_null($group->max_electives)) {
                $group->max_electives = 3;
            }
            
            // Set default is_active
            if (is_null($group->is_active)) {
                $group->is_active = true;
            }
            
            // Set default status
            if (empty($group->status)) {
                $group->status = 'draft';
            }
            
            // Set default allocation_method
            if (empty($group->allocation_method)) {
                $group->allocation_method = 'preference_based';
            }
            
            // Set created_by if not set
            if (empty($group->created_by) && Auth::check()) {
                $group->created_by = Auth::id();
            }
            
            // Validate elective group
            self::validateElectiveGroup($group);
            
            Log::info('Elective group creating', [
                'name' => $group->name,
                'code' => $group->code,
                'academic_year' => $group->academic_year,
                'created_by' => $group->created_by
            ]);
        });

        static::updating(function ($group) {
            // Update updated_by
            if (Auth::check()) {
                $group->updated_by = Auth::id();
            }
            
            // Validate elective group on update
            self::validateElectiveGroup($group);
            
            // Prevent deactivation if there are enrolled students
            if ($group->isDirty('is_active') && !$group->is_active) {
                $enrolledStudents = $group->students()->count();
                if ($enrolledStudents > 0) {
                    throw new \Exception("Cannot deactivate elective group with {$enrolledStudents} enrolled students");
                }
            }
            
            // Validate selection dates
            if ($group->isDirty('selection_start_date') || $group->isDirty('selection_end_date')) {
                if ($group->selection_start_date && $group->selection_end_date) {
                    if ($group->selection_start_date > $group->selection_end_date) {
                        throw new \Exception('Selection start date must be before end date');
                    }
                }
            }
        });

        static::saved(function ($group) {
            // Clear relevant cache
            Cache::forget("elective_group_{$group->id}");
            Cache::forget("elective_group_code_{$group->code}");
            Cache::tags([
                "elective_groups_grade_{$group->grade_level_id}",
                "elective_groups_academic_year_{$group->academic_year}",
                "elective_groups_active"
            ])->flush();
        });

        static::deleted(function ($group) {
            // Clear cache
            Cache::forget("elective_group_{$group->id}");
            Cache::forget("elective_group_code_{$group->code}");
            Cache::tags([
                "elective_groups_grade_{$group->grade_level_id}",
                "elective_groups_academic_year_{$group->academic_year}",
                "elective_groups_active"
            ])->flush();
            
            // Delete related elective choices
            $group->electiveChoices()->delete();
        });
    }

    /**
     * Get the grade level for this elective group.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function gradeLevel()
    {
        return $this->belongsTo(GradeLevel::class, 'grade_level_id');
    }

    /**
     * Get the section for this elective group.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function section()
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    /**
     * Get the user who created this elective group.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this elective group.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get the subjects in this elective group.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function subjects()
    {
        return $this->belongsToMany(Subject::class, 'elective_group_subjects', 'elective_group_id', 'subject_id')
            ->withPivot([
                'capacity',
                'current_enrollment',
                'min_required',
                'max_allowed',
                'teacher_id',
                'schedule_info',
                'room_requirements',
                'additional_fee',
                'prerequisites',
                'is_active'
            ])
            ->withTimestamps();
    }

    /**
     * Get the elective choices (student selections).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function electiveChoices()
    {
        return $this->hasMany(ElectiveChoice::class, 'elective_group_id');
    }

    /**
     * Get the students enrolled in this elective group.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function students()
    {
        return $this->belongsToMany(Student::class, 'elective_choices', 'elective_group_id', 'student_id')
            ->withPivot(['subject_id', 'preference_order', 'status', 'allocated_at', 'allocated_by'])
            ->withTimestamps();
    }

    /**
     * Get the teachers for subjects in this elective group.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function teachers()
    {
        return $this->belongsToMany(Teacher::class, 'elective_group_subjects', 'elective_group_id', 'teacher_id')
            ->withPivot(['subject_id'])
            ->withTimestamps();
    }

    /**
     * Get the full elective group name.
     *
     * @return string
     */
    public function getFullNameAttribute()
    {
        $name = $this->name;
        
        if ($this->gradeLevel) {
            $name .= " - {$this->gradeLevel->name}";
        }
        
        if ($this->section) {
            $name .= " ({$this->section->name})";
        }
        
        if ($this->academic_year) {
            $name .= " [{$this->academic_year}]";
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
            'draft' => 'Draft',
            'open_for_selection' => 'Open for Selection',
            'selection_closed' => 'Selection Closed',
            'allocation_in_progress' => 'Allocation in Progress',
            'allocation_completed' => 'Allocation Completed',
            'published' => 'Published',
            'archived' => 'Archived',
            'cancelled' => 'Cancelled'
        ];
        
        return $statuses[$this->status] ?? ucfirst($this->status);
    }

    /**
     * Get allocation method display name.
     *
     * @return string
     */
    public function getAllocationMethodDisplayAttribute()
    {
        $methods = [
            'preference_based' => 'Preference Based',
            'first_come_first_serve' => 'First Come First Serve',
            'random_allocation' => 'Random Allocation',
            'merit_based' => 'Merit Based',
            'manual' => 'Manual Allocation'
        ];
        
        return $methods[$this->allocation_method] ?? ucfirst($this->allocation_method);
    }

    /**
     * Get selection period.
     *
     * @return string|null
     */
    public function getSelectionPeriodAttribute()
    {
        if (!$this->selection_start_date || !$this->selection_end_date) {
            return null;
        }
        
        return $this->selection_start_date->format('M d, Y') . ' - ' . 
               $this->selection_end_date->format('M d, Y');
    }

    /**
     * Check if selection is open.
     *
     * @return bool
     */
    public function getIsSelectionOpenAttribute()
    {
        if ($this->status !== 'open_for_selection') {
            return false;
        }
        
        $today = now();
        
        if ($this->selection_start_date && $today < $this->selection_start_date) {
            return false;
        }
        
        if ($this->selection_end_date && $today > $this->selection_end_date) {
            return false;
        }
        
        return true;
    }

    /**
     * Get days until selection end.
     *
     * @return int|null
     */
    public function getDaysUntilSelectionEndAttribute()
    {
        if (!$this->selection_end_date || !$this->is_selection_open) {
            return null;
        }
        
        return now()->diffInDays($this->selection_end_date, false);
    }

    /**
     * Get subjects count.
     *
     * @return int
     */
    public function getSubjectsCountAttribute()
    {
        return $this->subjects()->count();
    }

    /**
     * Get students enrolled count.
     *
     * @return int
     */
    public function getStudentsEnrolledCountAttribute()
    {
        return $this->students()->count();
    }

    /**
     * Get available slots.
     *
     * @return array
     */
    public function getAvailableSlotsAttribute()
    {
        $slots = [];
        $totalCapacity = 0;
        $totalEnrolled = 0;
        
        foreach ($this->subjects as $subject) {
            $capacity = $subject->pivot->capacity ?? 0;
            $enrolled = $subject->pivot->current_enrollment ?? 0;
            $available = max(0, $capacity - $enrolled);
            
            $slots[] = [
                'subject_id' => $subject->id,
                'subject_name' => $subject->name,
                'capacity' => $capacity,
                'enrolled' => $enrolled,
                'available' => $available,
                'is_full' => $available === 0
            ];
            
            $totalCapacity += $capacity;
            $totalEnrolled += $enrolled;
        }
        
        return [
            'subjects' => $slots,
            'total_capacity' => $totalCapacity,
            'total_enrolled' => $totalEnrolled,
            'total_available' => max(0, $totalCapacity - $totalEnrolled)
        ];
    }

    /**
     * Add subject to elective group.
     *
     * @param  Subject  $subject
     * @param  array  $pivotData
     * @return bool
     */
    public function addSubject($subject, $pivotData = [])
    {
        // Check if subject already exists in group
        if ($this->subjects()->where('subject_id', $subject->id)->exists()) {
            throw new \Exception('Subject already exists in elective group');
        }
        
        $defaultPivotData = [
            'capacity' => $this->capacity_per_subject,
            'current_enrollment' => 0,
            'min_required' => 1,
            'max_allowed' => 1,
            'is_active' => true
        ];
        
        $pivotData = array_merge($defaultPivotData, $pivotData);
        
        $this->subjects()->attach($subject->id, $pivotData);
        
        Log::info('Subject added to elective group', [
            'elective_group_id' => $this->id,
            'subject_id' => $subject->id,
            'added_by' => Auth::id()
        ]);
        
        return true;
    }

    /**
     * Remove subject from elective group.
     *
     * @param  Subject  $subject
     * @return bool
     */
    public function removeSubject($subject)
    {
        // Check if subject has enrolled students
        $enrolledCount = $this->electiveChoices()
            ->where('subject_id', $subject->id)
            ->where('status', 'allocated')
            ->count();
            
        if ($enrolledCount > 0) {
            throw new \Exception("Cannot remove subject with {$enrolledCount} enrolled students");
        }
        
        $this->subjects()->detach($subject->id);
        
        Log::info('Subject removed from elective group', [
            'elective_group_id' => $this->id,
            'subject_id' => $subject->id,
            'removed_by' => Auth::id()
        ]);
        
        return true;
    }

    /**
     * Open selection period.
     *
     * @return bool
     */
    public function openSelection()
    {
        if ($this->status === 'open_for_selection') {
            throw new \Exception('Selection is already open');
        }
        
        if ($this->subjects_count === 0) {
            throw new \Exception('Cannot open selection without subjects');
        }
        
        if (!$this->selection_start_date || !$this->selection_end_date) {
            throw new \Exception('Selection dates must be set before opening selection');
        }
        
        $this->status = 'open_for_selection';
        $this->save();
        
        Log::info('Elective group selection opened', [
            'elective_group_id' => $this->id,
            'opened_by' => Auth::id()
        ]);
        
        // Notify students
        $this->notifySelectionOpening();
        
        return true;
    }

    /**
     * Close selection period.
     *
     * @return bool
     */
    public function closeSelection()
    {
        if ($this->status !== 'open_for_selection') {
            throw new \Exception('Selection is not currently open');
        }
        
        $this->status = 'selection_closed';
        $this->save();
        
        Log::info('Elective group selection closed', [
            'elective_group_id' => $this->id,
            'closed_by' => Auth::id()
        ]);
        
        return true;
    }

    /**
     * Student selects electives.
     *
     * @param  Student  $student
     * @param  array  $subjectIds
     * @return array
     */
    public function studentSelectElectives($student, $subjectIds)
    {
        if (!$this->is_selection_open) {
            throw new \Exception('Selection period is not open');
        }
        
        // Validate number of selections
        $selectionCount = count($subjectIds);
        if ($selectionCount < $this->min_electives || $selectionCount > $this->max_electives) {
            throw new \Exception("Must select between {$this->min_electives} and {$this->max_electives} electives");
        }
        
        // Validate subjects exist in group
        $validSubjectIds = $this->subjects()->pluck('subjects.id')->toArray();
        $invalidSubjects = array_diff($subjectIds, $validSubjectIds);
        
        if (!empty($invalidSubjects)) {
            throw new \Exception('Invalid subjects selected');
        }
        
        // Check for duplicate selections
        $uniqueSubjects = array_unique($subjectIds);
        if (count($uniqueSubjects) !== count($subjectIds)) {
            throw new \Exception('Duplicate subjects selected');
        }
        
        // Delete existing choices
        $this->electiveChoices()->where('student_id', $student->id)->delete();
        
        // Create new choices
        $choices = [];
        foreach ($subjectIds as $index => $subjectId) {
            $choice = ElectiveChoice::create([
                'elective_group_id' => $this->id,
                'student_id' => $student->id,
                'subject_id' => $subjectId,
                'preference_order' => $this->allow_student_preference ? $index + 1 : null,
                'status' => 'pending',
                'selected_at' => now(),
                'selected_by' => $student->id
            ]);
            
            $choices[] = $choice;
        }
        
        Log::info('Student selected electives', [
            'elective_group_id' => $this->id,
            'student_id' => $student->id,
            'subject_count' => count($subjectIds),
            'selected_by' => Auth::id()
        ]);
        
        return $choices;
    }

    /**
     * Allocate electives to students.
     *
     * @return array
     */
    public function allocateElectives()
    {
        if ($this->status !== 'selection_closed') {
            throw new \Exception('Cannot allocate electives before selection is closed');
        }
        
        $this->status = 'allocation_in_progress';
        $this->save();
        
        $results = [];
        
        switch ($this->allocation_method) {
            case 'preference_based':
                $results = $this->allocateByPreference();
                break;
            case 'first_come_first_serve':
                $results = $this->allocateByFirstComeFirstServe();
                break;
            case 'random_allocation':
                $results = $this->allocateRandomly();
                break;
            case 'merit_based':
                $results = $this->allocateByMerit();
                break;
            case 'manual':
                // Manual allocation - do nothing
                $results = [
                    'total_students' => 0,
                    'allocated' => 0,
                    'failed' => 0,
                    'method' => 'manual'
                ];
                break;
        }
        
        $this->status = 'allocation_completed';
        $this->save();
        
        Log::info('Elective allocation completed', [
            'elective_group_id' => $this->id,
            'results' => $results,
            'allocated_by' => Auth::id()
        ]);
        
        return $results;
    }

    /**
     * Allocate electives by preference.
     *
     * @return array
     */
    private function allocateByPreference()
    {
        // Get all student choices grouped by student
        $studentChoices = $this->electiveChoices()
            ->with(['student', 'subject'])
            ->where('status', 'pending')
            ->orderBy('preference_order')
            ->get()
            ->groupBy('student_id');
        
        $allocatedCount = 0;
        $failedStudents = [];
        
        // Track subject capacities
        $subjectCapacities = [];
        foreach ($this->subjects as $subject) {
            $subjectCapacities[$subject->id] = [
                'capacity' => $subject->pivot->capacity,
                'allocated' => 0
            ];
        }
        
        foreach ($studentChoices as $studentId => $choices) {
            $allocated = false;
            
            foreach ($choices as $choice) {
                $subjectId = $choice->subject_id;
                
                // Check if subject has capacity
                if ($subjectCapacities[$subjectId]['allocated'] < $subjectCapacities[$subjectId]['capacity']) {
                    // Allocate this subject to student
                    $choice->status = 'allocated';
                    $choice->allocated_at = now();
                    $choice->allocated_by = Auth::id();
                    $choice->save();
                    
                    // Update subject capacity
                    $subjectCapacities[$subjectId]['allocated']++;
                    
                    // Update subject enrollment
                    $this->subjects()->updateExistingPivot($subjectId, [
                        'current_enrollment' => $subjectCapacities[$subjectId]['allocated']
                    ]);
                    
                    $allocated = true;
                    $allocatedCount++;
                    break;
                }
            }
            
            if (!$allocated) {
                $failedStudents[] = $studentId;
            }
        }
        
        return [
            'total_students' => count($studentChoices),
            'allocated' => $allocatedCount,
            'failed' => count($failedStudents),
            'failed_students' => $failedStudents,
            'subject_allocations' => $subjectCapacities,
            'method' => 'preference_based'
        ];
    }

    /**
     * Allocate by first come first serve.
     *
     * @return array
     */
    private function allocateByFirstComeFirstServe()
    {
        // Implementation would depend on timestamp of selection
        // Placeholder implementation
        return [
            'total_students' => 0,
            'allocated' => 0,
            'failed' => 0,
            'method' => 'first_come_first_serve'
        ];
    }

    /**
     * Allocate randomly.
     *
     * @return array
     */
    private function allocateRandomly()
    {
        // Implementation for random allocation
        // Placeholder implementation
        return [
            'total_students' => 0,
            'allocated' => 0,
            'failed' => 0,
            'method' => 'random_allocation'
        ];
    }

    /**
     * Allocate by merit.
     *
     * @return array
     */
    private function allocateByMerit()
    {
        // Implementation would consider student grades/performance
        // Placeholder implementation
        return [
            'total_students' => 0,
            'allocated' => 0,
            'failed' => 0,
            'method' => 'merit_based'
        ];
    }

    /**
     * Notify students about selection opening.
     *
     * @return void
     */
    private function notifySelectionOpening()
    {
        // Implementation would depend on your notification system
        // This is a placeholder for notification logic
        Log::info('Notifying students about elective selection opening', [
            'elective_group_id' => $this->id,
            'selection_period' => $this->selection_period
        ]);
    }

    /**
     * Get elective group statistics.
     *
     * @return array
     */
    public function getStatistics()
    {
        $totalStudents = $this->gradeLevel ? $this->gradeLevel->student_count : 0;
        $enrolledStudents = $this->students_enrolled_count;
        $pendingChoices = $this->electiveChoices()->where('status', 'pending')->count();
        $allocatedChoices = $this->electiveChoices()->where('status', 'allocated')->count();
        
        // Get subject-wise enrollment
        $subjectEnrollment = [];
        foreach ($this->subjects as $subject) {
            $enrollment = $this->electiveChoices()
                ->where('subject_id', $subject->id)
                ->where('status', 'allocated')
                ->count();
                
            $capacity = $subject->pivot->capacity ?? 0;
            $enrollmentRate = $capacity > 0 ? round(($enrollment / $capacity) * 100, 2) : 0;
            
            $subjectEnrollment[] = [
                'subject_id' => $subject->id,
                'subject_name' => $subject->name,
                'capacity' => $capacity,
                'enrolled' => $enrollment,
                'enrollment_rate' => $enrollmentRate,
                'is_full' => $enrollment >= $capacity
            ];
        }
        
        // Get gender distribution
        $maleCount = $this->students()->where('gender', 'male')->count();
        $femaleCount = $this->students()->where('gender', 'female')->count();
        
        return [
            'total_students' => $totalStudents,
            'enrolled_students' => $enrolledStudents,
            'enrollment_rate' => $totalStudents > 0 ? round(($enrolledStudents / $totalStudents) * 100, 2) : 0,
            'pending_choices' => $pendingChoices,
            'allocated_choices' => $allocatedChoices,
            'subject_enrollment' => $subjectEnrollment,
            'gender_distribution' => [
                'male' => $maleCount,
                'female' => $femaleCount,
                'total' => $maleCount + $femaleCount
            ],
            'selection_status' => $this->status,
            'is_selection_open' => $this->is_selection_open,
            'days_until_selection_end' => $this->days_until_selection_end
        ];
    }

    /**
     * Generate elective group code.
     *
     * @param  ElectiveGroup  $group
     * @return string
     */
    private static function generateElectiveGroupCode($group)
    {
        $academicYear = substr(str_replace('/', '', $group->academic_year), 0, 4);
        $gradeCode = $group->gradeLevel ? $group->gradeLevel->code : 'GEN';
        $type = 'ELEC';
        
        do {
            $random = strtoupper(\Illuminate\Support\Str::random(4));
            $code = "{$type}{$academicYear}{$gradeCode}{$random}";
        } while (self::where('code', $code)->exists());
        
        return $code;
    }

    /**
     * Validate elective group.
     *
     * @param  ElectiveGroup  $group
     * @return void
     * @throws \Exception
     */
    private static function validateElectiveGroup($group)
    {
        // Check if elective group code is unique
        if ($group->code) {
            $existingGroup = self::where('code', $group->code)
                ->where('id', '!=', $group->id)
                ->first();
                
            if ($existingGroup) {
                throw new \Exception('Elective group code already exists');
            }
        }
        
        // Validate min and max electives
        if ($group->min_electives > $group->max_electives) {
            throw new \Exception('Minimum electives cannot be greater than maximum electives');
        }
        
        if ($group->min_electives <= 0) {
            throw new \Exception('Minimum electives must be greater than 0');
        }
        
        // Validate capacity per subject
        if ($group->capacity_per_subject && $group->capacity_per_subject <= 0) {
            throw new \Exception('Capacity per subject must be greater than 0');
        }
        
        // Validate preference priority count
        if ($group->allow_student_preference && $group->preference_priority_count) {
            if ($group->preference_priority_count < $group->min_electives || 
                $group->preference_priority_count > $group->max_electives) {
                throw new \Exception('Preference priority count must be between min and max electives');
            }
        }
    }

    /**
     * Get elective groups for a grade level.
     *
     * @param  int  $gradeLevelId
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getForGradeLevel($gradeLevelId, $filters = [])
    {
        $cacheKey = "elective_groups_grade_{$gradeLevelId}_" . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHours(6), function () use ($gradeLevelId, $filters) {
            $query = self::where('grade_level_id', $gradeLevelId)
                ->with(['gradeLevel', 'section', 'creator'])
                ->orderBy('academic_year', 'desc')
                ->orderBy('created_at', 'desc');
            
            // Apply filters
            if (isset($filters['academic_year'])) {
                $query->where('academic_year', $filters['academic_year']);
            }
            
            if (isset($filters['is_active'])) {
                $query->where('is_active', $filters['is_active']);
            }
            
            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            
            if (isset($filters['has_open_selection'])) {
                if ($filters['has_open_selection']) {
                    $query->where('status', 'open_for_selection')
                          ->whereDate('selection_start_date', '<=', now())
                          ->whereDate('selection_end_date', '>=', now());
                }
            }
            
            return $query->get();
        });
    }

    /**
     * Get active elective groups.
     *
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getActive($filters = [])
    {
        $cacheKey = 'elective_groups_active_' . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHours(6), function () use ($filters) {
            $query = self::where('is_active', true)
                ->with(['gradeLevel', 'section'])
                ->orderBy('academic_year', 'desc')
                ->orderBy('grade_level_id')
                ->orderBy('name');
            
            // Apply filters
            if (isset($filters['academic_year'])) {
                $query->where('academic_year', $filters['academic_year']);
            }
            
            if (isset($filters['grade_level_id'])) {
                $query->where('grade_level_id', $filters['grade_level_id']);
            }
            
            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            
            return $query->get();
        });
    }

    /**
     * Get elective group by code.
     *
     * @param  string  $code
     * @return ElectiveGroup|null
     */
    public static function getByCode($code)
    {
        return Cache::remember("elective_group_code_{$code}", now()->addHours(12), function () use ($code) {
            return self::where('code', $code)->first();
        });
    }

    /**
     * Clone elective group.
     *
     * @param  string  $newName
     * @param  array  $overrides
     * @return ElectiveGroup
     */
    public function clone($newName, $overrides = [])
    {
        $newGroup = $this->replicate();
        $newGroup->name = $newName;
        $newGroup->code = self::generateElectiveGroupCode($newGroup);
        $newGroup->status = 'draft';
        
        // Apply overrides
        foreach ($overrides as $key => $value) {
            if (in_array($key, $newGroup->fillable)) {
                $newGroup->$key = $value;
            }
        }
        
        $newGroup->save();
        
        // Clone subjects
        foreach ($this->subjects as $subject) {
            $newGroup->subjects()->attach($subject->id, [
                'capacity' => $subject->pivot->capacity,
                'current_enrollment' => 0,
                'min_required' => $subject->pivot->min_required,
                'max_allowed' => $subject->pivot->max_allowed,
                'teacher_id' => $subject->pivot->teacher_id,
                'schedule_info' => $subject->pivot->schedule_info,
                'room_requirements' => $subject->pivot->room_requirements,
                'additional_fee' => $subject->pivot->additional_fee,
                'prerequisites' => $subject->pivot->prerequisites,
                'is_active' => $subject->pivot->is_active
            ]);
        }
        
        Log::info('Elective group cloned', [
            'original_id' => $this->id,
            'new_id' => $newGroup->id,
            'new_name' => $newName,
            'cloned_by' => Auth::id()
        ]);
        
        return $newGroup;
    }

    /**
     * Export elective group data to array.
     *
     * @return array
     */
    public function exportToArray()
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'academic_year' => $this->academic_year,
            'grade_level' => $this->gradeLevel ? $this->gradeLevel->name : null,
            'section' => $this->section ? $this->section->name : null,
            'min_electives' => $this->min_electives,
            'max_electives' => $this->max_electives,
            'selection_period' => $this->selection_period,
            'selection_start_date' => $this->selection_start_date,
            'selection_end_date' => $this->selection_end_date,
            'is_selection_open' => $this->is_selection_open,
            'days_until_selection_end' => $this->days_until_selection_end,
            'capacity_per_subject' => $this->capacity_per_subject,
            'allow_student_preference' => $this->allow_student_preference,
            'preference_priority_count' => $this->preference_priority_count,
            'allocation_method' => $this->allocation_method,
            'allocation_method_display' => $this->allocation_method_display,
            'status' => $this->status,
            'status_display' => $this->status_display,
            'is_active' => $this->is_active,
            'subjects_count' => $this->subjects_count,
            'students_enrolled_count' => $this->students_enrolled_count,
            'available_slots' => $this->available_slots,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at
        ];
    }

    /**
     * Scope a query to only include active elective groups.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include elective groups with open selection.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithOpenSelection($query)
    {
        return $query->where('status', 'open_for_selection')
                     ->whereDate('selection_start_date', '<=', now())
                     ->whereDate('selection_end_date', '>=', now());
    }

    /**
     * Scope a query to only include elective groups for a specific academic year.
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
     * Scope a query to only include elective groups with a specific allocation method.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $allocationMethod
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAllocationMethod($query, $allocationMethod)
    {
        return $query->where('allocation_method', $allocationMethod);
    }
}