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
        'nationality_type',
        'nationality_name',
        'id_number',
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
            'password_last_changed_at' => 'datetime',
            'password_changed_count' => 'integer',
        ];
    }

    // سجل تغييرات كلمة المرور (otp/self/admin)
    public function passwordChangeLogs()
    {
        return $this->hasMany(PasswordChangeLog::class);
    }

    /**
     * يسجّل تغيير كلمة المرور: يزيد العدّاد، يحدّث تاريخ آخر تغيير، ويضيف صفاً للسجل.
     * يُستدعى بعد كل تغيير ناجح (OTP / المستخدم نفسه / الأدمن).
     */
    public function recordPasswordChange(string $method = 'self'): void
    {
        $this->increment('password_changed_count');
        $this->forceFill(['password_last_changed_at' => now()])->save();
        $this->passwordChangeLogs()->create([
            'changed_at' => now(),
            'method'     => $method,
        ]);

        // أمان (S1): إبطال كل توكنات المستخدم عند تغيير كلمة المرور — حتى لا يبقى
        // توكن مسروق صالحاً بعد التصفير. المستخدم يسجّل الدخول من جديد بالكلمة الجديدة.
        $this->tokens()->delete();
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

    // هل هذا المستخدم مشرف مركز؟ («مشرف المركز» — يدير عمليات مركزه فقط)
    public function isCenterSupervisor(): bool
    {
        return $this->role === 'center_supervisor';
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
