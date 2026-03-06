<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assessment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'school_id',
        'subject_id',
        'class_id',
        'term_id',
        'title',
        'type',
        'total_marks',
        'passing_marks',
        'weightage',
        'assessment_date',
        'due_date',
        'start_time',
        'end_time',
        'instructions',
        'description',
        'attachments',
        'status',
        'created_by',
    ];

    protected $casts = [
        'total_marks' => 'decimal:2',
        'passing_marks' => 'decimal:2',
        'weightage' => 'decimal:2',
        'assessment_date' => 'date',
        'due_date' => 'date',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'attachments' => 'array',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function class(): BelongsTo
    {
        return $this->belongsTo(Classes::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(TermSemester::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function results(): HasMany
    {
        return $this->hasMany(Result::class);
    }
}