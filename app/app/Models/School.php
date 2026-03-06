<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class School extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'address',
        'city',
        'state',
        'country',
        'phone',
        'email',
        'website',
        'logo',
        'status',
        'establishment_date',
        'registration_number',
        'principal_name',
    ];

    protected $casts = [
        'establishment_date' => 'date',
    ];

    // Relationships
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function teachers(): HasMany
    {
        return $this->hasMany(Teacher::class);
    }

    public function classes(): HasMany
    {
        return $this->hasMany(Classes::class);
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class);
    }

    public function academicSessions(): HasMany
    {
        return $this->hasMany(AcademicSession::class);
    }

    public function currentAcademicSession(): HasOne
    {
        return $this->hasOne(AcademicSession::class)->where('is_current', true);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function notices(): HasMany
    {
        return $this->hasMany(Notice::class);
    }

    public function settings(): HasMany
    {
        return $this->hasMany(SchoolSetting::class);
    }

    // Accessors
    public function getTotalStudentsAttribute(): int
    {
        return $this->students()->count();
    }

    public function getTotalTeachersAttribute(): int
    {
        return $this->teachers()->count();
    }

    public function getTotalClassesAttribute(): int
    {
        return $this->classes()->count();
    }

    public function getActiveStudentsAttribute(): int
    {
        return $this->students()->whereHas('user', function ($query) {
            $query->where('status', 'active');
        })->count();
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeSearch($query, $search)
    {
        return $query->where('name', 'like', "%{$search}%")
            ->orWhere('code', 'like', "%{$search}%")
            ->orWhere('email', 'like', "%{$search}%");
    }
}