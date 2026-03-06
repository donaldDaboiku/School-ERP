<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GradingSystem extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'grading_systems';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'code',
        'description',
        'school_id',
        'academic_year',
        'is_active',
        'is_default',
        'grade_type', // percentage, points, letter, gpa
        'min_score',
        'max_score',
        'passing_grade',
        'include_final_exam',
        'final_exam_weight',
        'include_assignment',
        'assignment_weight',
        'include_quiz',
        'quiz_weight',
        'include_participation',
        'participation_weight',
        'include_project',
        'project_weight',
        'include_midterm',
        'midterm_weight',
        'include_attendance',
        'attendance_weight',
        'grade_rounding',
        'decimal_places',
        'grade_scale',
        'created_by',
        'updated_by'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'min_score' => 'decimal:2',
        'max_score' => 'decimal:2',
        'passing_grade' => 'decimal:2',
        'include_final_exam' => 'boolean',
        'final_exam_weight' => 'decimal:2',
        'include_assignment' => 'boolean',
        'assignment_weight' => 'decimal:2',
        'include_quiz' => 'boolean',
        'quiz_weight' => 'decimal:2',
        'include_participation' => 'boolean',
        'participation_weight' => 'decimal:2',
        'include_project' => 'boolean',
        'project_weight' => 'decimal:2',
        'include_midterm' => 'boolean',
        'midterm_weight' => 'decimal:2',
        'include_attendance' => 'boolean',
        'attendance_weight' => 'decimal:2',
        'grade_scale' => 'json',
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
        'total_weight',
        'grade_type_display',
        'is_complete',
        'grade_ranges_summary'
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $with = ['gradeRanges', 'createdBy', 'updatedBy'];

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($gradingSystem) {
            $gradingSystem->code = $gradingSystem->code ?? self::generateCode();
            
            if ($gradingSystem->is_default) {
                // Only one default grading system per academic year
                self::where('academic_year', $gradingSystem->academic_year)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            if (!Auth::guest()) {
                $gradingSystem->created_by = Auth::id();
            }
        });

        static::updating(function ($gradingSystem) {
            if ($gradingSystem->is_default && $gradingSystem->isDirty('is_default')) {
                self::where('academic_year', $gradingSystem->academic_year)
                    ->where('id', '!=', $gradingSystem->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            if (!Auth::guest()) {
                $gradingSystem->updated_by = Auth::id();
            }
        });

        static::saved(function ($gradingSystem) {
            Cache::forget("grading_system_{$gradingSystem->id}");
            Cache::forget("grading_system_active_{$gradingSystem->academic_year}");
            Cache::forget("grading_system_default_{$gradingSystem->academic_year}");
        });

        static::deleted(function ($gradingSystem) {
            Cache::forget("grading_system_{$gradingSystem->id}");
            Cache::forget("grading_system_active_{$gradingSystem->academic_year}");
            Cache::forget("grading_system_default_{$gradingSystem->academic_year}");
        });
    }

    /**
     * Get the grade ranges for this grading system.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function gradeRanges()
    {
        return $this->hasMany(GradeRange::class, 'grading_system_id')
                    ->orderBy('min_score', 'desc');
    }

    /**
     * Get the grades that use this grading system.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function grades()
    {
        return $this->hasMany(Grade::class, 'grading_system_id');
    }

    /**
     * Get the subjects that use this grading system.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function subjects()
    {
        return $this->hasMany(Subject::class, 'grading_system_id');
    }

    /**
     * Get the institution that owns this grading system.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function institution()
    {
        return $this->belongsTo(School::class, 'institution_id');
    }

    /**
     * Get the user who created this grading system.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this grading system.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Calculate total weight of all components.
     *
     * @return float
     */
    public function getTotalWeightAttribute()
    {
        $total = 0;
        
        if ($this->include_final_exam) {
            $total += $this->final_exam_weight;
        }
        
        if ($this->include_assignment) {
            $total += $this->assignment_weight;
        }
        
        if ($this->include_quiz) {
            $total += $this->quiz_weight;
        }
        
        if ($this->include_participation) {
            $total += $this->participation_weight;
        }
        
        if ($this->include_project) {
            $total += $this->project_weight;
        }
        
        if ($this->include_midterm) {
            $total += $this->midterm_weight;
        }
        
        if ($this->include_attendance) {
            $total += $this->attendance_weight;
        }
        
        return round($total, 2);
    }

    /**
     * Get grade type display name.
     *
     * @return string
     */
    public function getGradeTypeDisplayAttribute()
    {
        $types = [
            'percentage' => 'Percentage',
            'points' => 'Points',
            'letter' => 'Letter Grade',
            'gpa' => 'GPA Scale'
        ];
        
        return $types[$this->grade_type] ?? ucfirst($this->grade_type);
    }

    /**
     * Check if grading system is complete (total weight = 100%).
     *
     * @return bool
     */
    public function getIsCompleteAttribute()
    {
        return abs($this->total_weight - 100) < 0.01;
    }

    /**
     * Get summary of grade ranges.
     *
     * @return string
     */
    public function getGradeRangesSummaryAttribute()
    {
        if ($this->gradeRanges->isEmpty()) {
            return 'No grade ranges defined';
        }
        
        $count = $this->gradeRanges->count();
        $highest = $this->gradeRanges->first();
        $lowest = $this->gradeRanges->last();
        
        return "{$count} ranges ({$lowest->grade} to {$highest->grade})";
    }

    /**
     * Get the grade for a given score.
     *
     * @param float $score
     * @return \App\Models\GradeRange|null
     */
    public function getGradeForScore($score)
    {
        return $this->gradeRanges
            ->where('min_score', '<=', $score)
            ->where('max_score', '>=', $score)
            ->first();
    }

    /**
     * Calculate final grade based on component scores.
     *
     * @param array $scores
     * @return array
     */
    public function calculateFinalGrade(array $scores)
    {
        try {
            $finalScore = 0;
            $components = [];
            $warnings = [];
            
            foreach ($this->getActiveComponents() as $component => $weight) {
                if (isset($scores[$component]) && is_numeric($scores[$component])) {
                    $componentScore = $scores[$component];
                    $componentContribution = ($componentScore / 100) * $weight;
                    $finalScore += $componentContribution;
                    
                    $components[$component] = [
                        'score' => $componentScore,
                        'weight' => $weight,
                        'contribution' => $componentContribution
                    ];
                } else {
                    $warnings[] = "Missing score for {$component}";
                }
            }
            
            // Apply rounding
            $finalScore = $this->applyRounding($finalScore);
            
            // Get grade
            $gradeRange = $this->getGradeForScore($finalScore);
            
            return [
                'success' => true,
                'final_score' => $finalScore,
                'grade' => $gradeRange ? $gradeRange->grade : null,
                'grade_point' => $gradeRange ? $gradeRange->grade_point : null,
                'status' => $gradeRange ? $gradeRange->status : 'unknown',
                'components' => $components,
                'warnings' => $warnings,
                'is_passing' => $finalScore >= $this->passing_grade
            ];
            
        } catch (\Exception $e) {
            Log::error('Grade calculation failed', [
                'grading_system_id' => $this->id,
                'scores' => $scores,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => 'Grade calculation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get all active components with their weights.
     *
     * @return array
     */
    public function getActiveComponents()
    {
        $components = [];
        
        if ($this->include_final_exam && $this->final_exam_weight > 0) {
            $components['final_exam'] = $this->final_exam_weight;
        }
        
        if ($this->include_assignment && $this->assignment_weight > 0) {
            $components['assignment'] = $this->assignment_weight;
        }
        
        if ($this->include_quiz && $this->quiz_weight > 0) {
            $components['quiz'] = $this->quiz_weight;
        }
        
        if ($this->include_participation && $this->participation_weight > 0) {
            $components['participation'] = $this->participation_weight;
        }
        
        if ($this->include_project && $this->project_weight > 0) {
            $components['project'] = $this->project_weight;
        }
        
        if ($this->include_midterm && $this->midterm_weight > 0) {
            $components['midterm'] = $this->midterm_weight;
        }
        
        if ($this->include_attendance && $this->attendance_weight > 0) {
            $components['attendance'] = $this->attendance_weight;
        }
        
        return $components;
    }

    /**
     * Apply rounding to score based on grading system settings.
     *
     * @param float $score
     * @return float
     */
    public function applyRounding($score)
    {
        switch ($this->grade_rounding) {
            case 'up':
                $score = ceil($score);
                break;
            case 'down':
                $score = floor($score);
                break;
            case 'nearest':
                $score = round($score);
                break;
            case 'nearest_half':
                $score = round($score * 2) / 2;
                break;
            case 'nearest_quarter':
                $score = round($score * 4) / 4;
                break;
            case 'no_rounding':
                // No rounding
                break;
        }
        
        return round($score, $this->decimal_places ?? 2);
    }

    /**
     * Validate if a score is within the valid range.
     *
     * @param float $score
     * @return bool
     */
    public function isValidScore($score)
    {
        return $score >= $this->min_score && $score <= $this->max_score;
    }

    /**
     * Get the default grading system for an academic year.
     *
     * @param string $academicYear
     * @return \App\Models\GradingSystem|null
     */
    public static function getDefaultForAcademicYear($academicYear)
    {
        $cacheKey = "grading_system_default_{$academicYear}";
        
        return Cache::remember($cacheKey, now()->addDay(), function () use ($academicYear) {
            return self::where('academic_year', $academicYear)
                ->where('is_default', true)
                ->where('is_active', true)
                ->with('gradeRanges')
                ->first();
        });
    }

    /**
     * Get active grading systems for an academic year.
     *
     * @param string $academicYear
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getActiveForAcademicYear($academicYear)
    {
        $cacheKey = "grading_system_active_{$academicYear}";
        
        return Cache::remember($cacheKey, now()->addDay(), function () use ($academicYear) {
            return self::where('academic_year', $academicYear)
                ->where('is_active', true)
                ->with('gradeRanges')
                ->orderBy('is_default', 'desc')
                ->orderBy('name')
                ->get();
        });
    }

    /**
     * Get grading system by code.
     *
     * @param string $code
     * @return \App\Models\GradingSystem|null
     */
    public static function findByCode($code)
    {
        return self::where('code', $code)->first();
    }

    /**
     * Get statistics about grades using this system.
     *
     * @return array
     */
    public function getStatistics()
    {
        return [
            'total_grades' => $this->grades()->count(),
            'average_score' => $this->grades()->avg('score'),
            'passing_rate' => $this->grades()->where('score', '>=', $this->passing_grade)->count() / 
                              max($this->grades()->count(), 1) * 100,
            'grade_distribution' => $this->getGradeDistribution(),
            'recent_grades' => $this->grades()->latest()->limit(10)->get()
        ];
    }

    /**
     * Get distribution of grades across ranges.
     *
     * @return array
     */
    public function getGradeDistribution()
    {
        $distribution = [];
        $totalGrades = $this->grades()->count();
        
        foreach ($this->gradeRanges as $range) {
            $count = $this->grades()
                ->where('score', '>=', $range->min_score)
                ->where('score', '<=', $range->max_score)
                ->count();
            
            $distribution[$range->grade] = [
                'count' => $count,
                'percentage' => $totalGrades > 0 ? ($count / $totalGrades) * 100 : 0
            ];
        }
        
        return $distribution;
    }

    /**
     * Validate the grading system configuration.
     *
     * @return array
     */
    public function validateConfiguration()
    {
        $errors = [];
        $warnings = [];
        
        // Check total weight
        if (!$this->is_complete && $this->total_weight != 0) {
            $errors[] = "Total weight must be exactly 100% (current: {$this->total_weight}%)";
        }
        
        // Check grade ranges
        if ($this->gradeRanges->isEmpty()) {
            $errors[] = "No grade ranges defined";
        } else {
            // Check for overlaps and gaps
            $previousMax = null;
            
            foreach ($this->gradeRanges->sortBy('min_score') as $range) {
                if ($previousMax !== null && $range->min_score != $previousMax) {
                    $errors[] = "Gap in grade ranges between {$previousMax} and {$range->min_score}";
                }
                
                if ($range->min_score >= $range->max_score) {
                    $errors[] = "Invalid range for grade {$range->grade}: min ({$range->min_score}) >= max ({$range->max_score})";
                }
                
                $previousMax = $range->max_score;
            }
            
            // Check coverage
            $firstRange = $this->gradeRanges->sortBy('min_score')->first();
            $lastRange = $this->gradeRanges->sortByDesc('max_score')->first();
            
            if ($firstRange->min_score > $this->min_score) {
                $warnings[] = "Grading does not cover minimum score ({$this->min_score})";
            }
            
            if ($lastRange->max_score < $this->max_score) {
                $warnings[] = "Grading does not cover maximum score ({$this->max_score})";
            }
        }
        
        // Check passing grade is covered
        if ($this->passing_grade) {
            $passingRange = $this->getGradeForScore((float)$this->passing_grade);
            if (!$passingRange || $passingRange->status !== 'pass') {
                $warnings[] = "Passing grade ({$this->passing_grade}) does not map to a passing grade range";
            }
        }
        
        return [
            'is_valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }

    /**
     * Generate a unique code for the grading system.
     *
     * @return string
     */
    private static function generateCode()
    {
        $prefix = 'GS';
        $year = date('y');
        
        do {
            $random = strtoupper(substr(md5(uniqid()), 0, 6));
            $code = "{$prefix}{$year}{$random}";
        } while (self::where('code', $code)->exists());
        
        return $code;
    }

    /**
     * Scope a query to only include active grading systems.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include default grading systems.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope a query by academic year.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $academicYear
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForAcademicYear($query, $academicYear)
    {
        return $query->where('academic_year', $academicYear);
    }

    /**
     * Scope a query by grade type.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $type
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('grade_type', $type);
    }

    /**
     * Get grade type options.
     *
     * @return array
     */
    public static function getGradeTypeOptions()
    {
        return [
            'percentage' => 'Percentage',
            'points' => 'Points',
            'letter' => 'Letter Grade',
            'gpa' => 'GPA Scale'
        ];
    }

    /**
     * Get rounding options.
     *
     * @return array
     */
    public static function getRoundingOptions()
    {
        return [
            'no_rounding' => 'No Rounding',
            'nearest' => 'Nearest Whole Number',
            'nearest_half' => 'Nearest Half',
            'nearest_quarter' => 'Nearest Quarter',
            'up' => 'Always Round Up',
            'down' => 'Always Round Down'
        ];
    }
}