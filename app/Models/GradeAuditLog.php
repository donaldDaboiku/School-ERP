<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GradeAuditLog extends Model
{
    use HasFactory;

    protected $table = 'grade_audit_logs';

    protected $fillable = [
        'grade_id',
        'action',
        'performed_by',
        'data',
        'ip_address',
        'user_agent'
    ];

    protected $casts = [
        'data' => 'json'
    ];

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}