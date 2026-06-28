<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtpReset extends Model
{
    protected $table = 'otp_resets';

    protected $fillable = [
        'user_id',
        'otp_hash',
        'expires_at',
        'attempts',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'attempts'   => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
