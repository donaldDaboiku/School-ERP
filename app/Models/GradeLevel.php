<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;    

class GradeLevel extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'grade_levels';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'code',
        'description',
        'level_order',
        'age_group',
        'curriculum',
        'min_age',
        'max_age',
        'is_active',
        'next_grade_id',
        'previous_grade_id',
        'academic_requirements',
        'fees_structure_id',
        'created_by',
        'updated_by'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'level_order' => 'integer',
        'min_age' => 'integer',
        'max_age' => 'integer',
        'is_active' => 'boolean',
        'academic_requirements' => 'json',
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
        'student_count',
        'teacher_count',
        'has_fees_structure',
        'next_grade_name',
        'previous_grade_name'
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $with = ['nextGrade', 'previousGrade', 'feesStructure', 'creator', 'updater'];

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($gradeLevel) {
            // Generate code if not provided
            if (empty($gradeLevel->code)) {
                $gradeLevel->code = self::generateGradeCode($gradeLevel);
            }
            
            // Set level order if not provided
            if (empty($gradeLevel->level_order)) {
                $maxOrder = self::max('level_order') ?? 0;
                $gradeLevel->level_order = $maxOrder + 1;
            }
            
            // Set default is_active
            if (is_null($gradeLevel->is_active)) {
                $gradeLevel->is_active = true;
            }
            
            // Set created_by if not set
            if (empty($gradeLevel->created_by) && Auth::check()) {
                $gradeLevel->created_by = Auth::id();
            }
            
            // Validate grade level
            self::validateGradeLevel($gradeLevel);
            
            Log::info('Grade level creating', [
                'name' => $gradeLevel->name,
                'code' => $gradeLevel->code,
                'level_order' => $gradeLevel->level_order,
                'created_by' => $gradeLevel->created_by
            ]);
        });

        static::updating(function ($gradeLevel) {
            // Update updated_by
            if (Auth::check()) {
                $gradeLevel->updated_by = Auth::id();
            }
            
            // Validate grade level on update
            self::validateGradeLevel($gradeLevel);
            
            // Prevent deactivation if there are active students
            if ($gradeLevel->isDirty('is_active') && !$gradeLevel->is_active) {
                $activeStudents = $gradeLevel->students()->where('status', 'active')->count();
                if ($activeStudents > 0) {
                    throw new \Exception("Cannot deactivate grade level with {$activeStudents} active students");
                }
            }
            
            // Validate circular references for next/previous grade
            if ($gradeLevel->next_grade_id == $gradeLevel->id) {
                throw new \Exception('Grade level cannot be its own next grade');
            }
            
            if ($gradeLevel->previous_grade_id == $gradeLevel->id) {
                throw new \Exception('Grade level cannot be its own previous grade');
            }
        });

        static::saved(function ($gradeLevel) {
            // Clear relevant cache
            Cache::forget("grade_level_{$gradeLevel->id}");
            Cache::forget("grade_level_code_{$gradeLevel->code}");
            Cache::tags(['grade_levels', 'grade_levels_active', 'grade_levels_order'])->flush();
        });

        static::deleted(function ($gradeLevel) {
            // Clear cache
            Cache::forget("grade_level_{$gradeLevel->id}");
            Cache::forget("grade_level_code_{$gradeLevel->code}");
            Cache::tags(['grade_levels', 'grade_levels_active', 'grade_levels_order'])->flush();
            
            // Update related grade levels
            self::where('next_grade_id', $gradeLevel->id)->update(['next_grade_id' => null]);
            self::where('previous_grade_id', $gradeLevel->id)->update(['previous_grade_id' => null]);
        });
    }

    /**
     * Get the next grade level.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function nextGrade()
    {
        return $this->belongsTo(self::class, 'next_grade_id');
    }

    /**
     * Get the previous grade level.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function previousGrade()
    {
        return $this->belongsTo(self::class, 'previous_grade_id');
    }

    /**
     * Get the fees structure for this grade level.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function feesStructure()
    {
        return $this->belongsTo(FeesStructure::class, 'fees_structure_id');
    }

    /**
     * Get the user who created this grade level.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this grade level.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get the students in this grade level.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function students()
    {
        return $this->hasMany(Student::class, 'grade_level_id');
    }

    /**
     * Get the teachers for this grade level.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function teachers()
    {
        return $this->belongsToMany(Teacher::class, 'grade_level_teachers', 'grade_level_id', 'teacher_id')
            ->withPivot(['subject', 'is_homeroom_teacher', 'academic_year'])
            ->withTimestamps();
    }

    /**
     * Get the timetables for this grade level.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function timetables()
    {
        return $this->hasMany(Timetable::class, 'grade_level_id');
    }

    /**
     * Get the sections for this grade level.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function sections()
    {
        return $this->hasMany(Section::class, 'grade_level_id');
    }

    /**
     * Get the full grade level name.
     *
     * @return string
     */
    public function getFullNameAttribute()
    {
        $name = $this->name;
        
        if ($this->code) {
            $name .= " ({$this->code})";
        }
        
        return $name;
    }

    /**
     * Get student count.
     *
     * @return int
     */
    public function getStudentCountAttribute()
    {
        return Cache::remember("grade_level_{$this->id}_student_count", now()->addHour(), function () {
            return $this->students()->active()->count();
        });
    }

    /**
     * Get teacher count.
     *
     * @return int
     */
    public function getTeacherCountAttribute()
    {
        return Cache::remember("grade_level_{$this->id}_teacher_count", now()->addHour(), function () {
            return $this->teachers()->active()->count();
        });
    }

    /**
     * Check if grade level has fees structure.
     *
     * @return bool
     */
    public function getHasFeesStructureAttribute()
    {
        return !is_null($this->fees_structure_id);
    }

    /**
     * Get next grade name.
     *
     * @return string|null
     */
    public function getNextGradeNameAttribute()
    {
        return $this->nextGrade ? $this->nextGrade->name : null;
    }

    /**
     * Get previous grade name.
     *
     * @return string|null
     */
    public function getPreviousGradeNameAttribute()
    {
        return $this->previousGrade ? $this->previousGrade->name : null;
    }

    /**
     * Get academic requirements as list.
     *
     * @return array
     */
    public function getRequirementsList()
    {
        if (!$this->academic_requirements || !is_array($this->academic_requirements)) {
            return [];
        }
        
        $requirements = [];
        foreach ($this->academic_requirements as $requirement) {
            $requirements[] = [
                'type' => $requirement['type'] ?? 'general',
                'description' => $requirement['description'] ?? '',
                'is_mandatory' => $requirement['is_mandatory'] ?? true
            ];
        }
        
        return $requirements;
    }

    /**
     * Check if student meets age requirements.
     *
     * @param  int  $age
     * @return array
     */
    public function checkAgeRequirements($age)
    {
        $meetsRequirements = true;
        $messages = [];
        
        if ($this->min_age && $age < $this->min_age) {
            $meetsRequirements = false;
            $messages[] = "Student age ({$age}) is below minimum requirement ({$this->min_age})";
        }
        
        if ($this->max_age && $age > $this->max_age) {
            $meetsRequirements = false;
            $messages[] = "Student age ({$age}) is above maximum requirement ({$this->max_age})";
        }
        
        return [
            'meets_requirements' => $meetsRequirements,
            'messages' => $messages
        ];
    }

    /**
     * Get progression path.
     *
     * @return array
     */
    public function getProgressionPath()
    {
        $path = [];
        $currentGrade = $this;
        
        // Get previous grades
        while ($currentGrade->previousGrade) {
            $path[] = [
                'grade' => $currentGrade->previousGrade,
                'direction' => 'previous',
                'level_difference' => $this->level_order - $currentGrade->previousGrade->level_order
            ];
            $currentGrade = $currentGrade->previousGrade;
        }
        
        // Reset and get next grades
        $currentGrade = $this;
        while ($currentGrade->nextGrade) {
            $path[] = [
                'grade' => $currentGrade->nextGrade,
                'direction' => 'next',
                'level_difference' => $currentGrade->nextGrade->level_order - $this->level_order
            ];
            $currentGrade = $currentGrade->nextGrade;
        }
        
        // Sort by level difference
        usort($path, function($a, $b) {
            return $a['level_difference'] <=> $b['level_difference'];
        });
        
        return $path;
    }

    /**
     * Get statistics for this grade level.
     *
     * @return array
     */
    public function getStatistics()
    {
        $totalStudents = $this->student_count;
        $activeStudents = $this->students()->active()->count();
        $maleStudents = $this->students()->where('gender', 'male')->active()->count();
        $femaleStudents = $this->students()->where('gender', 'female')->active()->count();
        
        $totalTeachers = $this->teacher_count;
        $homeroomTeachers = $this->teachers()->wherePivot('is_homeroom_teacher', true)->active()->count();
        
        $sectionsCount = $this->sections()->active()->count();
        $averageStudentsPerSection = $sectionsCount > 0 ? round($totalStudents / $sectionsCount, 2) : 0;
        
        // Get student distribution by age
        $ageDistribution = $this->students()
            ->active()
            ->selectRaw('age, COUNT(*) as count')
            ->groupBy('age')
            ->orderBy('age')
            ->pluck('count', 'age')
            ->toArray();
        
        return [
            'total_students' => $totalStudents,
            'active_students' => $activeStudents,
            'male_students' => $maleStudents,
            'female_students' => $femaleStudents,
            'total_teachers' => $totalTeachers,
            'homeroom_teachers' => $homeroomTeachers,
            'sections_count' => $sectionsCount,
            'average_students_per_section' => $averageStudentsPerSection,
            'age_distribution' => $ageDistribution,
            'gender_ratio' => $totalStudents > 0 ? round(($maleStudents / $totalStudents) * 100, 2) : 0,
            'teacher_student_ratio' => $totalStudents > 0 ? round($totalStudents / $totalTeachers, 2) : 0
        ];
    }

    /**
     * Promote students to next grade level.
     *
     * @param  array  $studentIds
     * @return array
     */
    public function promoteStudents($studentIds = [])
    {
        if (!$this->next_grade_id) {
            throw new \Exception('No next grade level defined');
        }
        
        $nextGrade = self::find($this->next_grade_id);
        if (!$nextGrade) {
            throw new \Exception('Next grade level not found');
        }
        
        $query = $this->students()->active();
        
        if (!empty($studentIds)) {
            $query->whereIn('id', $studentIds);
        }
        
        $students = $query->get();
        $promotedCount = 0;
        $failedStudents = [];
        
        foreach ($students as $student) {
            try {
                // Check if student meets promotion criteria
                $promotionEligible = $student->checkPromotionEligibility();
                
                if ($promotionEligible['eligible']) {
                    $student->grade_level_id = $nextGrade->id;
                    $student->save();
                    
                    Log::info('Student promoted', [
                        'student_id' => $student->id,
                        'from_grade' => $this->name,
                        'to_grade' => $nextGrade->name,
                        'promoted_by' => Auth::id()
                    ]);
                    
                    $promotedCount++;
                } else {
                    $failedStudents[] = [
                        'student_id' => $student->id,
                        'student_name' => $student->name,
                        'reasons' => $promotionEligible['reasons']
                    ];
                }
            } catch (\Exception $e) {
                $failedStudents[] = [
                    'student_id' => $student->id,
                    'student_name' => $student->name,
                    'reasons' => [$e->getMessage()]
                ];
            }
        }
        
        return [
            'total_students' => $students->count(),
            'promoted_count' => $promotedCount,
            'failed_count' => count($failedStudents),
            'failed_students' => $failedStudents,
            'next_grade' => $nextGrade->name
        ];
    }

    /**
     * Generate grade code.
     *
     * @param  GradeLevel  $gradeLevel
     * @return string
     */
    private static function generateGradeCode($gradeLevel)
    {
        $nameWords = explode(' ', $gradeLevel->name);
        $code = '';
        
        foreach ($nameWords as $word) {
            $code .= strtoupper(substr($word, 0, 1));
        }
        
        // Add level order if code is short
        if (strlen($code) <= 2) {
            $code .= str_pad($gradeLevel->level_order, 2, '0', STR_PAD_LEFT);
        }
        
        // Ensure uniqueness
        $originalCode = $code;
        $counter = 1;
        
        while (self::where('code', $code)->exists()) {
            $code = $originalCode . $counter;
            $counter++;
        }
        
        return $code;
    }

    /**
     * Validate grade level.
     *
     * @param  GradeLevel  $gradeLevel
     * @return void
     * @throws \Exception
     */
    private static function validateGradeLevel($gradeLevel)
    {
        // Check if grade code is unique
        if ($gradeLevel->code) {
            $existingGrade = self::where('code', $gradeLevel->code)
                ->where('id', '!=', $gradeLevel->id)
                ->first();
                
            if ($existingGrade) {
                throw new \Exception('Grade level code already exists');
            }
        }
        
        // Validate age ranges
        if ($gradeLevel->min_age && $gradeLevel->max_age) {
            if ($gradeLevel->min_age > $gradeLevel->max_age) {
                throw new \Exception('Minimum age cannot be greater than maximum age');
            }
        }
        
        // Validate level order uniqueness
        if ($gradeLevel->level_order) {
            $existingOrder = self::where('level_order', $gradeLevel->level_order)
                ->where('id', '!=', $gradeLevel->id)
                ->first();
                
            if ($existingOrder) {
                throw new \Exception('Level order already exists');
            }
        }
    }

    /**
     * Get active grade levels.
     *
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getActive($filters = [])
    {
        $cacheKey = 'grade_levels_active_' . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHours(6), function () use ($filters) {
            $query = self::where('is_active', true)
                ->with(['nextGrade', 'previousGrade', 'feesStructure'])
                ->orderBy('level_order');
            
            // Apply filters
            if (isset($filters['curriculum'])) {
                $query->where('curriculum', $filters['curriculum']);
            }
            
            if (isset($filters['age_group'])) {
                $query->where('age_group', $filters['age_group']);
            }
            
            if (isset($filters['has_fees_structure'])) {
                if ($filters['has_fees_structure']) {
                    $query->whereNotNull('fees_structure_id');
                } else {
                    $query->whereNull('fees_structure_id');
                }
            }
            
            return $query->get();
        });
    }

    /**
     * Get grade levels by level order.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getByLevelOrder()
    {
        return Cache::remember('grade_levels_by_order', now()->addHours(12), function () {
            return self::where('is_active', true)
                ->orderBy('level_order')
                ->get();
        });
    }

    /**
     * Get grade level by code.
     *
     * @param  string  $code
     * @return GradeLevel|null
     */
    public static function getByCode($code)
    {
        return Cache::remember("grade_level_code_{$code}", now()->addHours(12), function () use ($code) {
            return self::where('code', $code)->first();
        });
    }

    /**
     * Scope a query to only include active grade levels.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to order by level order.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('level_order');
    }

    /**
     * Scope a query to only include grade levels with a specific curriculum.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $curriculum
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForCurriculum($query, $curriculum)
    {
        return $query->where('curriculum', $curriculum);
    }

    /**
     * Scope a query to only include grade levels for a specific age group.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $ageGroup
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForAgeGroup($query, $ageGroup)
    {
        return $query->where('age_group', $ageGroup);
    }
}