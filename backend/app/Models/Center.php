<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Center extends Model
{
    protected $fillable = [
        'name',
        'display_code',
        'city',
        'address',
        'phone',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // كود العرض (C1..) يُحجز ذرّياً عند الإنشاء من أي مسار — لا يُقبل من العميل
    protected static function booted(): void
    {
        static::creating(function (Center $center) {
            if (empty($center->display_code)) {
                $center->display_code = \App\Support\DisplayCode::next('center');
            }
        });
    }

    // الطلاب في هذا المركز
    public function students()
    {
        return $this->hasMany(Student::class);
    }

    // المحفظون في هذا المركز
    public function teachers()
    {
        return $this->hasMany(User::class)->where('role', 'teacher');
    }
}
