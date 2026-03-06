<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Teacher extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'school_id',
        'teacher_id',
        'employment_type',
        'employment_date',
        'contract_end_date',
        'qualification',
        'specialization',
        'years_of_experience',
        'salary_scale',
        'bank_name',
        'account_number',
        'account_name',
        'pension_number',
        'tax_identification_number',
        'subjects_expertise',
        'classes_assigned',
        'teaching_level',
        'is_class_teacher',
        'is_head_of_department',
        'department',
        'notes',
        'custom_fields',
    ];

    protected $casts = [
        'employment_date' => 'date',
        'contract_end_date' => 'date',
        'subjects_expertise' => 'array',
        'classes_assigned' => 'array',
        'custom_fields' => 'array',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function classes(): BelongsToMany
    {
        return $this->belongsToMany(Classes::class, 'class_teacher')
            ->withTimestamps();
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'teacher_subject')
            ->withTimestamps();
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class, 'created_by');
    }

    public function remarks(): HasMany
    {
        return $this->hasMany(Remark::class, 'teacher_id');
    }

    // Accessors
    public function getFullNameAttribute(): string
    {
        return $this->user->name ?? '';
    }

    public function getEmailAttribute(): string
    {
        return $this->user->email ?? '';
    }

    public function getPhoneAttribute(): ?string
    {
        return $this->user->phone ?? null;
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->user && $this->user->status === 'active';
    }

    public function getEmploymentYearsAttribute(): int
    {
        if (!$this->employment_date) {
            return 0;
        }
        
        return now()->diffInYears($this->employment_date);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->whereHas('user', function ($q) {
            $q->where('status', 'active');
        });
    }

    public function scopeClassTeacher($query)
    {
        return $query->where('is_class_teacher', true);
    }

    public function scopeSearch($query, $search)
    {
        return $query->whereHas('user', function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('email', 'like', "%{$search}%");
        })->orWhere('teacher_id', 'like', "%{$search}%");
    }

    // Methods
    public function getAssignedClasses()
    {
        if ($this->classes_assigned && is_array($this->classes_assigned)) {
            return Classes::whereIn('id', $this->classes_assigned)->get();
        }
        
        return $this->classes;
    }

    public function getAssignedSubjects()
    {
        if ($this->subjects_expertise && is_array($this->subjects_expertise)) {
            return Subject::whereIn('id', $this->subjects_expertise)->get();
        }
        
        return $this->subjects;
    }

    public function isClassTeacherOf($classId): bool
    {
        return $this->is_class_teacher && 
               $this->classes()->where('classes.id', $classId)->exists();
    }
}