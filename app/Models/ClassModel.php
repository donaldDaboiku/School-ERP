<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;   
use Illuminate\Support\Facades\DB;   

class ClassModel extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'classes';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'code',
        'description',
        'grade_level',
        'section',
        'academic_year',
        'capacity',
        'current_strength',
        'room_number',
        'building',
        'homeroom_teacher_id',
        'assistant_teacher_id',
        'status',
        'is_active',
        'fees_structure_id',
        'timetable_id',
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
        'occupancy_rate',
        'is_full',
        'academic_year_display'
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $with = ['homeroomTeacher', 'assistantTeacher', 'gradeLevelInfo'];

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($class) {
            // Generate class code if not provided
            if (empty($class->code)) {
                $class->code = self::generateClassCode($class);
            }
            
            // Set academic year if not provided
            if (empty($class->academic_year)) {
                $class->academic_year = self::getCurrentAcademicYear();
            }
            
            // Set default capacity if not provided
            if (empty($class->capacity)) {
                $class->capacity = config('app.default_class_capacity', 40);
            }
            
            // Set current strength default
            $class->current_strength = $class->current_strength ?? 0;
            
            // Set default status
            if (empty($class->status)) {
                $class->status = 'active';
            }
            
            // Set is_active default
            if (is_null($class->is_active)) {
                $class->is_active = true;
            }
            
            // Set created_by if not set
            if (empty($class->created_by) && Auth::check()) {
                $class->created_by = Auth::id();
            }
            
            // Validate class
            self::validateClass($class);
            
            Log::info('Class creating', [
                'name' => $class->name,
                'code' => $class->code,
                'grade_level' => $class->grade_level,
                'academic_year' => $class->academic_year,
                'created_by' => $class->created_by
            ]);
        });

        static::updating(function ($class) {
            // Update current strength if students are added/removed
            if ($class->isDirty('current_strength')) {
                // Ensure current strength doesn't exceed capacity
                if ($class->current_strength > $class->capacity) {
                    throw new \Exception('Current strength cannot exceed capacity');
                }
            }
            
            // Update updated_by
            if (Auth::check()) {
                $class->updated_by = Auth::id();
            }
            
            // Validate class on update
            self::validateClass($class);
        });

        static::saved(function ($class) {
            // Clear relevant cache
            Cache::forget("class_{$class->id}");
            Cache::forget("class_code_{$class->code}");
            Cache::tags([
                "classes_academic_year_{$class->academic_year}",
                "classes_grade_level_{$class->grade_level}",
                "classes_teacher_{$class->homeroom_teacher_id}",
                "class_statistics_{$class->id}"
            ])->flush();
            
            // Update class statistics
            self::updateClassStatistics($class->id);
        });

        static::deleted(function ($class) {
            // Clear cache
            Cache::forget("class_{$class->id}");
            Cache::forget("class_code_{$class->code}");
            Cache::tags([
                "classes_academic_year_{$class->academic_year}",
                "classes_grade_level_{$class->grade_level}",
                "classes_teacher_{$class->homeroom_teacher_id}",
                "class_statistics_{$class->id}"
            ])->flush();
        });
    }

    /**
     * Get the homeroom teacher for this class.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function homeroomTeacher()
    {
        return $this->belongsTo(Teacher::class, 'homeroom_teacher_id');
    }

    /**
     * Get the assistant teacher for this class.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function assistantTeacher()
    {
        return $this->belongsTo(Teacher::class, 'assistant_teacher_id');
    }

    /**
     * Get the grade level information.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function gradeLevelInfo()
    {
        return $this->belongsTo(GradeLevel::class, 'grade_level', 'level');
    }

    /**
     * Get the user who created this class.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this class.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get the students in this class.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function students()
    {
        return $this->hasMany(Student::class, 'class_id');
    }

    /**
     * Get the teachers assigned to this class.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function teachers()
    {
        return $this->belongsToMany(Teacher::class, 'class_teachers', 'class_id', 'teacher_id')
            ->withPivot('subject_id', 'is_primary')
            ->withTimestamps();
    }

    /**
     * Get the subjects taught in this class.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function subjects()
    {
        return $this->belongsToMany(Subject::class, 'class_subjects', 'class_id', 'subject_id')
            ->withPivot('teacher_id', 'weekly_periods', 'is_compulsory')
            ->withTimestamps();
    }

    /**
     * Get the timetable for this class.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function timetable()
    {
        return $this->hasOne(Timetable::class, 'class_id');
    }

    /**
     * Get the fees structure for this class.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function feesStructure()
    {
        return $this->belongsTo(FeesStructure::class, 'fees_structure_id');
    }

    /**
     * Get the exams for this class.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function exams()
    {
        return $this->hasMany(Exam::class, 'class_id');
    }

    /**
     * Get the grades for this class.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function grades()
    {
        return $this->hasMany(Grade::class, 'class_id');
    }

    /**
     * Get the attendances for this class.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'class_id');
    }

    /**
     * Get the full class name.
     *
     * @return string
     */
    public function getFullNameAttribute()
    {
        $name = "Class {$this->grade_level}";
        
        if ($this->section) {
            $name .= " - {$this->section}";
        }
        
        if ($this->name != "Class {$this->grade_level}") {
            $name .= " ({$this->name})";
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
     * Get occupancy rate.
     *
     * @return float
     */
    public function getOccupancyRateAttribute()
    {
        if ($this->capacity === 0) {
            return 0;
        }
        
        return round(($this->current_strength / $this->capacity) * 100, 2);
    }

    /**
     * Check if class is full.
     *
     * @return bool
     */
    public function getIsFullAttribute()
    {
        return $this->available_seats <= 0;
    }

    /**
     * Get academic year display.
     *
     * @return string
     */
    public function getAcademicYearDisplayAttribute()
    {
        $parts = explode('-', $this->academic_year);
        if (count($parts) === 2) {
            return $parts[0] . '/' . substr($parts[1], -2);
        }
        
        return $this->academic_year;
    }

    /**
     * Add a student to the class.
     *
     * @param  Student|int  $student
     * @return bool
     */
    public function addStudent($student)
    {
        $studentId = $student instanceof Student ? $student->id : $student;
        $student = Student::find($studentId);
        
        if (!$student) {
            throw new \Exception('Student not found');
        }
        
        // Check if class is full
        if ($this->is_full) {
            throw new \Exception('Class is full');
        }
        
        // Check if student is already in a class
        if ($student->class_id && $student->class_id != $this->id) {
            throw new \Exception('Student is already assigned to another class');
        }
        
        // Update student's class
        $student->class_id = $this->id;
        $student->save();
        
        // Update current strength
        $this->current_strength++;
        $this->save();
        
        return true;
    }

    /**
     * Remove a student from the class.
     *
     * @param  Student|int  $student
     * @return bool
     */
    public function removeStudent($student)
    {
        $studentId = $student instanceof Student ? $student->id : $student;
        $student = Student::find($studentId);
        
        if (!$student || $student->class_id != $this->id) {
            throw new \Exception('Student not found in this class');
        }
        
        // Update student's class
        $student->class_id = null;
        $student->save();
        
        // Update current strength
        $this->current_strength = max(0, $this->current_strength - 1);
        $this->save();
        
        return true;
    }

    /**
     * Assign a teacher to the class.
     *
     * @param  Teacher|int  $teacher
     * @param  int|null  $subjectId
     * @param  bool  $isPrimary
     * @return bool
     */
    public function assignTeacher($teacher, $subjectId = null, $isPrimary = false)
    {
        $teacherId = $teacher instanceof Teacher ? $teacher->id : $teacher;
        $teacher = Teacher::find($teacherId);
        
        if (!$teacher) {
            throw new \Exception('Teacher not found');
        }
        
        // Check if teacher is already assigned
        $existingAssignment = $this->teachers()->where('teacher_id', $teacherId)->first();
        if ($existingAssignment) {
            throw new \Exception('Teacher is already assigned to this class');
        }
        
        // Assign teacher
        $this->teachers()->attach($teacherId, [
            'subject_id' => $subjectId,
            'is_primary' => $isPrimary
        ]);
        
        return true;
    }

    /**
     * Remove a teacher from the class.
     *
     * @param  Teacher|int  $teacher
     * @return bool
     */
    public function removeTeacher($teacher)
    {
        $teacherId = $teacher instanceof Teacher ? $teacher->id : $teacher;
        
        // Check if teacher is assigned
        $existingAssignment = $this->teachers()->where('teacher_id', $teacherId)->first();
        if (!$existingAssignment) {
            throw new \Exception('Teacher is not assigned to this class');
        }
        
        // Remove teacher
        $this->teachers()->detach($teacherId);
        
        return true;
    }

    /**
     * Get class statistics.
     *
     * @return array
     */
    public function getStatistics()
    {
        $cacheKey = "class_statistics_{$this->id}";
        
        return Cache::remember($cacheKey, now()->addHours(6), function () {
            $maleCount = $this->students()->where('gender', 'male')->count();
            $femaleCount = $this->students()->where('gender', 'female')->count();
            $otherCount = $this->students()->whereNotIn('gender', ['male', 'female'])->count();
            
            $averageAge = $this->students()->avg(DB::raw('TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE())'));
            
            $averageGrade = $this->grades()->avg('score');
            
            $attendanceRate = $this->attendances()
                ->where('status', 'present')
                ->count() / max($this->attendances()->count(), 1) * 100;
            
            return [
                'total_students' => $this->current_strength,
                'male_count' => $maleCount,
                'female_count' => $femaleCount,
                'other_count' => $otherCount,
                'gender_distribution' => [
                    'male' => $this->current_strength > 0 ? round(($maleCount / $this->current_strength) * 100, 2) : 0,
                    'female' => $this->current_strength > 0 ? round(($femaleCount / $this->current_strength) * 100, 2) : 0,
                    'other' => $this->current_strength > 0 ? round(($otherCount / $this->current_strength) * 100, 2) : 0
                ],
                'average_age' => round($averageAge ?? 0, 1),
                'average_grade' => round($averageGrade ?? 0, 2),
                'attendance_rate' => round($attendanceRate, 2),
                'teacher_count' => $this->teachers()->count(),
                'subject_count' => $this->subjects()->count(),
                'exam_count' => $this->exams()->count()
            ];
        });
    }

    /**
     * Generate class code.
     *
     * @param  ClassModel  $class
     * @return string
     */
    private static function generateClassCode($class)
    {
        $grade = str_pad($class->grade_level, 2, '0', STR_PAD_LEFT);
        $section = strtoupper($class->section ? substr($class->section, 0, 1) : 'A');
        $year = substr($class->academic_year, 2, 2);
        
        do {
            $random = strtoupper(\Illuminate\Support\Str::random(2));
            $code = "CLS{$grade}{$section}{$year}{$random}";
        } while (self::where('code', $code)->exists());
        
        return $code;
    }

    /**
     * Validate class.
     *
     * @param  ClassModel  $class
     * @return void
     * @throws \Exception
     */
    private static function validateClass($class)
    {
        // Check if class code is unique
        if ($class->code) {
            $existingClass = self::where('code', $class->code)
                ->where('id', '!=', $class->id)
                ->first();
                
            if ($existingClass) {
                throw new \Exception('Class code already exists');
            }
        }
        
        // Validate capacity
        if ($class->capacity < 0) {
            throw new \Exception('Capacity cannot be negative');
        }
        
        if ($class->current_strength < 0) {
            throw new \Exception('Current strength cannot be negative');
        }
        
        if ($class->current_strength > $class->capacity) {
            throw new \Exception('Current strength cannot exceed capacity');
        }
    }

    /**
     * Update class statistics.
     *
     * @param  int  $classId
     * @return void
     */
    private static function updateClassStatistics($classId)
    {
        $class = self::find($classId);
        if (!$class) {
            return;
        }
        
        // Update current strength
        $currentStrength = $class->students()->where('status', 'active')->count();
        $class->current_strength = $currentStrength;
        $class->saveQuietly();
    }

    /**
     * Get current academic year.
     *
     * @return string
     */
    public static function getCurrentAcademicYear()
    {
        $year = date('Y');
        $month = date('m');
        
        if ($month >= 8) {
            return $year . '-' . ($year + 1);
        } else {
            return ($year - 1) . '-' . $year;
        }
    }

    /**
     * Get classes for an academic year.
     *
     * @param  string  $academicYear
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getForAcademicYear($academicYear, $filters = [])
    {
        $cacheKey = "classes_academic_year_{$academicYear}_" . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHours(12), function () use ($academicYear, $filters) {
            $query = self::where('academic_year', $academicYear)
                ->with(['homeroomTeacher.user', 'assistantTeacher.user'])
                ->orderBy('grade_level')
                ->orderBy('section')
                ->orderBy('name');
            
            // Apply filters
            if (isset($filters['grade_level'])) {
                $query->where('grade_level', $filters['grade_level']);
            }
            
            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            
            if (isset($filters['is_active'])) {
                $query->where('is_active', $filters['is_active']);
            }
            
            if (isset($filters['homeroom_teacher_id'])) {
                $query->where('homeroom_teacher_id', $filters['homeroom_teacher_id']);
            }
            
            return $query->get();
        });
    }

    /**
     * Get class by code.
     *
     * @param  string  $code
     * @return ClassModel|null
     */
    public static function findByCode($code)
    {
        return Cache::remember("class_code_{$code}", now()->addHours(12), function () use ($code) {
            return self::where('code', $code)->first();
        });
    }

    /**
     * Scope a query to only include active classes.
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
     * Scope a query to only include classes for a specific academic year.
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
     * Scope a query to only include classes for a specific grade level.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $gradeLevel
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForGradeLevel($query, $gradeLevel)
    {
        return $query->where('grade_level', $gradeLevel);
    }

    /**
     * Scope a query to only include classes with available seats.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAvailableSeats($query)
    {
        return $query->whereRaw('current_strength < capacity')
                     ->where('is_active', true);
    }

    /**
     * Scope a query to only include classes taught by a specific teacher.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $teacherId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeTaughtBy($query, $teacherId)
    {
        return $query->where('homeroom_teacher_id', $teacherId)
                     ->orWhere('assistant_teacher_id', $teacherId)
                     ->orWhereHas('teachers', function($q) use ($teacherId) {
                         $q->where('teacher_id', $teacherId);
                     });
    }
}