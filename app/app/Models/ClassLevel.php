<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassLevel extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'school_id',
        'name',
        'code',
        'short_name',
        'level_order',
        'category',
        'fee_amount',
        'description',
    ];

    protected $casts = [
        'fee_amount' => 'decimal:2',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function classes(): HasMany
    {
        return $this->hasMany(Classes::class);
    }
}