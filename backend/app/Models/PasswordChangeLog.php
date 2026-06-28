<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordChangeLog extends Model
{
    protected $fillable = [
        'user_id',
        'changed_at',
        'method',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
