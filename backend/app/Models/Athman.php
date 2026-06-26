<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Athman extends Model
{
    protected $table = 'athman';

    protected $fillable = [
        'hizb',
        'thumn_in_hizb',
        'global_order',
        'surah_name',
        'start_text',
        'start_text_norm',
        'page',
    ];

    protected $casts = [
        'hizb'          => 'integer',
        'thumn_in_hizb' => 'integer',
        'global_order'  => 'integer',
        'page'          => 'integer',
    ];

    /**
     * تطبيع النص العربي للبحث — يفوّض إلى المصدر الموحّد {@see \App\Support\ArabicText}.
     * يُطبَّق على القيمة المخزّنة (start_text_norm) وعلى الاستعلام معاً.
     */
    public static function normalize(?string $s): string
    {
        return \App\Support\ArabicText::normalize($s);
    }

    /**
     * تطبيع الاستعلام (تطبيع كامل + إزالة أداة و/ف/ال من البداية) — يفوّض إلى المصدر الموحّد.
     */
    public static function normalizeQuery(string $q): string
    {
        return \App\Support\ArabicText::normalizeQuery($q);
    }
}
