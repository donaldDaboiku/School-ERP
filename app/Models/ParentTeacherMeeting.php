<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParentTeacherMeeting extends Model
{
    protected $fillable = [
        'parent_id',
        'teacher_id',
        'student_id',
        'scheduled_at',
        'duration',
        'meeting_type',
        'agenda',
        'notes',
        'status',
        'outcome'
    ];

    protected $casts = [
        'scheduled_at' => 'datetime'
    ];

    public function parent()
    {
        return $this->belongsTo(Parents::class, 'parent_id');
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }
}