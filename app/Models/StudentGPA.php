<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentGPA extends Model
{
    use HasFactory;

    protected $table = 'student_gpas';

    protected $fillable = [
        'student_id',
        'academic_year',
        'term',
        'gpa',
        'total_credits',
        'rank_in_class',
        'remarks'
    ];

    protected $casts = [
        'gpa' => 'decimal:2',
        'total_credits' => 'integer',
        'rank_in_class' => 'integer'
    ];

    protected $with = ['student'];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}