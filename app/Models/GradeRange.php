<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GradeRange extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'grade_ranges';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'grading_system_id',
        'grade',
        'description',
        'min_score',
        'max_score',
        'grade_point',
        'status',
        'remarks',
        'order',
        'color_code'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'min_score' => 'decimal:2',
        'max_score' => 'decimal:2',
        'grade_point' => 'decimal:2',
        'order' => 'integer'
    ];

    /**
     * The attributes that should be appended.
     *
     * @var array<string>
     */
    protected $appends = [
        'range_display',
        'status_display',
        'is_passing',
        'score_range'
    ];

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($gradeRange) {
            // Validate score range
            self::validateScoreRange($gradeRange);
            
            // Set default order if not provided
            if (empty($gradeRange->order)) {
                $maxOrder = self::where('grading_system_id', $gradeRange->grading_system_id)
                    ->max('order');
                $gradeRange->order = ($maxOrder ?? 0) + 1;
            }
            
            // Set default status if not provided
            if (empty($gradeRange->status)) {
                $gradeRange->status = 'pass';
            }
            
            Log::info('Grade range creating', [
                'grading_system_id' => $gradeRange->grading_system_id,
                'grade' => $gradeRange->grade,
                'min_score' => $gradeRange->min_score,
                'max_score' => $gradeRange->max_score
            ]);
        });

        static::updating(function ($gradeRange) {
            // Validate score range on update
            self::validateScoreRange($gradeRange);
            
            // Check for overlaps with other ranges in the same grading system
            self::checkForOverlaps($gradeRange);
        });

        static::saved(function ($gradeRange) {
            // Clear relevant cache
            Cache::forget("grade_range_{$gradeRange->id}");
            Cache::forget("grading_system_{$gradeRange->grading_system_id}_ranges");
            Cache::tags(["grading_system_{$gradeRange->grading_system_id}"])->flush();
            
            // Reorder if needed
            self::reorderRanges($gradeRange->grading_system_id);
        });

        static::deleted(function ($gradeRange) {
            // Clear cache
            Cache::forget("grade_range_{$gradeRange->id}");
            Cache::forget("grading_system_{$gradeRange->grading_system_id}_ranges");
            Cache::tags(["grading_system_{$gradeRange->grading_system_id}"])->flush();
            
            // Reorder remaining ranges
            self::reorderRanges($gradeRange->grading_system_id);
        });
    }

    /**
     * Get the grading system that owns this grade range.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function gradingSystem()
    {
        return $this->belongsTo(GradingSystem::class, 'grading_system_id');
    }

    /**
     * Get the range display attribute.
     *
     * @return string
     */
    public function getRangeDisplayAttribute()
    {
        return "{$this->min_score} - {$this->max_score}";
    }

    /**
     * Get the status display attribute.
     *
     * @return string
     */
    public function getStatusDisplayAttribute()
    {
        $statuses = [
            'pass' => 'Pass',
            'fail' => 'Fail',
            'conditional' => 'Conditional'
        ];
        
        return $statuses[$this->status] ?? ucfirst($this->status);
    }

    /**
     * Check if this grade range is passing.
     *
     * @return bool
     */
    public function getIsPassingAttribute()
    {
        return $this->status === 'pass';
    }

    /**
     * Get the score range array.
     *
     * @return array
     */
    public function getScoreRangeAttribute()
    {
        return [
            'min' => (float) $this->min_score,
            'max' => (float) $this->max_score
        ];
    }

    /**
     * Check if a score falls within this grade range.
     *
     * @param  float  $score
     * @return bool
     */
    public function containsScore($score)
    {
        return $score >= $this->min_score && $score <= $this->max_score;
    }

    /**
     * Get the grade point for a score.
     *
     * @param  float  $score
     * @return float|null
     */
    public function getGradePointForScore($score)
    {
        if (!$this->containsScore($score)) {
            return null;
        }
        
        // If grade point is explicitly set, return it
        if (!is_null($this->grade_point)) {
            return $this->grade_point;
        }
        
        // Calculate grade point based on score position in range
        $rangeSize = $this->max_score - $this->min_score;
        $position = ($score - $this->min_score) / $rangeSize;
        
        // Linear interpolation between min and max of typical grade points
        $minGradePoint = 0.0;
        $maxGradePoint = 4.0;
        
        return $minGradePoint + ($position * ($maxGradePoint - $minGradePoint));
    }

    /**
     * Get the CSS class for this grade range.
     *
     * @return string
     */
    public function getCssClass()
    {
        // Generate a CSS class based on grade or status
        $baseClass = 'grade-range';
        
        if ($this->color_code) {
            return $baseClass . ' ' . $this->color_code;
        }
        
        if (!$this->is_passing) {
            return $baseClass . ' grade-fail';
        }
        
        return $baseClass . ' grade-' . strtolower($this->grade);
    }

    /**
     * Get the grade range by grade for a grading system.
     *
     * @param  int  $gradingSystemId
     * @param  string  $grade
     * @return GradeRange|null
     */
    public static function findByGrade($gradingSystemId, $grade)
    {
        $cacheKey = "grade_range_{$gradingSystemId}_{$grade}";
        
        return Cache::remember($cacheKey, now()->addHours(12), function () use ($gradingSystemId, $grade) {
            return self::where('grading_system_id', $gradingSystemId)
                ->where('grade', $grade)
                ->first();
        });
    }

    /**
     * Get grade ranges for a grading system, ordered by min_score.
     *
     * @param  int  $gradingSystemId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getForGradingSystem($gradingSystemId)
    {
        $cacheKey = "grading_system_{$gradingSystemId}_ranges";
        
        return Cache::remember($cacheKey, now()->addHours(12), function () use ($gradingSystemId) {
            return self::where('grading_system_id', $gradingSystemId)
                ->orderBy('min_score', 'asc')
                ->get();
        });
    }

    /**
     * Validate that min_score is less than max_score.
     *
     * @param  GradeRange  $gradeRange
     * @return void
     * @throws \Exception
     */
    private static function validateScoreRange($gradeRange)
    {
        if ($gradeRange->min_score >= $gradeRange->max_score) {
            throw new \Exception('Minimum score must be less than maximum score');
        }
        
        // Validate score boundaries
        if ($gradeRange->min_score < 0 || $gradeRange->max_score > 100) {
            throw new \Exception('Scores must be between 0 and 100');
        }
    }

    /**
     * Check for overlaps with other grade ranges in the same grading system.
     *
     * @param  GradeRange  $gradeRange
     * @return void
     * @throws \Exception
     */
    private static function checkForOverlaps($gradeRange)
    {
        $overlappingRange = self::where('grading_system_id', $gradeRange->grading_system_id)
            ->where('id', '!=', $gradeRange->id)
            ->where(function($query) use ($gradeRange) {
                $query->whereBetween('min_score', [$gradeRange->min_score, $gradeRange->max_score])
                      ->orWhereBetween('max_score', [$gradeRange->min_score, $gradeRange->max_score])
                      ->orWhere(function($q) use ($gradeRange) {
                          $q->where('min_score', '<', $gradeRange->min_score)
                            ->where('max_score', '>', $gradeRange->max_score);
                      });
            })
            ->first();
            
        if ($overlappingRange) {
            throw new \Exception("Grade range overlaps with existing range: {$overlappingRange->grade} ({$overlappingRange->min_score}-{$overlappingRange->max_score})");
        }
    }

    /**
     * Reorder grade ranges for a grading system.
     *
     * @param  int  $gradingSystemId
     * @return void
     */
    private static function reorderRanges($gradingSystemId)
    {
        $ranges = self::where('grading_system_id', $gradingSystemId)
            ->orderBy('min_score', 'asc')
            ->get();
            
        $order = 1;
        foreach ($ranges as $range) {
            $range->order = $order;
            $range->saveQuietly(); // Use saveQuietly to avoid triggering events
            $order++;
        }
    }

    /**
     * Get the grade range that contains a specific score.
     *
     * @param  int  $gradingSystemId
     * @param  float  $score
     * @return GradeRange|null
     */
    public static function getRangeForScore($gradingSystemId, $score)
    {
        $ranges = self::getForGradingSystem($gradingSystemId);
        
        foreach ($ranges as $range) {
            if ($range->containsScore($score)) {
                return $range;
            }
        }
        
        return null;
    }

    /**
     * Get all passing grade ranges for a grading system.
     *
     * @param  int  $gradingSystemId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getPassingRanges($gradingSystemId)
    {
        return self::where('grading_system_id', $gradingSystemId)
            ->where('status', 'pass')
            ->orderBy('min_score', 'asc')
            ->get();
    }

    /**
     * Get all failing grade ranges for a grading system.
     *
     * @param  int  $gradingSystemId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getFailingRanges($gradingSystemId)
    {
        return self::where('grading_system_id', $gradingSystemId)
            ->where('status', 'fail')
            ->orderBy('min_score', 'asc')
            ->get();
    }

    /**
     * Check if all grade ranges in a grading system are contiguous.
     *
     * @param  int  $gradingSystemId
     * @return array
     */
    public static function validateContiguity($gradingSystemId)
    {
        $ranges = self::getForGradingSystem($gradingSystemId);
        $errors = [];
        $warnings = [];
        
        if ($ranges->isEmpty()) {
            $errors[] = 'No grade ranges defined';
            return compact('errors', 'warnings', 'is_valid');
        }
        
        // Sort by min_score
        $ranges = $ranges->sortBy('min_score');
        
        $previousMax = null;
        foreach ($ranges as $index => $range) {
            if ($previousMax !== null && $range->min_score > $previousMax) {
                $gap = $range->min_score - $previousMax;
                $errors[] = "Gap of {$gap} points between ranges";
            } elseif ($previousMax !== null && $range->min_score < $previousMax) {
                $overlap = $previousMax - $range->min_score;
                $errors[] = "Overlap of {$overlap} points between ranges";
            }
            
            $previousMax = $range->max_score;
        }
        
        $is_valid = empty($errors);
        
        return compact('errors', 'warnings', 'is_valid');
    }

    /**
     * Get grade range statistics.
     *
     * @return array
     */
    public function getStatistics()
    {
        return [
            'total_grades_in_range' => $this->getGradesCount(),
            'average_score_in_range' => $this->getAverageScore(),
            'percentage_of_total' => $this->getPercentageOfTotal()
        ];
    }

    /**
     * Get count of grades in this range.
     *
     * @return int
     */
    private function getGradesCount()
    {
        if (!$this->gradingSystem) {
            return 0;
        }
        
        return $this->gradingSystem->grades()
            ->where('score', '>=', $this->min_score)
            ->where('score', '<=', $this->max_score)
            ->count();
    }

    /**
     * Get average score in this range.
     *
     * @return float|null
     */
    private function getAverageScore()
    {
        if (!$this->gradingSystem) {
            return null;
        }
        
        return $this->gradingSystem->grades()
            ->where('score', '>=', $this->min_score)
            ->where('score', '<=', $this->max_score)
            ->avg('score');
    }

    /**
     * Get percentage of total grades in this range.
     *
     * @return float
     */
    private function getPercentageOfTotal()
    {
        if (!$this->gradingSystem) {
            return 0;
        }
        
        $totalGrades = $this->gradingSystem->grades()->count();
        $gradesInRange = $this->getGradesCount();
        
        if ($totalGrades === 0) {
            return 0;
        }
        
        return round(($gradesInRange / $totalGrades) * 100, 2);
    }
}