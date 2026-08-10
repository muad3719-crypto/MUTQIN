<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * كود العرض القصير — فريد على مستوى النظام كله، عدّاد مستقل لكل نوع:
 *   student → S1..  |  teacher → T1..  |  center_manager → CA1..
 * (مدير النظام وولي الأمر: لا كود عرض.)
 *
 * الحجز ذرّي عبر UPDATE واحد على جدول code_sequences:
 * قفل الصف يمنع تكرار الرقم تحت التزامن، والعدّاد لا ينقص أبداً
 * فلا يُعاد استخدام كود حتى لو حُذف صاحبه. يُستدعى داخل transaction
 * عند الإنشاء ليُحرَّر الرقم مع نجاح العملية كلها أو تُلغى معاً.
 */
class DisplayCode
{
    public const PREFIXES = [
        'student'        => 'S',
        'teacher'        => 'T',
        'center_manager' => 'CA',
        'center'         => 'C',   // المراكز (C1, C2..) — كيان لا مستخدم
    ];

    /**
     * معاينة الكود التالي دون حجزه — قراءة فقط (SELECT بلا UPDATE)، فلا
     * يزيد العدّاد ولا يُهدر رقم لو أُلغيت العملية. الرقم «تقديري»: قد
     * يسبقه غيره فيتغيّر الفعلي عند الحفظ (next() هو الذي يحجز ذرّياً).
     */
    public static function preview(string $type): string
    {
        $prefix = self::PREFIXES[$type] ?? null;
        if ($prefix === null) {
            throw new \InvalidArgumentException("نوع لا يحمل كود عرض: {$type}");
        }

        $row = DB::selectOne('SELECT value FROM code_sequences WHERE name = ?', [$type]);
        if ($row === null) {
            throw new \RuntimeException("عدّاد كود العرض غير مهيأ: {$type}");
        }

        return $prefix . ((int) $row->value + 1);
    }

    /** يحجز الرقم التالي للنوع ويعيد الكود جاهزاً (مثل T13). */
    public static function next(string $type): string
    {
        $prefix = self::PREFIXES[$type] ?? null;
        if ($prefix === null) {
            throw new \InvalidArgumentException("نوع لا يحمل كود عرض: {$type}");
        }

        $affected = DB::update(
            'UPDATE code_sequences SET value = LAST_INSERT_ID(value + 1) WHERE name = ?',
            [$type]
        );
        if ($affected === 0) {
            throw new \RuntimeException("عدّاد كود العرض غير مهيأ: {$type}");
        }

        // LAST_INSERT_ID لكل اتصال على حدة — آمن مع الطلبات المتوازية
        return $prefix . DB::selectOne('SELECT LAST_INSERT_ID() AS n')->n;
    }
}
