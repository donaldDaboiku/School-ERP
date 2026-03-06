<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RemarkActivityLog extends Model
{
    use HasFactory;

    protected $table = 'remark_activity_logs';

    protected $fillable = [
        'remark_id',
        'action',
        'performed_by',
        'data',
        'ip_address',
        'user_agent'
    ];

    protected $casts = [
        'data' => 'json'
    ];

    protected $with = ['performer'];

    public function remark()
    {
        return $this->belongsTo(Remark::class);
    }

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}