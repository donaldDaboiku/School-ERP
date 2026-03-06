<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Classes extends Model
{
    use SoftDeletes;

    protected $table = 'classes';

    protected $fillable = [
        'school_id',
        'class_level_id',
        'name',
        'code',
        'room_number',
        'capacity',
        'student_count',
        'class_teacher_id',
        'academic_session_id',
        'term_id',
        'status',
    ];

    // Relationships
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function classLevel(): BelongsTo
    {
        return $this->belongsTo(ClassLevel::class);
    }

    public function classTeacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'class_teacher_id');
    }

    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(TermSemester::class, 'term_id');
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'class_id');
    }

    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(Teacher::class, 'class_teacher')
            ->withTimestamps();
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'class_subject')
            ->withTimestamps();
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    // Accessors
    public function getAvailableSeatsAttribute(): int
    {
        return max(0, $this->capacity - $this->student_count);
    }

    public function getIsFullAttribute(): bool
    {
        return $this->student_count >= $this->capacity;
    }

    public function getMaleStudentsCountAttribute(): int
    {
        return $this->students()->whereHas('user', function ($q) {
            $q->where('gender', 'male');
        })->count();
    }

    public function getFemaleStudentsCountAttribute(): int
    {
        return $this->students()->whereHas('user', function ($q) {
            $q->where('gender', 'female');
        })->count();
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForSchool($query, $schoolId)
    {
        return $query->where('school_id', $schoolId);
    }

    public function scopeForAcademicSession($query, $sessionId)
    {
        return $query->where('academic_session_id', $sessionId);
    }

    public function scopeForClassLevel($query, $levelId)
    {
        return $query->where('class_level_id', $levelId);
    }

    // Methods
    public function updateStudentCount(): void
    {
        $this->update([
            'student_count' => $this->students()->count()
        ]);
    }

    public function getClassAverage($subjectId = null, $termId = null): float
    {
        $query = Result::where('class_id', $this->id)
            ->where('is_finalized', true);
        
        if ($subjectId) {
            $query->where('subject_id', $subjectId);
        }
        
        if ($termId) {
            $query->where('term_id', $termId);
        }
        
        return $query->avg('percentage') ?? 0;
    }
}