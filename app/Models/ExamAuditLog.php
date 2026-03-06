<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamAuditLog extends Model
{
    use HasFactory;

    protected $table = 'exam_audit_logs';

    protected $fillable = [
        'exam_id',
        'action',
        'performed_by',
        'data',
        'ip_address',
        'user_agent'
    ];

    protected $casts = [
        'data' => 'json'
    ];

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}