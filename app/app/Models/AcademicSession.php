<?php

namespace App\Models;

use App\Models\TermSemester;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicSession extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'school_id',
        'name',
        'code',
        'start_date',
        'end_date',
        'is_current',
        'status',
        'description',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_current' => 'boolean',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function terms(): HasMany
    {
        return $this->hasMany(TermSemester::class);
    }

    public function currentTerm()
    {
        return $this->terms()->where('is_current', true)->first();
    }
}