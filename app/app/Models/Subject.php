<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'school_id',
        'name',
        'code',
        'short_name',
        'type',
        'position',
        'has_practical',
        'max_score',
        'pass_score',
        'description',
    ];

    protected $casts = [
        'max_score' => 'decimal:2',
        'pass_score' => 'decimal:2',
        'has_practical' => 'boolean',
    ];

    // Relationships
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function classes(): BelongsToMany
    {
        return $this->belongsToMany(Classes::class, 'class_subject')
            ->withTimestamps();
    }

    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(Teacher::class, 'teacher_subject')
            ->withTimestamps();
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(Result::class);
    }

    // Accessors
    public function getIsCoreAttribute(): bool
    {
        return $this->type === 'core';
    }

    public function getIsElectiveAttribute(): bool
    {
        return $this->type === 'elective';
    }

    // Scopes
    public function scopeCore($query)
    {
        return $query->where('type', 'core');
    }

    public function scopeElective($query)
    {
        return $query->where('type', 'elective');
    }

    public function scopeForSchool($query, $schoolId)
    {
        return $query->where('school_id', $schoolId);
    }

    // Methods
    public function getClassCount(): int
    {
        return $this->classes()->count();
    }

    public function getTeacherCount(): int
    {
        return $this->teachers()->count();
    }
}