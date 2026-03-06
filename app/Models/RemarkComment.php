<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RemarkComment extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'remark_comments';

    protected $fillable = [
        'remark_id',
        'content',
        'created_by',
        'is_internal'
    ];

    protected $casts = [
        'is_internal' => 'boolean'
    ];

    protected $with = ['author'];

    protected $appends = ['author_name'];

    public function remark()
    {
        return $this->belongsTo(Remark::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getAuthorNameAttribute()
    {
        return $this->author ? $this->author->name : 'Unknown';
    }
}