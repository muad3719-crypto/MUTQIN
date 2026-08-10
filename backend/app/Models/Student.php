<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    protected $fillable = [
        'name',
        'display_code',
        'birth_date',
        'phone',
        'national_id',
        'nationality_type',
        'nationality_name',
        'age',
        'guardian_name',
        'guardian_phone',
        'center_id',
        'teacher_id',
        'former_teacher_name',
        'parent_id',
        'enrollment_date',
        'is_active',
        'status_changed_by',
        'status_changed_at',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'enrollment_date' => 'date',
        'is_active' => 'boolean',
        'status_changed_at' => 'datetime',
    ];

    // المركز الذي ينتمي إليه
    // كود العرض (S1..) يُحجز تلقائياً عند الإنشاء من أي مسار
    // (لوحة الأدمن، موافقة الطلبات، البذر) — العدّاد لا يعيد الأرقام
    protected static function booted(): void
    {
        static::creating(function (Student $student) {
            if (empty($student->display_code)) {
                $student->display_code = \App\Support\DisplayCode::next('student');
            }
        });
    }

    public function center()
    {
        return $this->belongsTo(Center::class);
    }

    // المعلم المسؤول عنه
    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    // ولي أمر الطالب (مستخدم بدور parent)
    public function parent()
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    // نطاق: الطلاب بلا رقم وطني (لتقارير جاهزية الأوقاف)
    public function scopeMissingNationalId($query)
    {
        return $query->whereNull('national_id');
    }

    // سجلات الحضور
    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    // سجلات الحفظ
    public function memorizations()
    {
        return $this->hasMany(Memorization::class);
    }

    // سجلات المراجعة
    public function revisions()
    {
        return $this->hasMany(Revision::class);
    }

    // تقييمات التجويد
    public function tajweedEvaluations()
    {
        return $this->hasMany(TajweedEvaluation::class);
    }

    // الاختبارات الأسبوعية
    public function weeklyTests()
    {
        return $this->hasMany(WeeklyTest::class);
    }

    // تم إزالة دالة حساب العمر لاستخدام حقل العمر الفعلي
}
