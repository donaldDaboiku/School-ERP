<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class profile extends Model
{
    protected $fillable = [
      'user_id',
      'phone',
      'address',
      'avatar',
      'date_of_birth',
      'gender'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
