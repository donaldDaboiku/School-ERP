<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use App\Models\RemarkActivityLog;
use App\Models\RemarkComment;
use App\Models\RemarkAttachment;

class Remark extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'remarks';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'remarkable_type',
        'remarkable_id',
        'type',
        'title',
        'content',
        'priority',
        'status',
        'is_private',
        'is_confidential',
        'requires_followup',
        'followup_date',
        'followup_completed',
        'followup_notes',
        'created_by',
        'assigned_to',
        'tags',
        'metadata',
        'academic_year',
        'term'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_private' => 'boolean',
        'is_confidential' => 'boolean',
        'requires_followup' => 'boolean',
        'followup_completed' => 'boolean',
        'followup_date' => 'date',
        'tags' => 'array',
        'metadata' => 'json',
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
        'type_display',
        'priority_display',
        'status_display',
        'is_overdue',
        'days_until_followup',
        'truncated_content',
        'author_name',
        'assigned_to_name'
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $with = ['author', 'assignee'];

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($remark) {
            // Set default values
            if (empty($remark->priority)) {
                $remark->priority = 'medium';
            }

            if (empty($remark->status)) {
                $remark->status = 'open';
            }

            if (empty($remark->academic_year)) {
                $remark->academic_year = self::getCurrentAcademicYear();
            }

            if (empty($remark->term)) {
                $remark->term = self::getCurrentTerm();
            }

            // Set created_by if not set
            if (empty($remark->created_by) && Auth::check()) {
                $remark->created_by = Auth::id();
            }

            // Generate title if not provided
            if (empty($remark->title) && !empty($remark->content)) {
                $remark->title = Str::limit($remark->content, 50);
            }

            // Validate followup date
            if ($remark->requires_followup && $remark->followup_date) {
                if ($remark->followup_date < now()) {
                    throw new \Exception('Follow-up date cannot be in the past');
                }
            }
        });

        static::updating(function ($remark) {
            // Check if followup was completed
            if ($remark->isDirty('followup_completed') && $remark->followup_completed) {
                $remark->followup_completed_at = now();
                $remark->followup_completed_by = Auth::id();
            }

            // Check if status changed to closed
            if ($remark->isDirty('status') && $remark->status === 'closed') {
                $remark->closed_at = now();
                $remark->closed_by = Auth::id();

                // Auto-complete followup if not already
                if ($remark->requires_followup && !$remark->followup_completed) {
                    $remark->followup_completed = true;
                    $remark->followup_completed_at = now();
                    $remark->followup_completed_by = Auth::id();
                }
            }

            // Check if status changed from closed
            if ($remark->isDirty('status') && $remark->getOriginal('status') === 'closed') {
                $remark->closed_at = null;
                $remark->closed_by = null;
            }
        });

        static::saved(function ($remark) {
            // Clear relevant cache
            Cache::forget("remark_{$remark->id}");
            Cache::tags([
                "remarks_{$remark->remarkable_type}_{$remark->remarkable_id}",
                "remarks_user_{$remark->created_by}",
                "remarks_assigned_{$remark->assigned_to}"
            ])->flush();

            // Create activity log
            $remark->createActivityLog('saved');
        });

        static::deleted(function ($remark) {
            // Clear cache
            Cache::forget("remark_{$remark->id}");
            Cache::tags([
                "remarks_{$remark->remarkable_type}_{$remark->remarkable_id}",
                "remarks_user_{$remark->created_by}",
                "remarks_assigned_{$remark->assigned_to}"
            ])->flush();

            // Create activity log for deletion
            $remark->createActivityLog('deleted');
        });

        static::restored(function ($remark) {
            // Clear cache
            Cache::tags([
                "remarks_{$remark->remarkable_type}_{$remark->remarkable_id}",
                "remarks_user_{$remark->created_by}",
                "remarks_assigned_{$remark->assigned_to}"
            ])->flush();

            // Create activity log for restoration
            $remark->createActivityLog('restored');
        });
    }

    /**
     * Get the parent remarkable model (student, teacher, etc.).
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function remarkable()
    {
        return $this->morphTo();
    }

    /**
     * Get the user who created the remark.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user assigned to follow up on the remark.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Get the user who closed the remark.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * Get the user who completed the follow-up.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function followupCompletedBy()
    {
        return $this->belongsTo(User::class, 'followup_completed_by');
    }

    /**
     * Get comments on the remark.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function comments()
    {
        return $this->hasMany(RemarkComment::class, 'remark_id')->orderBy('created_at', 'desc');
    }

    /**
     * Get attachments for the remark.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function attachments()
    {
        return $this->hasMany(RemarkAttachment::class, 'remark_id');
    }

    /**
     * Get activity logs for the remark.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function activityLogs()
    {
        return $this->hasMany(RemarkActivityLog::class, 'remark_id')->orderBy('created_at', 'desc');
    }

    /**
     * Get type display name.
     *
     * @return string
     */
    public function getTypeDisplayAttribute()
    {
        $types = [
            'academic' => 'Academic',
            'behavioral' => 'Behavioral',
            'attendance' => 'Attendance',
            'health' => 'Health',
            'disciplinary' => 'Disciplinary',
            'achievement' => 'Achievement',
            'concern' => 'Concern',
            'recommendation' => 'Recommendation',
            'general' => 'General',
            'parent_communication' => 'Parent Communication'
        ];

        return $types[$this->type] ?? ucfirst($this->type);
    }

    /**
     * Get priority display name.
     *
     * @return string
     */
    public function getPriorityDisplayAttribute()
    {
        $priorities = [
            'low' => 'Low',
            'medium' => 'Medium',
            'high' => 'High',
            'critical' => 'Critical'
        ];

        return $priorities[$this->priority] ?? ucfirst($this->priority);
    }

    /**
     * Get status display name.
     *
     * @return string
     */
    public function getStatusDisplayAttribute()
    {
        $statuses = [
            'open' => 'Open',
            'in_progress' => 'In Progress',
            'resolved' => 'Resolved',
            'closed' => 'Closed',
            'archived' => 'Archived'
        ];

        return $statuses[$this->status] ?? ucfirst($this->status);
    }

    /**
     * Check if follow-up is overdue.
     *
     * @return bool
     */
    public function getIsOverdueAttribute()
    {
        if (!$this->requires_followup || $this->followup_completed) {
            return false;
        }

        return $this->followup_date && $this->followup_date < now();
    }

    /**
     * Get days until follow-up (negative if overdue).
     *
     * @return int|null
     */
    public function getDaysUntilFollowupAttribute()
    {
        if (!$this->followup_date) {
            return null;
        }

        return now()->diffInDays($this->followup_date, false);
    }

    /**
     * Get truncated content for preview.
     *
     * @return string
     */
    public function getTruncatedContentAttribute()
    {
        if (strlen($this->content) <= 100) {
            return $this->content;
        }

        return substr($this->content, 0, 100) . '...';
    }

    /**
     * Get author's name.
     *
     * @return string
     */
    public function getAuthorNameAttribute()
    {
        return $this->author ? $this->author->name : 'System';
    }

    /**
     * Get assignee's name.
     *
     * @return string|null
     */
    public function getAssignedToNameAttribute()
    {
        return $this->assignee ? $this->assignee->name : null;
    }

    /**
     * Get the CSS class for priority.
     *
     * @return string
     */
    public function getPriorityClass()
    {
        $classes = [
            'low' => 'bg-blue-100 text-blue-800',
            'medium' => 'bg-yellow-100 text-yellow-800',
            'high' => 'bg-orange-100 text-orange-800',
            'critical' => 'bg-red-100 text-red-800'
        ];

        return $classes[$this->priority] ?? 'bg-gray-100 text-gray-800';
    }

    /**
     * Get the CSS class for status.
     *
     * @return string
     */
    public function getStatusClass()
    {
        $classes = [
            'open' => 'bg-blue-100 text-blue-800',
            'in_progress' => 'bg-yellow-100 text-yellow-800',
            'resolved' => 'bg-green-100 text-green-800',
            'closed' => 'bg-gray-100 text-gray-800',
            'archived' => 'bg-purple-100 text-purple-800'
        ];

        return $classes[$this->status] ?? 'bg-gray-100 text-gray-800';
    }

    /**
     * Check if the remark can be viewed by a user.
     *
     * @param  User|null  $user
     * @return bool
     */
    public function canView($user = null)
    {
        if (!$user) {
            $user = Auth::user();
        }

        if (!$user) {
            return false;
        }

        if (!$user instanceof User) {
            return false;
        }

        // Super admin can view everything
        if ($user->hasRole('super-admin')) {
            return true;
        }

        // Check if remark is confidential
        if ($this->is_confidential) {
            // Only author, assignee, and admins can view confidential remarks
            return $this->created_by === $user->id ||
                $this->assigned_to === $user->id ||
                $user->hasRole('admin');
        }

        // Check if remark is private
        if ($this->is_private) {
            // Only author and assignee can view private remarks
            return $this->created_by === $user->id || $this->assigned_to === $user->id;
        }

        // For student remarks, check if user is parent of the student
        if ($this->remarkable_type === 'App\Models\Student') {
            if ($user->hasRole('parent')) {
                return $user->parent->students->contains('id', $this->remarkable_id);
            }

            // Teachers can view their students' remarks
            if ($user->hasRole('teacher')) {
                $student = $this->remarkable;
                return $student && $student->class && $student->class->teachers->contains('id', $user->teacher->id);
            }
        }

        // For teacher remarks, check if user is admin or the teacher
        if ($this->remarkable_type === 'App\Models\Teacher') {
            if ($user->hasRole('teacher')) {
                return $this->remarkable_id === $user->teacher->id;
            }
        }

        // Default: admin, teacher, and staff can view
        return $user->hasAnyRole(['admin', 'teacher', 'staff']);
    }

    /**
     * Check if the remark can be edited by a user.
     *
     * @param  User|null  $user
     * @return bool
     */
    public function canEdit($user = null)
    {
        if (!$user) {
            $user = Auth::user();
        }

        if (!$user) {
            return false;
        }

        if (!$user instanceof User) {
            return false;
        }

        // Cannot edit closed or archived remarks
        if (in_array($this->status, ['closed', 'archived'])) {
            return false;
        }

        // Super admin and admin can edit everything
        if ($user->hasAnyRole(['super-admin', 'admin'])) {
            return true;
        }

        // Author can edit their own remarks
        if ($this->created_by === $user->id) {
            return true;
        }

        // Assignee can edit remarks assigned to them
        if ($this->assigned_to === $user->id) {
            return true;
        }

        return false;
    }

    /**
     * Add a comment to the remark.
     *
     * @param  string  $content
     * @param  User|null  $user
     * @param  bool  $isInternal
     * @return RemarkComment
     */
    public function addComment($content, $user = null, $isInternal = false)
    {
        if (!$user) {
            $user = Auth::user();
        }

        $comment = $this->comments()->create([
            'content' => $content,
            'created_by' => $user->id,
            'is_internal' => $isInternal
        ]);

        // Create activity log
        $this->createActivityLog('comment_added', ['comment_id' => $comment->id]);

        // Send notifications if comment is not internal
        if (!$isInternal) {
            $this->notifyNewComment($comment);
        }

        return $comment;
    }

    /**
     * Add an attachment to the remark.
     *
     * @param  string  $filePath
     * @param  string  $fileName
     * @param  string  $fileType
     * @param  User|null  $user
     * @return RemarkAttachment
     */
    public function addAttachment($filePath, $fileName, $fileType, $user = null)
    {
        if (!$user) {
            $user = Auth::user();
        }

        $attachment = $this->attachments()->create([
            'file_path' => $filePath,
            'file_name' => $fileName,
            'file_type' => $fileType,
            'file_size' => filesize($filePath),
            'uploaded_by' => $user->id
        ]);

        // Create activity log
        $this->createActivityLog('attachment_added', ['attachment_id' => $attachment->id]);

        return $attachment;
    }

    /**
     * Assign the remark to a user.
     *
     * @param  User  $user
     * @param  User|null  $assignedBy
     * @return bool
     */
    public function assignTo(User $user, $assignedBy = null)
    {
        if (!$assignedBy) {
            $assignedBy = Auth::user();
        }

        $this->assigned_to = $user->id;
        $this->save();

        // Create activity log
        $this->createActivityLog('assigned', [
            'assigned_to' => $user->id,
            'assigned_by' => $assignedBy->id
        ]);

        // Send notification to assignee
        $this->notifyAssignment($user, $assignedBy);

        return true;
    }

    /**
     * Update the remark status.
     *
     * @param  string  $status
     * @param  User|null  $updatedBy
     * @param  string|null  $notes
     * @return bool
     */
    public function updateStatus($status, $updatedBy = null, $notes = null)
    {
        if (!$updatedBy) {
            $updatedBy = Auth::user();
        }

        $oldStatus = $this->status;
        $this->status = $status;

        if ($notes) {
            $this->addComment("Status changed from {$oldStatus} to {$status}: {$notes}", $updatedBy, true);
        }

        $this->save();

        // Create activity log
        $this->createActivityLog('status_changed', [
            'from' => $oldStatus,
            'to' => $status,
            'updated_by' => $updatedBy->id
        ]);

        // Send notifications if status changed to resolved or closed
        if (in_array($status, ['resolved', 'closed'])) {
            $this->notifyStatusChange($oldStatus, $status, $updatedBy);
        }

        return true;
    }

    /**
     * Mark follow-up as completed.
     *
     * @param  User|null  $completedBy
     * @param  string|null  $notes
     * @return bool
     */
    public function completeFollowup($completedBy = null, $notes = null)
    {
        if (!$completedBy) {
            $completedBy = Auth::user();
        }

        $this->followup_completed = true;
        $this->followup_completed_at = now();
        $this->followup_completed_by = $completedBy->id;

        if ($notes) {
            $this->followup_notes = $notes;
        }

        $this->save();

        // Create activity log
        $this->createActivityLog('followup_completed', [
            'completed_by' => $completedBy->id,
            'notes' => $notes
        ]);

        // Send notification
        $this->notifyFollowupCompleted($completedBy);

        return true;
    }

    /**
     * Create an activity log entry.
     *
     * @param  string  $action
     * @param  array  $data
     * @return RemarkActivityLog
     */
    public function createActivityLog($action, $data = [])
    {
        $log = $this->activityLogs()->create([
            'action' => $action,
            'performed_by' => Auth::id(),
            'data' => $data,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);

        return $log;
    }

    /**
     * Get remarks for a specific model.
     *
     * @param  string  $modelType
     * @param  int  $modelId
     * @param  User|null  $user
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getForModel($modelType, $modelId, $user = null)
    {
        $cacheKey = "remarks_{$modelType}_{$modelId}_user_" . ($user ? $user->id : 'guest');

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($modelType, $modelId, $user) {
            $query = self::where('remarkable_type', $modelType)
                ->where('remarkable_id', $modelId)
                ->with(['author', 'assignee', 'comments' => function ($q) use ($user) {
                    $q->where('is_internal', false)
                        ->orWhere(function ($q) use ($user) {
                            if ($user) {
                                $q->where('is_internal', true)
                                    ->where('created_by', $user->id);
                            }
                        });
                }])
                ->orderBy('priority', 'desc')
                ->orderBy('created_at', 'desc');

            // Apply visibility filters
            if ($user) {
                $query->where(function ($q) use ($user) {
                    $q->where('is_confidential', false)
                        ->orWhere(function ($q) use ($user) {
                            $q->where('is_confidential', true)
                                ->where(function ($q2) use ($user) {
                                    $q2->where('created_by', $user->id)
                                        ->orWhere('assigned_to', $user->id)
                                        ->orWhereHas('remarkable', function ($q3) use ($user) {
                                            // Add specific permission checks based on model type
                                        });
                                });
                        });
                });
            } else {
                $query->where('is_confidential', false)
                    ->where('is_private', false);
            }

            return $query->get();
        });
    }

    /**
     * Get remarks assigned to a user.
     *
     * @param  User  $user
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getAssignedToUser(User $user, $filters = [])
    {
        $cacheKey = "remarks_assigned_{$user->id}_" . md5(json_encode($filters));

        return Cache::remember($cacheKey, now()->addHours(2), function () use ($user, $filters) {
            $query = self::where('assigned_to', $user->id)
                ->with(['author', 'remarkable'])
                ->orderBy('priority', 'desc')
                ->orderBy('followup_date', 'asc')
                ->orderBy('created_at', 'desc');

            // Apply filters
            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (isset($filters['type'])) {
                $query->where('type', $filters['type']);
            }

            if (isset($filters['priority'])) {
                $query->where('priority', $filters['priority']);
            }

            if (isset($filters['overdue']) && $filters['overdue']) {
                $query->where('requires_followup', true)
                    ->where('followup_completed', false)
                    ->where('followup_date', '<', now());
            }

            if (isset($filters['academic_year'])) {
                $query->where('academic_year', $filters['academic_year']);
            }

            return $query->get();
        });
    }

    /**
     * Get statistics for remarks.
     *
     * @param  array  $filters
     * @return array
     */
    public static function getStatistics($filters = [])
    {
        $cacheKey = "remarks_statistics_" . md5(json_encode($filters));

        return Cache::remember($cacheKey, now()->addHours(1), function () use ($filters) {
            $query = self::query();

            // Apply filters
            if (isset($filters['academic_year'])) {
                $query->where('academic_year', $filters['academic_year']);
            }

            if (isset($filters['term'])) {
                $query->where('term', $filters['term']);
            }

            if (isset($filters['type'])) {
                $query->where('type', $filters['type']);
            }

            $total = $query->count();
            $byStatus = $query->selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            $byPriority = $query->selectRaw('priority, count(*) as count')
                ->groupBy('priority')
                ->pluck('count', 'priority')
                ->toArray();

            $byType = $query->selectRaw('type, count(*) as count')
                ->groupBy('type')
                ->pluck('count', 'type')
                ->toArray();

            $overdue = $query->where('requires_followup', true)
                ->where('followup_completed', false)
                ->where('followup_date', '<', now())
                ->count();

            return [
                'total' => $total,
                'by_status' => $byStatus,
                'by_priority' => $byPriority,
                'by_type' => $byType,
                'overdue' => $overdue,
                'average_resolution_time' => self::getAverageResolutionTime($filters)
            ];
        });
    }

    /**
     * Get average resolution time for remarks.
     *
     * @param  array  $filters
     * @return string|null
     */
    private static function getAverageResolutionTime($filters = [])
    {
        $query = self::where('status', 'closed')
            ->whereNotNull('closed_at')
            ->whereNotNull('created_at');

        // Apply filters
        if (isset($filters['academic_year'])) {
            $query->where('academic_year', $filters['academic_year']);
        }

        if (isset($filters['term'])) {
            $query->where('term', $filters['term']);
        }

        $remarks = $query->get(['created_at', 'closed_at']);

        if ($remarks->isEmpty()) {
            return null;
        }

        $totalDays = 0;
        foreach ($remarks as $remark) {
            $totalDays += $remark->created_at->diffInDays($remark->closed_at);
        }

        $averageDays = $totalDays / $remarks->count();

        if ($averageDays < 1) {
            return 'Less than a day';
        } elseif ($averageDays < 7) {
            return round($averageDays) . ' days';
        } else {
            return round($averageDays / 7, 1) . ' weeks';
        }
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

        // Academic year typically runs from August to July
        if ($month >= 8) {
            return $year . '-' . ($year + 1);
        } else {
            return ($year - 1) . '-' . $year;
        }
    }

    /**
     * Get current term.
     *
     * @return string
     */
    public static function getCurrentTerm()
    {
        $month = date('m');

        if ($month >= 1 && $month <= 4) {
            return 'term1';
        } elseif ($month >= 5 && $month <= 8) {
            return 'term2';
        } else {
            return 'term3';
        }
    }

    /**
     * Get type options.
     *
     * @return array
     */
    public static function getTypeOptions()
    {
        return [
            'academic' => 'Academic',
            'behavioral' => 'Behavioral',
            'attendance' => 'Attendance',
            'health' => 'Health',
            'disciplinary' => 'Disciplinary',
            'achievement' => 'Achievement',
            'concern' => 'Concern',
            'recommendation' => 'Recommendation',
            'general' => 'General',
            'parent_communication' => 'Parent Communication'
        ];
    }

    /**
     * Get priority options.
     *
     * @return array
     */
    public static function getPriorityOptions()
    {
        return [
            'low' => 'Low',
            'medium' => 'Medium',
            'high' => 'High',
            'critical' => 'Critical'
        ];
    }

    /**
     * Get status options.
     *
     * @return array
     */
    public static function getStatusOptions()
    {
        return [
            'open' => 'Open',
            'in_progress' => 'In Progress',
            'resolved' => 'Resolved',
            'closed' => 'Closed',
            'archived' => 'Archived'
        ];
    }

    /**
     * Get term options.
     *
     * @return array
     */
    public static function getTermOptions()
    {
        return [
            'term1' => 'Term 1',
            'term2' => 'Term 2',
            'term3' => 'Term 3',
            'term4' => 'Term 4'
        ];
    }

    /**
     * Scope a query to only include remarks for a specific model.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $modelType
     * @param  int  $modelId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForModel($query, $modelType, $modelId)
    {
        return $query->where('remarkable_type', $modelType)
            ->where('remarkable_id', $modelId);
    }

    /**
     * Scope a query to only include remarks of a specific type.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $type
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope a query to only include remarks with a specific status.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $status
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to only include remarks with a specific priority.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $priority
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithPriority($query, $priority)
    {
        return $query->where('priority', $priority);
    }

    /**
     * Scope a query to only include remarks that require follow-up.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRequiresFollowup($query)
    {
        return $query->where('requires_followup', true);
    }

    /**
     * Scope a query to only include overdue remarks.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOverdue($query)
    {
        return $query->where('requires_followup', true)
            ->where('followup_completed', false)
            ->where('followup_date', '<', now());
    }

    /**
     * Scope a query to only include remarks for a specific academic year.
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
     * Scope a query to only include remarks for a specific term.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $term
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForTerm($query, $term)
    {
        return $query->where('term', $term);
    }

    /**
     * Scope a query to only include remarks created by a specific user.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCreatedBy($query, $userId)
    {
        return $query->where('created_by', $userId);
    }

    /**
     * Scope a query to only include remarks assigned to a specific user.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAssignedTo($query, $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    /**
     * Scope a query to only include public remarks.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePublic($query)
    {
        return $query->where('is_private', false)
            ->where('is_confidential', false);
    }

    /**
     * Scope a query to only include private remarks for a user.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  User  $user
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeVisibleTo($query, User $user)
    {
        return $query->where(function ($q) use ($user) {
            $q->where('is_private', false)
                ->orWhere('created_by', $user->id)
                ->orWhere('assigned_to', $user->id);
        })->where(function ($q) use ($user) {
            $q->where('is_confidential', false)
                ->orWhere('created_by', $user->id)
                ->orWhere('assigned_to', $user->id)
                ->orWhere($user->hasAnyRole(['super-admin', 'admin']));
        });
    }

    // Notification methods (to be implemented based on your notification system)
    private function notifyNewComment($comment) {}
    private function notifyAssignment($user, $assignedBy) {}
    private function notifyStatusChange($oldStatus, $newStatus, $updatedBy) {}
    private function notifyFollowupCompleted($completedBy) {}
}
