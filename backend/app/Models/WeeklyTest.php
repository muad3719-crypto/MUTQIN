<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeeklyTest extends Model
{
    protected $fillable = [
        'student_id',
        'teacher_id',
        'exam_date',
        'result',
        'notes',
    ];

    protected $casts = [
        'exam_date' => 'date',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    // العلاقة مع الأثمان (أجزاء الاختبار) الأسبوعية المرتبطة بالاختبار
    public function questions()
    {
        return $this->hasMany(WeeklyTestQuestion::class);
    }
}
