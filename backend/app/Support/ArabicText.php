<?php

namespace App\Support;

/**
 * المصدر الموحّد لتطبيع/تنظيف النص العربي.
 * يجمع ما كان مكرّراً بين Athman::normalize و StudentController::stripTashkeel،
 * مع الحفاظ على سلوك كل دالة كما هو (لا يتغيّر start_text_norm المخزّن).
 */
class ArabicText
{
    /**
     * إزالة التشكيل وعلامات الضبط فقط (دون توحيد صور الحروف) —
     * مناسب لمطابقة الأسماء المخزّنة بلا تشكيل.
     */
    public static function stripTashkeel(string $text): string
    {
        return preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $text);
    }

    /**
     * تطبيع كامل للبحث: إزالة التطويل والتشكيل، وتوحيد الألف والياء
     * والتاء المربوطة والهمزات، وتبسيط المسافات.
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
     * تطبيع الاستعلام: كالتطبيع العادي + إزالة أداة عطف/تعريف من البداية
     * (و/ف/ال وتركيباتها) حتى يُطابق وإن كُتب بدونها.
     */
    public static function normalizeQuery(string $q): string
    {
        $q = self::normalize($q);
        $q = preg_replace('/^(وال|فال|ال|و|ف)\s*/u', '', $q);

        return trim($q);
    }
}
