<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * رسالة في محادثة (ولي أمر ↔ محفّظ) مقيّدة بطالب محدّد — الخيط مفتاحه
 * student_id. لا حذف للرسائل (قرار «لا حذف» العام) ولا مرفقات.
 */
class Message extends Model
{
    protected $fillable = [
        'student_id',
        'sender_id',
        'sender_role',
        'body',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
