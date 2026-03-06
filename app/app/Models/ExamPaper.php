<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;    
use Illuminate\Support\Facades\Storage;

class ExamPaper extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'exam_papers';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'exam_id',
        'subject_id',
        'paper_type',
        'paper_code',
        'title',
        'description',
        'total_marks',
        'duration',
        'instructions',
        'file_path',
        'file_name',
        'file_size',
        'file_type',
        'version',
        'is_active',
        'is_approved',
        'approved_by',
        'approved_at',
        'created_by',
        'updated_by',
        'remarks'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'total_marks' => 'decimal:2',
        'duration' => 'integer',
        'file_size' => 'integer',
        'version' => 'integer',
        'is_active' => 'boolean',
        'is_approved' => 'boolean',
        'approved_at' => 'datetime',
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
        'paper_type_display',
        'file_url',
        'file_size_formatted',
        'is_downloadable',
        'version_display'
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $with = ['exam', 'subject', 'creator', 'approver'];

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($paper) {
            // Generate paper code if not provided
            if (empty($paper->paper_code)) {
                $paper->paper_code = self::generatePaperCode($paper);
            }
            
            // Set version if not provided
            if (empty($paper->version)) {
                $paper->version = 1;
            }
            
            // Set is_active default
            if (is_null($paper->is_active)) {
                $paper->is_active = true;
            }
            
            // Set created_by if not set
            if (empty($paper->created_by) && Auth::check()) {
                $paper->created_by = Auth::id();
            }
            
            // Validate file
            if ($paper->file_path) {
                self::validateFile($paper);
            }
            
            Log::info('Exam paper creating', [
                'exam_id' => $paper->exam_id,
                'paper_code' => $paper->paper_code,
                'title' => $paper->title,
                'created_by' => $paper->created_by
            ]);
        });

        static::updating(function ($paper) {
            // Handle approval
            if ($paper->isDirty('is_approved') && $paper->is_approved) {
                $paper->approved_at = now();
                $paper->approved_by = Auth::id();
                
                // Deactivate other versions of the same paper
                self::deactivateOtherVersions($paper);
            }
            
            // Handle version update
            if ($paper->isDirty('version')) {
                // Check if version already exists
                $existingVersion = self::where('exam_id', $paper->exam_id)
                    ->where('paper_type', $paper->paper_type)
                    ->where('version', $paper->version)
                    ->where('id', '!=', $paper->id)
                    ->first();
                    
                if ($existingVersion) {
                    throw new \Exception("Version {$paper->version} already exists for this paper type");
                }
            }
            
            // Update updated_by
            if (Auth::check()) {
                $paper->updated_by = Auth::id();
            }
        });

        static::saved(function ($paper) {
            // Clear relevant cache
            Cache::forget("exam_paper_{$paper->id}");
            Cache::forget("exam_paper_code_{$paper->paper_code}");
            Cache::tags([
                "exam_papers_exam_{$paper->exam_id}",
                "exam_papers_subject_{$paper->subject_id}",
                "exam_papers_active"
            ])->flush();
        });

        static::deleted(function ($paper) {
            // Clear cache
            Cache::forget("exam_paper_{$paper->id}");
            Cache::forget("exam_paper_code_{$paper->paper_code}");
            Cache::tags([
                "exam_papers_exam_{$paper->exam_id}",
                "exam_papers_subject_{$paper->subject_id}",
                "exam_papers_active"
            ])->flush();
            
            // Delete file if no other papers reference it
            if ($paper->file_path && self::where('file_path', $paper->file_path)->count() === 0) {
                Storage::disk('private')->delete($paper->file_path);
            }
        });
    }

    /**
     * Get the exam for this paper.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function exam()
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    /**
     * Get the subject for this paper.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    /**
     * Get the user who created this paper.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who approved this paper.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the user who last updated this paper.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get paper type display name.
     *
     * @return string
     */
    public function getPaperTypeDisplayAttribute()
    {
        $types = [
            'question_paper' => 'Question Paper',
            'answer_key' => 'Answer Key',
            'marking_scheme' => 'Marking Scheme',
            'model_answer' => 'Model Answer',
            'supplementary' => 'Supplementary Paper'
        ];
        
        return $types[$this->paper_type] ?? ucfirst($this->paper_type);
    }

    /**
     * Get file URL.
     *
     * @return string|null
     */
    public function getFileUrlAttribute()
    {
        if (!$this->file_path) {
            return null;
        }
        
        return route('exam.papers.download', $this->id);
    }

    /**
     * Get formatted file size.
     *
     * @return string
     */
    public function getFileSizeFormattedAttribute()
    {
        if (!$this->file_size) {
            return '0 B';
        }
        
        $size = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        
        return round($size, 2) . ' ' . $units[$i];
    }

    /**
     * Check if paper is downloadable.
     *
     * @return bool
     */
    public function getIsDownloadableAttribute()
    {
        return $this->file_path && Storage::disk('private')->exists($this->file_path);
    }

    /**
     * Get version display.
     *
     * @return string
     */
    public function getVersionDisplayAttribute()
    {
        return 'v' . $this->version;
    }

    /**
     * Create a new version of the paper.
     *
     * @param  array  $data
     * @return ExamPaper
     */
    public function createNewVersion($data = [])
    {
        // Get next version number
        $nextVersion = self::where('exam_id', $this->exam_id)
            ->where('paper_type', $this->paper_type)
            ->max('version') + 1;
        
        // Create new version
        $newPaper = $this->replicate();
        $newPaper->version = $nextVersion;
        $newPaper->is_approved = false;
        $newPaper->approved_by = null;
        $newPaper->approved_at = null;
        $newPaper->remarks = 'New version created from v' . $this->version;
        
        // Update with provided data
        $newPaper->fill($data);
        
        // Set created_by
        $newPaper->created_by = Auth::id();
        
        $newPaper->save();
        
        return $newPaper;
    }

    /**
     * Approve the paper.
     *
     * @param  User|null  $approver
     * @param  string|null  $remarks
     * @return bool
     */
    public function approve($approver = null, $remarks = null)
    {
        if (!$approver) {
            $approver = Auth::user();
        }
        
        if ($this->is_approved) {
            throw new \Exception('Paper is already approved');
        }
        
        $this->is_approved = true;
        $this->approved_at = now();
        $this->approved_by = $approver->id;
        
        if ($remarks) {
            $this->remarks = $remarks;
        }
        
        // Deactivate other versions
        self::deactivateOtherVersions($this);
        
        $this->save();
        
        return true;
    }

    /**
     * Deactivate the paper.
     *
     * @return bool
     */
    public function deactivate()
    {
        if (!$this->is_active) {
            throw new \Exception('Paper is already deactivated');
        }
        
        $this->is_active = false;
        $this->save();
        
        return true;
    }

    /**
     * Activate the paper.
     *
     * @return bool
     */
    public function activate()
    {
        if ($this->is_active) {
            throw new \Exception('Paper is already active');
        }
        
        $this->is_active = true;
        $this->save();
        
        return true;
    }

    /**
     * Generate paper code.
     *
     * @param  ExamPaper  $paper
     * @return string
     */
    private static function generatePaperCode($paper)
    {
        $examCode = $paper->exam ? $paper->exam->code : 'EXAM';
        $paperType = strtoupper(substr($paper->paper_type, 0, 2));
        
        do {
            $random = strtoupper(\Illuminate\Support\Str::random(4));
            $code = "{$examCode}{$paperType}{$random}";
        } while (self::where('paper_code', $code)->exists());
        
        return $code;
    }

    /**
     * Validate file.
     *
     * @param  ExamPaper  $paper
     * @return void
     * @throws \Exception
     */
    private static function validateFile($paper)
    {
        if (!Storage::disk('private')->exists($paper->file_path)) {
            throw new \Exception('File does not exist');
        }
        
        // Validate file type
        $allowedTypes = ['pdf', 'doc', 'docx', 'txt'];
        $extension = pathinfo($paper->file_name, PATHINFO_EXTENSION);
        
        if (!in_array(strtolower($extension), $allowedTypes)) {
            throw new \Exception('Invalid file type. Allowed types: ' . implode(', ', $allowedTypes));
        }
        
        // Validate file size (max 10MB)
        $maxSize = 10 * 1024 * 1024; // 10MB in bytes
        if ($paper->file_size > $maxSize) {
            throw new \Exception('File size exceeds maximum limit of 10MB');
        }
    }

    /**
     * Deactivate other versions of the same paper.
     *
     * @param  ExamPaper  $paper
     * @return void
     */
    private static function deactivateOtherVersions($paper)
    {
        self::where('exam_id', $paper->exam_id)
            ->where('paper_type', $paper->paper_type)
            ->where('id', '!=', $paper->id)
            ->update(['is_active' => false]);
    }

    /**
     * Get papers for an exam.
     *
     * @param  int  $examId
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getForExam($examId, $filters = [])
    {
        $cacheKey = "exam_papers_exam_{$examId}_" . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHours(6), function () use ($examId, $filters) {
            $query = self::where('exam_id', $examId)
                ->with(['subject', 'creator', 'approver'])
                ->orderBy('paper_type')
                ->orderBy('version', 'desc');
            
            // Apply filters
            if (isset($filters['paper_type'])) {
                $query->where('paper_type', $filters['paper_type']);
            }
            
            if (isset($filters['is_active'])) {
                $query->where('is_active', $filters['is_active']);
            }
            
            if (isset($filters['is_approved'])) {
                $query->where('is_approved', $filters['is_approved']);
            }
            
            return $query->get();
        });
    }

    /**
     * Get approved papers for an exam.
     *
     * @param  int  $examId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getApprovedForExam($examId)
    {
        return self::where('exam_id', $examId)
            ->where('is_approved', true)
            ->where('is_active', true)
            ->orderBy('paper_type')
            ->orderBy('version', 'desc')
            ->get();
    }

    /**
     * Scope a query to only include active papers.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include approved papers.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeApproved($query)
    {
        return $query->where('is_approved', true);
    }

    /**
     * Scope a query to only include papers of a specific type.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $type
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('paper_type', $type);
    }

    /**
     * Scope a query to only include papers for a specific exam.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $examId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForExam($query, $examId)
    {
        return $query->where('exam_id', $examId);
    }

    /**
     * Scope a query to only include latest versions.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeLatestVersions($query)
    {
        return $query->whereIn('id', function($q) {
            $q->selectRaw('MAX(id)')
              ->from('exam_papers')
              ->groupBy(['exam_id', 'paper_type']);
        });
    }
}