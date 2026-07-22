<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    protected $fillable = [
        'student_id',
        'teacher_id',
        'center_id',
        'date',
        'time',
        'status',
        'notes',
        'imported_at',
        'corrected_by',
        'corrected_at',
    ];

    protected $casts = [
        'date' => 'date',
        'corrected_at' => 'datetime',
        'imported_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    // ترجمة الحالة إلى عربي
    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'present' => 'حاضر',
            'absent'  => 'غائب',
            'late'    => 'متأخر',
            default   => $this->status,
        };
    }

    // لون الحالة
    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'present' => 'success',
            'absent'  => 'danger',
            'late'    => 'warning',
            default   => 'secondary',
        };
    }
}
