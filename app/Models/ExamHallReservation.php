<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamHallReservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'hall_id',
        'start_date',
        'end_date',
        'purpose',
        'reserved_by',
        'status',
        'notes'
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime'
    ];

    public function hall()
    {
        return $this->belongsTo(ExamHall::class);
    }

    public function reservedBy()
    {
        return $this->belongsTo(User::class, 'reserved_by');
    }
}