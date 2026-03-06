<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParentCommunicationLog extends Model
{
    protected $fillable = [
        'parent_id',
        'type',
        'title',
        'message',
        'sent_via',
        'sent_by',
        'status',
        'read_at'
    ];

    protected $casts = [
        'read_at' => 'datetime'
    ];

    public function parent()
    {
        return $this->belongsTo(Parents ::class, 'parent_id');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}