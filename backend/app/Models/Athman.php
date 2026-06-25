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
     * تطبيع النص العربي للبحث: إزالة التشكيل والتطويل، وتوحيد الألف
     * والياء والهاء والهمزات — يُطبَّق على القيمة المخزّنة وعلى الاستعلام معاً.
     */
    public static function normalize(?string $s): string
    {
        $s = (string) $s;
        // إزالة التطويل (ـ) والتشكيل (الفتحة..السكون + الألف الخنجرية)
        $s = preg_replace('/[\x{0640}\x{064B}-\x{0652}\x{0670}]/u', '', $s);
        // توحيد صور الألف: أ إ آ ٱ → ا
        $s = preg_replace('/[\x{0623}\x{0625}\x{0622}\x{0671}]/u', 'ا', $s);
        // توحيد: الألف المقصورة→ياء، التاء المربوطة→هاء، الهمزات
        $s = str_replace(['ى', 'ة', 'ؤ', 'ئ', 'ء'], ['ي', 'ه', 'و', 'ي', ''], $s);
        // تبسيط المسافات
        $s = preg_replace('/\s+/u', ' ', trim($s));

        return $s;
    }

    /**
     * تطبيع الاستعلام: كالتطبيع العادي + إزالة أداة في البداية (و/ف/ال)
     * حتى يجد المعلّم الثمن وإن كتب بدون أداة العطف أو التعريف.
     */
    public static function normalizeQuery(string $q): string
    {
        $q = self::normalize($q);
        // إزالة أداة عطف/تعريف من البداية فقط: و / ف / ال (وتركيباتها)
        $q = preg_replace('/^(وال|فال|ال|و|ف)\s*/u', '', $q);

        return trim($q);
    }
}
