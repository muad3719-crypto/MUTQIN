<?php

namespace App\Support;

/**
 * حساب النسبة المئوية الآمن (مصدر واحد) — يتجنّب القسمة على صفر.
 * مستخدَم في ملخّصات الحضور (StudentController) وفي تقارير ReportService.
 */
class Percentage
{
    /** نسبة $part من $total كعدد صحيح 0..100 (0 إن كان $total صفراً). */
    public static function of(int $part, int $total): int
    {
        return $total > 0 ? (int) round($part / $total * 100) : 0;
    }
}
