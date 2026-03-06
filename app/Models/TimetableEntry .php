<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;    

class TimetableEntry extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'timetable_entries';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'timetable_id',
        'day',
        'period_order',
        'start_time',
        'end_time',
        'subject_id',
        'teacher_id',
        'classroom_id',
        'section_id',
        'grade_level_id',
        'entry_type',
        'is_break',
        'break_type',
        'is_elective',
        'elective_group_id',
        'notes',
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
        'period_order' => 'integer',
        'is_break' => 'boolean',
        'is_elective' => 'boolean',
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
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
        'duration_minutes',
        'time_slot',
        'entry_type_display',
        'status_display',
        'teacher_name',
        'subject_name',
        'classroom_name',
        'conflicts'
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $with = ['timetable', 'subject', 'teacher', 'classroom', 'section', 'gradeLevel', 'creator', 'updater'];

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($entry) {
            // Set default status
            if (empty($entry->status)) {
                $entry->status = 'scheduled';
            }
            
            // Set created_by if not set
            if (empty($entry->created_by) && Auth::check()) {
                $entry->created_by = Auth::id();
            }
            
            // Validate entry
            self::validateEntry($entry);
            
            Log::info('Timetable entry creating', [
                'timetable_id' => $entry->timetable_id,
                'day' => $entry->day,
                'period_order' => $entry->period_order,
                'created_by' => $entry->created_by
            ]);
        });

        static::updating(function ($entry) {
            // Update updated_by
            if (Auth::check()) {
                $entry->updated_by = Auth::id();
            }
            
            // Validate entry on update
            self::validateEntry($entry);
        });

        static::saved(function ($entry) {
            // Clear relevant cache
            Cache::forget("timetable_entry_{$entry->id}");
            Cache::tags([
                "timetable_entries_timetable_{$entry->timetable_id}",
                "timetable_entries_teacher_{$entry->teacher_id}",
                "timetable_entries_classroom_{$entry->classroom_id}",
                "timetable_entries_day_{$entry->day}"
            ])->flush();
        });

        static::deleted(function ($entry) {
            // Clear cache
            Cache::forget("timetable_entry_{$entry->id}");
            Cache::tags([
                "timetable_entries_timetable_{$entry->timetable_id}",
                "timetable_entries_teacher_{$entry->teacher_id}",
                "timetable_entries_classroom_{$entry->classroom_id}",
                "timetable_entries_day_{$entry->day}"
            ])->flush();
        });
    }

    /**
     * Get the timetable for this entry.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function timetable()
    {
        return $this->belongsTo(Timetable::class, 'timetable_id');
    }

    /**
     * Get the subject for this entry.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    /**
     * Get the teacher for this entry.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function teacher()
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    /**
     * Get the classroom for this entry.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function classroom()
    {
        return $this->belongsTo(Classroom::class, 'classroom_id');
    }

    /**
     * Get the section for this entry.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function section()
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    /**
     * Get the grade level for this entry.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function gradeLevel()
    {
        return $this->belongsTo(GradeLevel::class, 'grade_level_id');
    }

    /**
     * Get the user who created this entry.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this entry.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get the elective group for this entry.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function electiveGroup()
    {
        return $this->belongsTo(ElectiveGroup::class, 'elective_group_id');
    }

    /**
     * Get duration in minutes.
     *
     * @return int
     */
    public function getDurationMinutesAttribute()
    {
        if (!$this->start_time || !$this->end_time) {
            return 0;
        }
        
        return $this->start_time->diffInMinutes($this->end_time);
    }

    /**
     * Get time slot.
     *
     * @return string
     */
    public function getTimeSlotAttribute()
    {
        if (!$this->start_time || !$this->end_time) {
            return '';
        }
        
        return $this->start_time->format('H:i') . ' - ' . $this->end_time->format('H:i');
    }

    /**
     * Get entry type display name.
     *
     * @return string
     */
    public function getEntryTypeDisplayAttribute()
    {
        $types = [
            'regular' => 'Regular Class',
            'lab' => 'Laboratory',
            'practical' => 'Practical Session',
            'tutorial' => 'Tutorial',
            'seminar' => 'Seminar',
            'workshop' => 'Workshop',
            'assembly' => 'Assembly',
            'break' => 'Break',
            'lunch' => 'Lunch Break',
            'sports' => 'Sports',
            'club' => 'Club Activity'
        ];
        
        return $types[$this->entry_type] ?? ucfirst($this->entry_type);
    }

    /**
     * Get status display name.
     *
     * @return string
     */
    public function getStatusDisplayAttribute()
    {
        $statuses = [
            'scheduled' => 'Scheduled',
            'ongoing' => 'Ongoing',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'postponed' => 'Postponed',
            'rescheduled' => 'Rescheduled'
        ];
        
        return $statuses[$this->status] ?? ucfirst($this->status);
    }

    /**
     * Get teacher name.
     *
     * @return string|null
     */
    public function getTeacherNameAttribute()
    {
        return $this->teacher ? $this->teacher->name : null;
    }

    /**
     * Get subject name.
     *
     * @return string|null
     */
    public function getSubjectNameAttribute()
    {
        return $this->subject ? $this->subject->name : null;
    }

    /**
     * Get classroom name.
     *
     * @return string|null
     */
    public function getClassroomNameAttribute()
    {
        return $this->classroom ? $this->classroom->name : null;
    }

    /**
     * Check for conflicts.
     *
     * @return array
     */
    public function getConflictsAttribute()
    {
        return $this->checkConflicts();
    }

    /**
     * Check for scheduling conflicts.
     *
     * @return array
     */
    public function checkConflicts()
    {
        $conflicts = [];
        
        // Check teacher conflicts
        $teacherConflicts = self::where('teacher_id', $this->teacher_id)
            ->where('day', $this->day)
            ->where('id', '!=', $this->id)
            ->where(function($query) {
                $query->whereBetween('start_time', [$this->start_time, $this->end_time])
                      ->orWhereBetween('end_time', [$this->start_time, $this->end_time])
                      ->orWhere(function($q) {
                          $q->where('start_time', '<', $this->start_time)
                            ->where('end_time', '>', $this->end_time);
                      });
            })
            ->where('status', '!=', 'cancelled')
            ->get();
        
        if ($teacherConflicts->isNotEmpty()) {
            $conflicts['teacher'] = $teacherConflicts;
        }
        
        // Check classroom conflicts
        $classroomConflicts = self::where('classroom_id', $this->classroom_id)
            ->where('day', $this->day)
            ->where('id', '!=', $this->id)
            ->where(function($query) {
                $query->whereBetween('start_time', [$this->start_time, $this->end_time])
                      ->orWhereBetween('end_time', [$this->start_time, $this->end_time])
                      ->orWhere(function($q) {
                          $q->where('start_time', '<', $this->start_time)
                            ->where('end_time', '>', $this->end_time);
                      });
            })
            ->where('status', '!=', 'cancelled')
            ->get();
        
        if ($classroomConflicts->isNotEmpty()) {
            $conflicts['classroom'] = $classroomConflicts;
        }
        
        return $conflicts;
    }

    /**
     * Mark entry as completed.
     *
     * @param  string|null  $notes
     * @return bool
     */
    public function markCompleted($notes = null)
    {
        if ($this->status === 'completed') {
            throw new \Exception('Entry is already completed');
        }
        
        $this->status = 'completed';
        
        if ($notes) {
            $this->notes = ($this->notes ? $this->notes . "\n" : '') . "Completed: {$notes}";
        }
        
        $this->save();
        
        Log::info('Timetable entry completed', [
            'entry_id' => $this->id,
            'subject' => $this->subject_name,
            'teacher' => $this->teacher_name,
            'completed_by' => Auth::id()
        ]);
        
        return true;
    }

    /**
     * Cancel entry.
     *
     * @param  string  $reason
     * @return bool
     */
    public function cancel($reason)
    {
        if ($this->status === 'cancelled') {
            throw new \Exception('Entry is already cancelled');
        }
        
        $this->status = 'cancelled';
        $this->notes = ($this->notes ? $this->notes . "\n" : '') . "Cancelled: {$reason}";
        $this->save();
        
        Log::info('Timetable entry cancelled', [
            'entry_id' => $this->id,
            'subject' => $this->subject_name,
            'teacher' => $this->teacher_name,
            'reason' => $reason,
            'cancelled_by' => Auth::id()
        ]);
        
        return true;
    }

    /**
     * Reschedule entry.
     *
     * @param  string  $newDay
     * @param  string  $newStartTime
     * @param  string  $newEndTime
     * @param  string  $reason
     * @return bool
     */
    public function reschedule($newDay, $newStartTime, $newEndTime, $reason)
    {
        // Create new entry
        $newEntry = $this->replicate();
        $newEntry->day = $newDay;
        $newEntry->start_time = $newStartTime;
        $newEntry->end_time = $newEndTime;
        $newEntry->status = 'scheduled';
        $newEntry->notes = ($newEntry->notes ? $newEntry->notes . "\n" : '') . "Rescheduled from: {$this->day} {$this->time_slot}. Reason: {$reason}";
        $newEntry->created_by = Auth::id();
        $newEntry->save();
        
        // Mark original as rescheduled
        $this->status = 'rescheduled';
        $this->notes = ($this->notes ? $this->notes . "\n" : '') . "Rescheduled to: {$newDay} {$newEntry->time_slot}. Reason: {$reason}";
        $this->save();
        
        Log::info('Timetable entry rescheduled', [
            'original_entry_id' => $this->id,
            'new_entry_id' => $newEntry->id,
            'old_schedule' => "{$this->day} {$this->time_slot}",
            'new_schedule' => "{$newDay} {$newEntry->time_slot}",
            'reason' => $reason,
            'rescheduled_by' => Auth::id()
        ]);
        
        return true;
    }

    /**
     * Check if entry is happening now.
     *
     * @return bool
     */
    public function isHappeningNow()
    {
        $now = now();
        $today = $now->format('l');
        
        if ($this->day !== $today) {
            return false;
        }
        
        $currentTime = $now->format('H:i:s');
        return $currentTime >= $this->start_time->format('H:i:s') && 
               $currentTime <= $this->end_time->format('H:i:s');
    }

    /**
     * Check if entry is upcoming.
     *
     * @param  int  $minutesThreshold
     * @return bool
     */
    public function isUpcoming($minutesThreshold = 15)
    {
        $now = now();
        $today = $now->format('l');
        
        if ($this->day !== $today) {
            return false;
        }
        
        $startTime = $this->start_time;
        $timeDifference = $now->diffInMinutes($startTime, false);
        
        return $timeDifference > 0 && $timeDifference <= $minutesThreshold;
    }

    /**
     * Get entry details.
     *
     * @return array
     */
    public function getDetails()
    {
        return [
            'id' => $this->id,
            'day' => $this->day,
            'period_order' => $this->period_order,
            'time_slot' => $this->time_slot,
            'duration_minutes' => $this->duration_minutes,
            'subject' => $this->subject ? [
                'id' => $this->subject->id,
                'name' => $this->subject->name,
                'code' => $this->subject->code
            ] : null,
            'teacher' => $this->teacher ? [
                'id' => $this->teacher->id,
                'name' => $this->teacher->name,
                'email' => $this->teacher->email
            ] : null,
            'classroom' => $this->classroom ? [
                'id' => $this->classroom->id,
                'name' => $this->classroom->name,
                'room_number' => $this->classroom->room_number
            ] : null,
            'section' => $this->section ? [
                'id' => $this->section->id,
                'name' => $this->section->name
            ] : null,
            'grade_level' => $this->gradeLevel ? [
                'id' => $this->gradeLevel->id,
                'name' => $this->gradeLevel->name
            ] : null,
            'entry_type' => $this->entry_type,
            'entry_type_display' => $this->entry_type_display,
            'status' => $this->status,
            'status_display' => $this->status_display,
            'is_break' => $this->is_break,
            'break_type' => $this->break_type,
            'is_elective' => $this->is_elective,
            'elective_group' => $this->electiveGroup ? $this->electiveGroup->name : null,
            'notes' => $this->notes,
            'conflicts' => $this->conflicts
        ];
    }

    /**
     * Validate entry.
     *
     * @param  TimetableEntry  $entry
     * @return void
     * @throws \Exception
     */
    private static function validateEntry($entry)
    {
        // Validate time format
        if ($entry->start_time >= $entry->end_time) {
            throw new \Exception('Start time must be before end time');
        }
        
        // Validate day
        $validDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        if (!in_array($entry->day, $validDays)) {
            throw new \Exception('Invalid day. Must be one of: ' . implode(', ', $validDays));
        }
        
        // Check for overlapping entries (if not a break)
        if (!$entry->is_break) {
            $overlappingEntries = self::where('timetable_id', $entry->timetable_id)
                ->where('day', $entry->day)
                ->where('id', '!=', $entry->id)
                ->where(function($query) use ($entry) {
                    $query->whereBetween('start_time', [$entry->start_time, $entry->end_time])
                          ->orWhereBetween('end_time', [$entry->start_time, $entry->end_time])
                          ->orWhere(function($q) use ($entry) {
                              $q->where('start_time', '<', $entry->start_time)
                                ->where('end_time', '>', $entry->end_time);
                          });
                })
                ->where('status', '!=', 'cancelled')
                ->count();
                
            if ($overlappingEntries > 0) {
                throw new \Exception('Overlapping timetable entry exists');
            }
        }
    }

    /**
     * Get entries for a timetable.
     *
     * @param  int  $timetableId
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getForTimetable($timetableId, $filters = [])
    {
        $cacheKey = "timetable_entries_timetable_{$timetableId}_" . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHours(6), function () use ($timetableId, $filters) {
            $query = self::where('timetable_id', $timetableId)
                ->with(['subject', 'teacher', 'classroom', 'section'])
                ->orderByRaw("FIELD(day, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')")
                ->orderBy('start_time');
            
            // Apply filters
            if (isset($filters['day'])) {
                $query->where('day', $filters['day']);
            }
            
            if (isset($filters['teacher_id'])) {
                $query->where('teacher_id', $filters['teacher_id']);
            }
            
            if (isset($filters['subject_id'])) {
                $query->where('subject_id', $filters['subject_id']);
            }
            
            if (isset($filters['classroom_id'])) {
                $query->where('classroom_id', $filters['classroom_id']);
            }
            
            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            
            if (isset($filters['is_break'])) {
                $query->where('is_break', $filters['is_break']);
            }
            
            return $query->get();
        });
    }

    /**
     * Get entries for a teacher.
     *
     * @param  int  $teacherId
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getForTeacher($teacherId, $filters = [])
    {
        $cacheKey = "timetable_entries_teacher_{$teacherId}_" . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHours(6), function () use ($teacherId, $filters) {
            $query = self::where('teacher_id', $teacherId)
                ->with(['timetable', 'subject', 'classroom', 'section'])
                ->orderBy('day')
                ->orderBy('start_time');
            
            // Apply filters
            if (isset($filters['day'])) {
                $query->where('day', $filters['day']);
            }
            
            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            
            if (isset($filters['is_break'])) {
                $query->where('is_break', $filters['is_break']);
            }
            
            if (isset($filters['date'])) {
                $query->whereHas('timetable', function($q) use ($filters) {
                    $q->whereDate('start_date', '<=', $filters['date'])
                      ->whereDate('end_date', '>=', $filters['date']);
                });
            }
            
            return $query->get();
        });
    }

    /**
     * Get today's entries for a teacher.
     *
     * @param  int  $teacherId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getTodayForTeacher($teacherId)
    {
        $today = now()->format('l');
        
        return self::where('teacher_id', $teacherId)
            ->where('day', $today)
            ->where('status', 'scheduled')
            ->with(['subject', 'classroom', 'section'])
            ->orderBy('start_time')
            ->get();
    }

    /**
     * Import entries from CSV.
     *
     * @param  array  $data
     * @param  Timetable  $timetable
     * @param  User  $importer
     * @return TimetableEntry
     */
    public static function importFromCSV($data, $timetable, $importer)
    {
        $entry = new self($data);
        $entry->timetable_id = $timetable->id;
        $entry->created_by = $importer->id;
        $entry->save();
        
        Log::info('Timetable entry imported from CSV', [
            'entry_id' => $entry->id,
            'timetable_id' => $timetable->id,
            'importer_id' => $importer->id
        ]);
        
        return $entry;
    }

    /**
     * Export entry data to array.
     *
     * @return array
     */
    public function exportToArray()
    {
        return [
            'id' => $this->id,
            'timetable' => $this->timetable ? $this->timetable->name : null,
            'day' => $this->day,
            'period_order' => $this->period_order,
            'time_slot' => $this->time_slot,
            'subject' => $this->subject ? $this->subject->name : null,
            'teacher' => $this->teacher ? $this->teacher->name : null,
            'classroom' => $this->classroom ? $this->classroom->name : null,
            'section' => $this->section ? $this->section->name : null,
            'grade_level' => $this->gradeLevel ? $this->gradeLevel->name : null,
            'entry_type' => $this->entry_type,
            'is_break' => $this->is_break,
            'break_type' => $this->break_type,
            'is_elective' => $this->is_elective,
            'elective_group' => $this->electiveGroup ? $this->electiveGroup->name : null,
            'status' => $this->status,
            'notes' => $this->notes,
            'duration_minutes' => $this->duration_minutes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at
        ];
    }

    /**
     * Scope a query to only include scheduled entries.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled');
    }

    /**
     * Scope a query to only include entries for a specific day.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $day
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForDay($query, $day)
    {
        return $query->where('day', $day);
    }

    /**
     * Scope a query to only include entries for a specific period.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $periodOrder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForPeriod($query, $periodOrder)
    {
        return $query->where('period_order', $periodOrder);
    }

    /**
     * Scope a query to only include non-break entries.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeNonBreak($query)
    {
        return $query->where('is_break', false);
    }
}