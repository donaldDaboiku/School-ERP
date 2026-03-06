<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class Section extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'sections';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'class_id',
        'name',
        'capacity',
        'code',
        'description',
        'grade_level_id',
        'academic_year',
        'capacity',
        'current_strength',
        'room_number',
        'homeroom_teacher_id',
        'assistant_teacher_id',
        'curriculum',
        'section_type',
        'is_active',
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
        'capacity' => 'integer',
        'current_strength' => 'integer',
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
        'available_seats',
        'is_full',
        'student_list',
        'teacher_list',
        'average_performance'
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $with = ['gradeLevel', 'homeroomTeacher', 'assistantTeacher', 'creator', 'updater'];

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($section) {
            // Generate code if not provided
            if (empty($section->code)) {
                $section->code = self::generateSectionCode($section);
            }
            
            // Set default current_strength
            if (is_null($section->current_strength)) {
                $section->current_strength = 0;
            }
            
            // Set default is_active
            if (is_null($section->is_active)) {
                $section->is_active = true;
            }
            
            // Set default status
            if (empty($section->status)) {
                $section->status = 'active';
            }
            
            // Set created_by if not set
            if (empty($section->created_by) && Auth::check()) {
                $section->created_by = Auth::id();
            }
            
            // Validate section
            self::validateSection($section);
            
            Log::info('Section creating', [
                'name' => $section->name,
                'code' => $section->code,
                'grade_level_id' => $section->grade_level_id,
                'created_by' => $section->created_by
            ]);
        });

        static::updating(function ($section) {
            // Update updated_by
            if (Auth::check()) {
                $section->updated_by = Auth::id();
            }
            
            // Validate section on update
            self::validateSection($section);
            
            // Prevent deactivation if there are active students
            if ($section->isDirty('is_active') && !$section->is_active) {
                $activeStudents = $section->students()->where('status', 'active')->count();
                if ($activeStudents > 0) {
                    throw new \Exception("Cannot deactivate section with {$activeStudents} active students");
                }
            }
        });

        static::saved(function ($section) {
            // Clear relevant cache
            Cache::forget("section_{$section->id}");
            Cache::forget("section_code_{$section->code}");
            Cache::tags([
                "sections_grade_{$section->grade_level_id}",
                "sections_academic_year_{$section->academic_year}",
                "sections_active"
            ])->flush();
        });

        static::deleted(function ($section) {
            // Clear cache
            Cache::forget("section_{$section->id}");
            Cache::forget("section_code_{$section->code}");
            Cache::tags([
                "sections_grade_{$section->grade_level_id}",
                "sections_academic_year_{$section->academic_year}",
                "sections_active"
            ])->flush();
        });
    }

    /**
     * Get the grade level for this section.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */

    public function class()
    {
        return $this->belongsTo(Classes::class);
    }

    public function gradeLevel()
    {
        return $this->belongsTo(GradeLevel::class, 'grade_level_id');
    }

    /**
     * Get the homeroom teacher for this section.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function homeroomTeacher()
    {
        return $this->belongsTo(Teacher::class, 'homeroom_teacher_id');
    }

    /**
     * Get the assistant teacher for this section.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function assistantTeacher()
    {
        return $this->belongsTo(Teacher::class, 'assistant_teacher_id');
    }

    /**
     * Get the user who created this section.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this section.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get the students in this section.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function students()
    {
        return $this->hasMany(Student::class, 'section_id');
    }

    /**
     * Get the teachers assigned to this section.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function teachers()
    {
        return $this->belongsToMany(Teacher::class, 'section_teachers', 'section_id', 'teacher_id')
            ->withPivot(['subject_id', 'is_primary', 'academic_year'])
            ->withTimestamps();
    }

    /**
     * Get the subjects for this section.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function subjects()
    {
        return $this->belongsToMany(Subject::class, 'section_subjects', 'section_id', 'subject_id')
            ->withPivot(['teacher_id', 'periods_per_week', 'academic_year'])
            ->withTimestamps();
    }

    /**
     * Get the timetables for this section.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function timetables()
    {
        return $this->hasMany(Timetable::class, 'section_id');
    }

    /**
     * Get the full section name.
     *
     * @return string
     */
    public function getFullNameAttribute()
    {
        $name = $this->name;
        
        if ($this->gradeLevel) {
            $name = "{$this->gradeLevel->name} - {$name}";
        }
        
        if ($this->academic_year) {
            $name .= " ({$this->academic_year})";
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
            'graduated' => 'Graduated',
            'archived' => 'Archived'
        ];
        
        return $statuses[$this->status] ?? ucfirst($this->status);
    }

    /**
     * Get available seats.
     *
     * @return int
     */
    public function getAvailableSeatsAttribute()
    {
        return max(0, $this->capacity - $this->current_strength);
    }

    /**
     * Check if section is full.
     *
     * @return bool
     */
    public function getIsFullAttribute()
    {
        return $this->current_strength >= $this->capacity;
    }

    /**
     * Get student list summary.
     *
     * @return array
     */
    public function getStudentListAttribute()
    {
        return [
            'total' => $this->current_strength,
            'male' => $this->students()->where('gender', 'male')->active()->count(),
            'female' => $this->students()->where('gender', 'female')->active()->count(),
            'average_age' => $this->getAverageAge()
        ];
    }

    /**
     * Get teacher list.
     *
     * @return array
     */
    public function getTeacherListAttribute()
    {
        $teachers = $this->teachers()->active()->get();
        
        return [
            'total' => $teachers->count(),
            'homeroom_teacher' => $this->homeroomTeacher ? $this->homeroomTeacher->name : null,
            'assistant_teacher' => $this->assistantTeacher ? $this->assistantTeacher->name : null,
            'subject_teachers' => $teachers->pluck('name')->toArray()
        ];
    }

    /**
     * Get average performance.
     *
     * @return float|null
     */
    public function getAveragePerformanceAttribute()
    {
        $average = $this->students()->active()->avg('overall_percentage');
        return $average ? round($average, 2) : null;
    }

    /**
     * Add student to section.
     *
     * @param  Student  $student
     * @return bool
     */
    public function addStudent($student)
    {
        if ($this->is_full) {
            throw new \Exception('Section is full');
        }
        
        if ($student->section_id) {
            throw new \Exception('Student is already assigned to a section');
        }
        
        $student->section_id = $this->id;
        $student->save();
        
        $this->increment('current_strength');
        
        Log::info('Student added to section', [
            'student_id' => $student->id,
            'section_id' => $this->id,
            'added_by' => Auth::id()
        ]);
        
        return true;
    }

    /**
     * Remove student from section.
     *
     * @param  Student  $student
     * @return bool
     */
    public function removeStudent($student)
    {
        if ($student->section_id != $this->id) {
            throw new \Exception('Student is not in this section');
        }
        
        $student->section_id = null;
        $student->save();
        
        $this->decrement('current_strength');
        
        Log::info('Student removed from section', [
            'student_id' => $student->id,
            'section_id' => $this->id,
            'removed_by' => Auth::id()
        ]);
        
        return true;
    }

    /**
     * Assign teacher to section.
     *
     * @param  Teacher  $teacher
     * @param  Subject|null  $subject
     * @param  bool  $isPrimary
     * @return bool
     */
    public function assignTeacher($teacher, $subject = null, $isPrimary = false)
    {
        // Check if teacher is already assigned
        $existingAssignment = $this->teachers()->where('teacher_id', $teacher->id)->first();
        
        if ($existingAssignment) {
            throw new \Exception('Teacher is already assigned to this section');
        }
        
        $this->teachers()->attach($teacher->id, [
            'subject_id' => $subject ? $subject->id : null,
            'is_primary' => $isPrimary,
            'academic_year' => $this->academic_year
        ]);
        
        Log::info('Teacher assigned to section', [
            'teacher_id' => $teacher->id,
            'section_id' => $this->id,
            'subject_id' => $subject ? $subject->id : null,
            'assigned_by' => Auth::id()
        ]);
        
        return true;
    }

    /**
     * Get average age of students.
     *
     * @return float|null
     */
    public function getAverageAge()
    {
        $averageAge = $this->students()->active()->avg('age');
        return $averageAge ? round($averageAge, 1) : null;
    }

    /**
     * Get section statistics.
     *
     * @return array
     */
    public function getStatistics()
    {
        $students = $this->students()->active()->get();
        $maleCount = $students->where('gender', 'male')->count();
        $femaleCount = $students->where('gender', 'female')->count();
        
        // Get performance distribution
        $performanceDistribution = [
            'excellent' => $students->where('overall_percentage', '>=', 90)->count(),
            'good' => $students->whereBetween('overall_percentage', [75, 89])->count(),
            'average' => $students->whereBetween('overall_percentage', [50, 74])->count(),
            'below_average' => $students->where('overall_percentage', '<', 50)->count()
        ];
        
        // Get attendance rate
        $attendanceRate = $this->getAttendanceRate();
        
        // Get subject-wise performance
        $subjectPerformance = $this->getSubjectPerformance();
        
        return [
            'total_students' => $this->current_strength,
            'male_students' => $maleCount,
            'female_students' => $femaleCount,
            'gender_ratio' => $this->current_strength > 0 ? round(($maleCount / $this->current_strength) * 100, 2) : 0,
            'available_seats' => $this->available_seats,
            'occupancy_rate' => $this->capacity > 0 ? round(($this->current_strength / $this->capacity) * 100, 2) : 0,
            'average_performance' => $this->average_performance,
            'performance_distribution' => $performanceDistribution,
            'attendance_rate' => $attendanceRate,
            'subject_performance' => $subjectPerformance,
            'teacher_count' => $this->teachers()->count(),
            'average_age' => $this->getAverageAge()
        ];
    }

    /**
     * Get attendance rate.
     *
     * @return float|null
     */
    private function getAttendanceRate()
    {
        // This would depend on your attendance system
        // Placeholder implementation
        return null;
    }

    /**
     * Get subject performance.
     *
     * @return array
     */
    private function getSubjectPerformance()
    {
        // This would depend on your grading system
        // Placeholder implementation
        return [];
    }

    /**
     * Generate section code.
     *
     * @param  Section  $section
     * @return string
     */
    private static function generateSectionCode($section)
    {
        $gradeCode = $section->gradeLevel ? $section->gradeLevel->code : 'G';
        $academicYear = substr(str_replace('/', '', $section->academic_year), 0, 4);
        $sectionName = strtoupper(substr($section->name, 0, 3));
        
        do {
            $random = strtoupper(\Illuminate\Support\Str::random(2));
            $code = "SEC{$gradeCode}{$academicYear}{$sectionName}{$random}";
        } while (self::where('code', $code)->exists());
        
        return $code;
    }

    /**
     * Validate section.
     *
     * @param  Section  $section
     * @return void
     * @throws \Exception
     */
    private static function validateSection($section)
    {
        // Check if section code is unique
        if ($section->code) {
            $existingSection = self::where('code', $section->code)
                ->where('id', '!=', $section->id)
                ->first();
                
            if ($existingSection) {
                throw new \Exception('Section code already exists');
            }
        }
        
        // Validate capacity
        if ($section->capacity <= 0) {
            throw new \Exception('Capacity must be greater than 0');
        }
        
        if ($section->current_strength > $section->capacity) {
            throw new \Exception('Current strength cannot exceed capacity');
        }
        
        // Validate academic year format
        if ($section->academic_year && !preg_match('/^\d{4}\/\d{4}$/', $section->academic_year)) {
            throw new \Exception('Academic year must be in format YYYY/YYYY');
        }
    }

    /**
     * Get sections for a grade level.
     *
     * @param  int  $gradeLevelId
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getForGradeLevel($gradeLevelId, $filters = [])
    {
        $cacheKey = "sections_grade_{$gradeLevelId}_" . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHours(6), function () use ($gradeLevelId, $filters) {
            $query = self::where('grade_level_id', $gradeLevelId)
                ->with(['gradeLevel', 'homeroomTeacher', 'assistantTeacher'])
                ->orderBy('name');
            
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
            
            if (isset($filters['has_available_seats'])) {
                $query->whereRaw('capacity > current_strength');
            }
            
            return $query->get();
        });
    }

    /**
     * Get active sections.
     *
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getActive($filters = [])
    {
        $cacheKey = 'sections_active_' . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHours(6), function () use ($filters) {
            $query = self::where('is_active', true)
                ->where('status', 'active')
                ->with(['gradeLevel', 'homeroomTeacher'])
                ->orderBy('grade_level_id')
                ->orderBy('name');
            
            // Apply filters
            if (isset($filters['academic_year'])) {
                $query->where('academic_year', $filters['academic_year']);
            }
            
            if (isset($filters['grade_level_id'])) {
                $query->where('grade_level_id', $filters['grade_level_id']);
            }
            
            return $query->get();
        });
    }

    /**
     * Get section by code.
     *
     * @param  string  $code
     * @return Section|null
     */
    public static function getByCode($code)
    {
        return Cache::remember("section_code_{$code}", now()->addHours(12), function () use ($code) {
            return self::where('code', $code)->first();
        });
    }

    /**
     * Import sections from CSV.
     *
     * @param  array  $data
     * @param  User  $importer
     * @return Section
     */
    public static function importFromCSV($data, $importer)
    {
        $section = new self($data);
        $section->created_by = $importer->id;
        $section->save();
        
        Log::info('Section imported from CSV', [
            'section_id' => $section->id,
            'section_name' => $section->name,
            'importer_id' => $importer->id
        ]);
        
        return $section;
    }

    /**
     * Export section data to array.
     *
     * @return array
     */
    public function exportToArray()
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'grade_level' => $this->gradeLevel ? $this->gradeLevel->name : null,
            'academic_year' => $this->academic_year,
            'capacity' => $this->capacity,
            'current_strength' => $this->current_strength,
            'available_seats' => $this->available_seats,
            'room_number' => $this->room_number,
            'homeroom_teacher' => $this->homeroomTeacher ? $this->homeroomTeacher->name : null,
            'assistant_teacher' => $this->assistantTeacher ? $this->assistantTeacher->name : null,
            'curriculum' => $this->curriculum,
            'section_type' => $this->section_type,
            'status' => $this->status,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at
        ];
    }

    /**
     * Scope a query to only include active sections.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                     ->where('status', 'active');
    }

    /**
     * Scope a query to only include sections with available seats.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAvailableSeats($query)
    {
        return $query->whereRaw('capacity > current_strength');
    }

    /**
     * Scope a query to only include sections for a specific academic year.
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