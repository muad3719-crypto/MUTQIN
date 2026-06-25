<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'role',
        'password',
        'center_id',
        'type',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // هل هذا المستخدم مدير؟
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    // هل هذا المستخدم معلم؟
    public function isTeacher(): bool
    {
        return $this->role === 'teacher';
    }

    // هل هذا المستخدم ولي أمر؟
    public function isParent(): bool
    {
        return $this->role === 'parent';
    }

    // الطلاب المرتبطون بهذا المعلم
    public function students()
    {
        return $this->hasMany(Student::class, 'teacher_id');
    }

    // أبناء ولي الأمر (الطلاب المرتبطون به عبر parent_id)
    public function children()
    {
        return $this->hasMany(Student::class, 'parent_id');
    }

    // المركز التابع له
    public function center()
    {
        return $this->belongsTo(Center::class);
    }

    // الاختبارات التي أجراها هذا المعلم
    public function weeklyTests()
    {
        return $this->hasMany(WeeklyTest::class, 'teacher_id');
    }
}
