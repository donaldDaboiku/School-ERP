<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamTimetable extends Model
{
    use HasFactory;

    protected $table = 'exam_timetables';

    protected $fillable = [
        'exam_id',
        'date',
        'start_time',
        'end_time',
        'venue',
        'room_number'
    ];

    protected $casts = [
        'date' => 'date',
        'start_time' => 'datetime',
        'end_time' => 'datetime'
    ];

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }
}