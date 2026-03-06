<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Student extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'school_id',
        'class_id',
        'admission_number',
        'admission_date',
        'admission_type',
        'student_category',
        'previous_school',
        'previous_grade',
        'academic_stream',
        'blood_group',
        'genotype',
        'health_conditions',
        'allergies',
        'notes',
        'custom_fields',
    ];

    protected $casts = [
        'admission_date' => 'date',
        'custom_fields' => 'array',
        'previous_grade' => 'decimal:2',
    ];

    // ---------------- RELATIONSHIPS ----------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classes::class, 'class_id');
    }

    public function parents(): BelongsToMany
    {
        return $this->belongsToMany(Parents::class, 'parent_student', 'student_id', 'parent_id')
                    ->withPivot('relationship', 'is_primary')
                    ->withTimestamps();
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(Result::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    // ---------------- ACCESSORS ----------------

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

    public function getAgeAttribute(): ?int
    {
        if (!$this->user || !$this->user->date_of_birth) return null;
        return now()->diffInYears($this->user->date_of_birth);
    }

    public function getGenderAttribute(): ?string
    {
        return $this->user->gender ?? null;
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->user && $this->user->status === 'active';
    }
}
