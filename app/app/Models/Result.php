<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Result extends Model
{
    protected $fillable = [
        'school_id',
        'student_id',
        'class_id',
        'subject_id',
        'assessment_id',
        'exam_id',
        'academic_session_id',
        'term_id',
        'marks_obtained',
        'total_marks',
        'grade',
        'grade_point',
        'teacher_comment',
        'status',
        'graded_by',
        'published_at',
        'is_finalized',
    ];

    protected $casts = [
        'marks_obtained' => 'decimal:2',
        'total_marks' => 'decimal:2',
        'grade_point' => 'decimal:2',
        'published_at' => 'datetime',
        'is_finalized' => 'boolean',
    ];

    // Relationships
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function class(): BelongsTo
    {
        return $this->belongsTo(Classes::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(TermSemester::class);
    }

    public function gradedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }

    // Accessors
    public function getPercentageAttribute(): float
    {
        if ($this->total_marks <= 0) return 0;
        return ($this->marks_obtained / $this->total_marks) * 100;
    }

    public function getIsPassedAttribute(): bool
    {
        $passScore = $this->subject->pass_score ?? 40;
        return $this->percentage >= $passScore;
    }

    public function getFormattedMarksAttribute(): string
    {
        return "{$this->marks_obtained}/{$this->total_marks}";
    }

    public function getIsPublishedAttribute(): bool
    {
        return $this->status === 'published' && $this->published_at !== null;
    }

    // Scopes
    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeFinalized($query)
    {
        return $query->where('is_finalized', true);
    }

    public function scopeForStudent($query, $studentId)
    {
        return $query->where('student_id', $studentId);
    }

    public function scopeForSubject($query, $subjectId)
    {
        return $query->where('subject_id', $subjectId);
    }

    public function scopeForClass($query, $classId)
    {
        return $query->where('class_id', $classId);
    }

    public function scopeForTerm($query, $termId)
    {
        return $query->where('term_id', $termId);
    }

    // Methods
    public function publish(): void
    {
        $this->update([
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    public function finalize(): void
    {
        $this->update([
            'is_finalized' => true,
        ]);
    }

    public function calculateGrade(string $gradingSystem = 'percentage'): string
    {
        $percentage = $this->percentage;
        
        if ($percentage >= 70) return 'A';
        if ($percentage >= 60) return 'B';
        if ($percentage >= 50) return 'C';
        if ($percentage >= 45) return 'D';
        if ($percentage >= 40) return 'E';
        return 'F';
    }
}