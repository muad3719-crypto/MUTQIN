<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TajweedEvaluation extends Model
{
    protected $fillable = [
        'student_id',
        'teacher_id',
        'date',
        'makharij_score',
        'sifat_score',
        'madd_score',
        'waqf_score',
        'total_score',
        'notes',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    // احتساب المجموع تلقائياً قبل الحفظ
    protected static function boot()
    {
        parent::boot();
        static::saving(function ($model) {
            $model->total_score = $model->makharij_score
                + $model->sifat_score
                + $model->madd_score
                + $model->waqf_score;
        });
    }
}
