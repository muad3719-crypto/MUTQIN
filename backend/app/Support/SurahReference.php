<?php

namespace App\Support;

use App\Models\Athman;

/**
 * مرجع السور الثابت (114 سورة) لحساب تقدّم الحفظ بدلالة السور المكتملة.
 *
 * - الترتيب هنا ترتيب المصحف (1=الفاتحة ... 114=الناس).
 * - juz = الجزء الذي «تبدأ» فيه السورة (التقسيم القياسي: جزء عمّ = 78..114، تبارك = 67..77).
 * - تسلسل الحفظ «عكسي»: الناس (114) هي البداية نزولاً نحو الفاتحة (1) هي النهاية،
 *   فكلما صغر رقم المصحف للسورة المسجّلة دلّ على تقدّمٍ أعمق.
 *
 * قاعدة الإتمام: لا يُحتسب الجزء مكتملاً إلا بإتمام «كل» سوره.
 */
class SurahReference
{
    /** [اسم السورة => رقم الجزء الذي تبدأ فيه] بترتيب المصحف. */
    public const SURAHS = [
        'الفاتحة' => 1,  'البقرة' => 1,  'آل عمران' => 3, 'النساء' => 4,  'المائدة' => 6,
        'الأنعام' => 7,  'الأعراف' => 8, 'الأنفال' => 9,  'التوبة' => 10, 'يونس' => 11,
        'هود' => 11,     'يوسف' => 12,   'الرعد' => 13,   'إبراهيم' => 13,'الحجر' => 14,
        'النحل' => 14,   'الإسراء' => 15,'الكهف' => 15,   'مريم' => 16,   'طه' => 16,
        'الأنبياء' => 17,'الحج' => 17,   'المؤمنون' => 18,'النور' => 18,  'الفرقان' => 18,
        'الشعراء' => 19, 'النمل' => 19,  'القصص' => 20,   'العنكبوت' => 20,'الروم' => 21,
        'لقمان' => 21,   'السجدة' => 21, 'الأحزاب' => 21, 'سبأ' => 22,    'فاطر' => 22,
        'يس' => 22,      'الصافات' => 23,'ص' => 23,       'الزمر' => 23,  'غافر' => 24,
        'فصلت' => 24,    'الشورى' => 25, 'الزخرف' => 25,  'الدخان' => 25, 'الجاثية' => 25,
        'الأحقاف' => 26, 'محمد' => 26,   'الفتح' => 26,   'الحجرات' => 26,'ق' => 26,
        'الذاريات' => 26,'الطور' => 27,  'النجم' => 27,   'القمر' => 27,  'الرحمن' => 27,
        'الواقعة' => 27, 'الحديد' => 27, 'المجادلة' => 28,'الحشر' => 28,  'الممتحنة' => 28,
        'الصف' => 28,    'الجمعة' => 28, 'المنافقون' => 28,'التغابن' => 28,'الطلاق' => 28,
        'التحريم' => 28, 'الملك' => 29,  'القلم' => 29,   'الحاقة' => 29, 'المعارج' => 29,
        'نوح' => 29,     'الجن' => 29,   'المزمل' => 29,  'المدثر' => 29, 'القيامة' => 29,
        'الإنسان' => 29, 'المرسلات' => 29,'النبأ' => 30,  'النازعات' => 30,'عبس' => 30,
        'التكوير' => 30, 'الانفطار' => 30,'المطففين' => 30,'الانشقاق' => 30,'البروج' => 30,
        'الطارق' => 30,  'الأعلى' => 30, 'الغاشية' => 30, 'الفجر' => 30,  'البلد' => 30,
        'الشمس' => 30,   'الليل' => 30,  'الضحى' => 30,   'الشرح' => 30,  'التين' => 30,
        'العلق' => 30,   'القدر' => 30,  'البينة' => 30,  'الزلزلة' => 30,'العاديات' => 30,
        'القارعة' => 30, 'التكاثر' => 30,'العصر' => 30,   'الهمزة' => 30, 'الفيل' => 30,
        'قريش' => 30,    'الماعون' => 30,'الكوثر' => 30,  'الكافرون' => 30,'النصر' => 30,
        'المسد' => 30,   'الإخلاص' => 30,'الفلق' => 30,   'الناس' => 30,
    ];

    /** خرائط مبنية مرة واحدة (cache ثابت). */
    private static ?array $normToJuz = null;   // اسم مطبّع => جزء
    private static ?array $normToOrder = null; // اسم مطبّع => ترتيب المصحف (1..114)
    private static ?array $normToName = null;  // اسم مطبّع => الاسم الأصلي
    private static ?array $juzToNorms = null;   // جزء => [أسماء مطبّعة]

    private static function build(): void
    {
        if (self::$normToJuz !== null) {
            return;
        }
        self::$normToJuz = self::$normToOrder = self::$normToName = self::$juzToNorms = [];
        $order = 0;
        foreach (self::SURAHS as $name => $juz) {
            $order++;
            $norm = Athman::normalize($name);
            self::$normToJuz[$norm]   = $juz;
            self::$normToOrder[$norm] = $order;
            self::$normToName[$norm]  = $name;
            self::$juzToNorms[$juz][] = $norm;
        }
    }

    /**
     * يحسب تقدّم طالب من مجموعة أسماء السور المسجّلة له.
     *
     * يُرجع:
     *  - reached_juz:        الجزء الذي تقع فيه آخر سورة في تسلسل الحفظ (أعمق تقدّم) — أو null.
     *  - reached_juz_done:   هل أُتمّ ذلك الجزء بالكامل (كل سوره)؟
     *  - last_surah:         اسم آخر سورة وصل إليها (الأعمق) — أو null.
     *  - completed_count:    عدد الأجزاء المكتملة فعلاً المتّصلة من 30 نزولاً.
     *  - completed_down_to:  أصغر جزء أُكمل ضمن السلسلة المتّصلة من 30 (أو null).
     */
    public static function progress(array $surahNames): array
    {
        self::build();

        $empty = [
            'reached_juz' => null, 'reached_juz_done' => false, 'last_surah' => null,
            'completed_count' => 0, 'completed_down_to' => null,
        ];

        // مجموعة السور المعروفة (مطبّعة) التي سجّلها الطالب
        $set = [];
        foreach ($surahNames as $s) {
            $n = Athman::normalize((string) $s);
            if (isset(self::$normToJuz[$n])) {
                $set[$n] = true;
            }
        }
        if (!$set) {
            return $empty;
        }

        // آخر سورة (الأعمق) = أصغر ترتيب مصحف ضمن المسجّل
        $minOrder = PHP_INT_MAX;
        $lastNorm = null;
        foreach ($set as $n => $_) {
            if (self::$normToOrder[$n] < $minOrder) {
                $minOrder = self::$normToOrder[$n];
                $lastNorm = $n;
            }
        }
        $reachedJuz = self::$normToJuz[$lastNorm];

        // الأجزاء المكتملة المتّصلة من 30 نزولاً
        $downTo = null;
        for ($j = 30; $j >= 1; $j--) {
            if (self::isJuzComplete($j, $set)) {
                $downTo = $j;
            } else {
                break;
            }
        }
        $completedCount = $downTo !== null ? (30 - $downTo + 1) : 0;

        return [
            'reached_juz'       => $reachedJuz,
            'reached_juz_done'  => self::isJuzComplete($reachedJuz, $set),
            'last_surah'        => self::$normToName[$lastNorm],
            'completed_count'   => $completedCount,
            'completed_down_to' => $downTo,
        ];
    }

    /** أسماء سور الجزء $juz (بالصيغة الأصلية، بترتيب المصحف) — لفلترة سجلات الحفظ. */
    public static function namesOfJuz(int $juz): array
    {
        $names = [];
        foreach (self::SURAHS as $name => $j) {
            if ($j === $juz) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /** هل كل سور الجزء $juz موجودة في المجموعة المطبّعة $set؟ */
    private static function isJuzComplete(int $juz, array $set): bool
    {
        self::build();
        $norms = self::$juzToNorms[$juz] ?? [];
        if (!$norms) {
            return false;
        }
        foreach ($norms as $n) {
            if (empty($set[$n])) {
                return false;
            }
        }
        return true;
    }
}
