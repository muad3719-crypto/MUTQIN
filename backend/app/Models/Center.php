<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Center extends Model
{
    protected $fillable = [
        'name',
        'city',
        'address',
        'phone',
        'is_active',
    ];

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
