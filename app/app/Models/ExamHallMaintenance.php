<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamHallMaintenance extends Model
{
    use HasFactory;

    protected $fillable = [
        'hall_id',
        'reason',
        'start_date',
        'expected_completion',
        'completion_date',
        'reported_by',
        'completed_by',
        'status',
        'notes'
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'expected_completion' => 'datetime',
        'completion_date' => 'datetime'
    ];

    public function hall()
    {
        return $this->belongsTo(ExamHall::class);
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function completer()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}