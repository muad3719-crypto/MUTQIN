<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeeklyTestQuestion extends Model
{
    protected $fillable = [
        'weekly_test_id',
        'student_id',
        'eighth_start',
        'result',
        'mistake',
    ]; // الحقول القابلة للتعبئة لأسئلة الاختبار الأسبوعي

    // العلاقة مع الاختبار الأسبوعي الرئيسي
    public function weeklyTest()
    {
        return $this->belongsTo(WeeklyTest::class);
    }

    // العلاقة مع الطالب المختبر
    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
