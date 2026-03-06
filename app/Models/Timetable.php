<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;    

class Timetable extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'timetables';

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
        'section_id',
        'start_date',
        'end_date',
        'week_days',
        'periods',
        'breaks',
        'status',
        'is_active',
        'is_published',
        'published_at',
        'created_by',
        'updated_by'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'week_days' => 'json',
        'periods' => 'json',
        'breaks' => 'json',
        'is_active' => 'boolean',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
        'start_date' => 'date',
        'end_date' => 'date',
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
        'duration_days',
        'is_current',
        'timetable_entries_count'
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

        static::creating(function ($timetable) {
            // Generate code if not provided
            if (empty($timetable->code)) {
                $timetable->code = self::generateTimetableCode($timetable);
            }
            
            // Set default status
            if (empty($timetable->status)) {
                $timetable->status = 'draft';
            }
            
            // Set default is_active
            if (is_null($timetable->is_active)) {
                $timetable->is_active = true;
            }
            
            // Set default is_published
            if (is_null($timetable->is_published)) {
                $timetable->is_published = false;
            }
            
            // Set created_by if not set
            if (empty($timetable->created_by) && Auth::check()) {
                $timetable->created_by = Auth::id();
            }
            
            // Validate timetable
            self::validateTimetable($timetable);
            
            Log::info('Timetable creating', [
                'name' => $timetable->name,
                'code' => $timetable->code,
                'academic_year' => $timetable->academic_year,
                'created_by' => $timetable->created_by
            ]);
        });

        static::updating(function ($timetable) {
            // Update updated_by
            if (Auth::check()) {
                $timetable->updated_by = Auth::id();
            }
            
            // Set published_at if being published
            if ($timetable->isDirty('is_published') && $timetable->is_published) {
                $timetable->published_at = now();
                $timetable->status = 'published';
                
                // Archive other active timetables for the same grade/section
                if ($timetable->is_active) {
                    self::where('grade_level_id', $timetable->grade_level_id)
                        ->where('section_id', $timetable->section_id)
                        ->where('id', '!=', $timetable->id)
                        ->where('is_active', true)
                        ->update(['is_active' => false, 'status' => 'archived']);
                }
            }
            
            // Validate timetable on update
            self::validateTimetable($timetable);
        });

        static::saved(function ($timetable) {
            // Clear relevant cache
            Cache::forget("timetable_{$timetable->id}");
            Cache::forget("timetable_code_{$timetable->code}");
            Cache::tags([
                "timetables_grade_{$timetable->grade_level_id}",
                "timetables_section_{$timetable->section_id}",
                "timetables_academic_year_{$timetable->academic_year}"
            ])->flush();
        });

        static::deleted(function ($timetable) {
            // Clear cache
            Cache::forget("timetable_{$timetable->id}");
            Cache::forget("timetable_code_{$timetable->code}");
            Cache::tags([
                "timetables_grade_{$timetable->grade_level_id}",
                "timetables_section_{$timetable->section_id}",
                "timetables_academic_year_{$timetable->academic_year}"
            ])->flush();
        });
    }

    /**
     * Get the grade level for this timetable.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function gradeLevel()
    {
        return $this->belongsTo(GradeLevel::class, 'grade_level_id');
    }

    /**
     * Get the section for this timetable.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function section()
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    /**
     * Get the user who created this timetable.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this timetable.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get the timetable entries.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function entries()
    {
        return $this->hasMany(TimetableEntry::class, 'timetable_id');
    }

    /**
     * Get the full timetable name.
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
            'published' => 'Published',
            'archived' => 'Archived',
            'cancelled' => 'Cancelled'
        ];
        
        return $statuses[$this->status] ?? ucfirst($this->status);
    }

    /**
     * Get duration in days.
     *
     * @return int
     */
    public function getDurationDaysAttribute()
    {
        if (!$this->start_date || !$this->end_date) {
            return 0;
        }
        
        return $this->start_date->diffInDays($this->end_date) + 1;
    }

    /**
     * Check if timetable is current.
     *
     * @return bool
     */
    public function getIsCurrentAttribute()
    {
        if (!$this->is_active || !$this->is_published) {
            return false;
        }
        
        $today = now();
        
        // Check if today is within timetable dates
        if ($this->start_date && $this->end_date) {
            return $today->between($this->start_date, $this->end_date);
        }
        
        return false;
    }

    /**
     * Get timetable entries count.
     *
     * @return int
     */
    public function getTimetableEntriesCountAttribute()
    {
        return $this->entries()->count();
    }

    /**
     * Get timetable for a specific day.
     *
     * @param  string  $day
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getDayTimetable($day)
    {
        return $this->entries()
            ->where('day', $day)
            ->orderBy('period_order')
            ->with(['subject', 'teacher', 'classroom'])
            ->get();
    }

    /**
     * Get weekly timetable.
     *
     * @return array
     */
    public function getWeeklyTimetable()
    {
        $weekDays = $this->week_days ?? ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        $weeklyTimetable = [];
        
        foreach ($weekDays as $day) {
            $weeklyTimetable[$day] = $this->getDayTimetable($day);
        }
        
        return $weeklyTimetable;
    }

    /**
     * Add timetable entry.
     *
     * @param  array  $entryData
     * @return TimetableEntry
     */
    public function addEntry($entryData)
    {
        // Validate entry
        $this->validateEntry($entryData);
        
        $entry = new TimetableEntry($entryData);
        $entry->timetable_id = $this->id;
        $entry->save();
        
        return $entry;
    }

    /**
     * Update timetable entry.
     *
     * @param  int  $entryId
     * @param  array  $entryData
     * @return bool
     */
    public function updateEntry($entryId, $entryData)
    {
        $entry = $this->entries()->findOrFail($entryId);
        
        // Validate entry
        $this->validateEntry($entryData, $entryId);
        
        $entry->update($entryData);
        
        return true;
    }

    /**
     * Validate timetable entry.
     *
     * @param  array  $entryData
     * @param  int|null  $excludeEntryId
     * @return void
     * @throws \Exception
     */
    private function validateEntry($entryData, $excludeEntryId = null)
    {
        // Check for teacher availability
        $teacherConflict = $this->entries()
            ->where('teacher_id', $entryData['teacher_id'])
            ->where('day', $entryData['day'])
            ->where('period_order', $entryData['period_order'])
            ->where('id', '!=', $excludeEntryId)
            ->exists();
            
        if ($teacherConflict) {
            throw new \Exception('Teacher is already assigned during this period');
        }
        
        // Check for classroom availability
        $classroomConflict = $this->entries()
            ->where('classroom_id', $entryData['classroom_id'])
            ->where('day', $entryData['day'])
            ->where('period_order', $entryData['period_order'])
            ->where('id', '!=', $excludeEntryId)
            ->exists();
            
        if ($classroomConflict) {
            throw new \Exception('Classroom is already occupied during this period');
        }
    }

    /**
     * Generate timetable code.
     *
     * @param  Timetable  $timetable
     * @return string
     */
    private static function generateTimetableCode($timetable)
    {
        $academicYear = substr(str_replace('/', '', $timetable->academic_year), 0, 4);
        $gradeCode = $timetable->gradeLevel ? $timetable->gradeLevel->code : 'GEN';
        $term = $timetable->term ? strtoupper(substr($timetable->term, 0, 1)) : 'T';
        
        do {
            $random = strtoupper(\Illuminate\Support\Str::random(4));
            $code = "TT{$academicYear}{$gradeCode}{$term}{$random}";
        } while (self::where('code', $code)->exists());
        
        return $code;
    }

    /**
     * Validate timetable.
     *
     * @param  Timetable  $timetable
     * @return void
     * @throws \Exception
     */
    private static function validateTimetable($timetable)
    {
        // Check if timetable code is unique
        if ($timetable->code) {
            $existingTimetable = self::where('code', $timetable->code)
                ->where('id', '!=', $timetable->id)
                ->first();
                
            if ($existingTimetable) {
                throw new \Exception('Timetable code already exists');
            }
        }
        
        // Validate dates
        if ($timetable->start_date && $timetable->end_date) {
            if ($timetable->start_date > $timetable->end_date) {
                throw new \Exception('Start date must be before end date');
            }
        }
        
        // Validate academic year format
        if (!preg_match('/^\d{4}\/\d{4}$/', $timetable->academic_year)) {
            throw new \Exception('Academic year must be in format YYYY/YYYY');
        }
    }

    /**
     * Get timetables for a specific grade level.
     *
     * @param  int  $gradeLevelId
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getForGradeLevel($gradeLevelId, $filters = [])
    {
        $cacheKey = "timetables_grade_{$gradeLevelId}_" . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHours(6), function () use ($gradeLevelId, $filters) {
            $query = self::where('grade_level_id', $gradeLevelId)
                ->with(['section', 'gradeLevel', 'creator'])
                ->orderBy('start_date', 'desc');
            
            // Apply filters
            if (isset($filters['academic_year'])) {
                $query->where('academic_year', $filters['academic_year']);
            }
            
            if (isset($filters['term'])) {
                $query->where('term', $filters['term']);
            }
            
            if (isset($filters['is_active'])) {
                $query->where('is_active', $filters['is_active']);
            }
            
            if (isset($filters['is_published'])) {
                $query->where('is_published', $filters['is_published']);
            }
            
            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            
            return $query->get();
        });
    }

    /**
     * Get current timetable for a grade/section.
     *
     * @param  int  $gradeLevelId
     * @param  int|null  $sectionId
     * @return Timetable|null
     */
    public static function getCurrent($gradeLevelId, $sectionId = null)
    {
        $cacheKey = "current_timetable_grade_{$gradeLevelId}_section_" . ($sectionId ?? 'all');
        
        return Cache::remember($cacheKey, now()->addHour(), function () use ($gradeLevelId, $sectionId) {
            $query = self::where('grade_level_id', $gradeLevelId)
                ->where('is_active', true)
                ->where('is_published', true)
                ->where('status', 'published')
                ->whereDate('start_date', '<=', now())
                ->whereDate('end_date', '>=', now());
            
            if ($sectionId) {
                $query->where('section_id', $sectionId);
            }
            
            return $query->first();
        });
    }

    /**
     * Clone timetable.
     *
     * @param  string  $newName
     * @param  array  $overrides
     * @return Timetable
     */
    public function clone($newName, $overrides = [])
    {
        $newTimetable = $this->replicate();
        $newTimetable->name = $newName;
        $newTimetable->code = self::generateTimetableCode($newTimetable);
        $newTimetable->status = 'draft';
        $newTimetable->is_published = false;
        $newTimetable->published_at = null;
        
        // Apply overrides
        foreach ($overrides as $key => $value) {
            if (in_array($key, $newTimetable->fillable)) {
                $newTimetable->$key = $value;
            }
        }
        
        $newTimetable->save();
        
        // Clone entries
        foreach ($this->entries as $entry) {
            $newEntry = $entry->replicate();
            $newEntry->timetable_id = $newTimetable->id;
            $newEntry->save();
        }
        
        Log::info('Timetable cloned', [
            'original_id' => $this->id,
            'new_id' => $newTimetable->id,
            'new_name' => $newName,
            'cloned_by' => Auth::id()
        ]);
        
        return $newTimetable;
    }

    /**
     * Publish timetable.
     *
     * @return bool
     */
    public function publish()
    {
        if ($this->is_published) {
            throw new \Exception('Timetable is already published');
        }
        
        // Validate that timetable has entries
        if ($this->entries()->count() === 0) {
            throw new \Exception('Cannot publish timetable without entries');
        }
        
        $this->is_published = true;
        $this->published_at = now();
        $this->status = 'published';
        $this->save();
        
        // Notify stakeholders
        $this->notifyPublish();
        
        return true;
    }

    /**
     * Notify stakeholders about timetable publication.
     *
     * @return void
     */
    private function notifyPublish()
    {
        // Implementation would depend on your notification system
        // This is a placeholder for notification logic
        Log::info('Timetable published', [
            'timetable_id' => $this->id,
            'timetable_name' => $this->name,
            'published_at' => $this->published_at,
            'published_by' => Auth::id()
        ]);
    }

    /**
     * Scope a query to only include active timetables.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include published timetables.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    /**
     * Scope a query to only include timetables for a specific academic year.
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
     * Scope a query to only include timetables for a specific term.
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