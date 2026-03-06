<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExamResult extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'exam_results';

    protected $fillable = [
        'exam_id',
        'student_id',
        'marks_obtained',
        'percentage',
        'grade',
        'grade_point',
        'is_pass',
        'attendance_status',
        'remarks',
        'is_published',
        'published_at',
        'published_by',
        'recorded_by',
        'recorded_at'
    ];

    protected $casts = [
        'marks_obtained' => 'decimal:2',
        'percentage' => 'decimal:2',
        'grade_point' => 'decimal:2',
        'is_pass' => 'boolean',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
        'recorded_at' => 'datetime'
    ];

    protected $with = ['student', 'exam'];

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function publishedBy()
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}