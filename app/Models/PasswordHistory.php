<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'password',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
