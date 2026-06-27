<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * طلب طالب (إضافة جديد / نقل موجود) ينتظر موافقة الأدمن.
 * لا يُحدث أي تغيير على جدول students إلا داخل مسار الموافقة (StudentRequestController@approve).
 */
class StudentRequest extends Model
{
    protected $fillable = [
        'type',
        'status',
        'requested_by',
        'target_center_id',
        'target_teacher_id',
        'student_id',
        'national_id',
        'student_name',
        'age',
        'phone',
        'guardian_name',
        'guardian_phone',
        'guardian_email',
        'from_center_id',
        'from_teacher_id',
        'admin_note',
    ];

    // المحفّظ مقدّم الطلب
    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    // مركز الوجهة
    public function targetCenter()
    {
        return $this->belongsTo(Center::class, 'target_center_id');
    }

    // محفّظ الوجهة (المستلِم)
    public function targetTeacher()
    {
        return $this->belongsTo(User::class, 'target_teacher_id');
    }

    // الطالب الموجود (طلب النقل)
    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    // مركز المصدر (طلب النقل)
    public function fromCenter()
    {
        return $this->belongsTo(Center::class, 'from_center_id');
    }

    // محفّظ المصدر (طلب النقل)
    public function fromTeacher()
    {
        return $this->belongsTo(User::class, 'from_teacher_id');
    }
}
