<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * تعبئة أكواد العرض للسجلات الموجودة (backfill):
 *   الطلاب S..، المحفّظون T..، مدراء المراكز CA.. — مرتّبين حسب id.
 *
 * يبدأ الترقيم بعد أكبر رقم مستخدم فعلاً في الجدول (لا بعد قيمة العدّاد) —
 * فأرقام العدّاد التي حُجزت ثم حُذف أصحابها قبل الـ backfill لا تترك فجوات،
 * وفي قاعدة نظيفة يبدأ من 1. في النهاية يُضبط العدّاد على آخر رقم صُرف
 * حتى تكمل الإنشاءات الجديدة من بعده (لا إعادة استخدام إلى الأمام أبداً).
 */
return new class extends Migration
{
    public function up(): void
    {
        // [النوع => [الجدول، البادئة، شرط الدور]]
        $targets = [
            'student'        => ['students', 'S',  null],
            'teacher'        => ['users',    'T',  'teacher'],
            'center_manager' => ['users',    'CA', 'center_manager'],
        ];

        foreach ($targets as $type => [$table, $prefix, $role]) {
            DB::transaction(function () use ($type, $table, $prefix, $role) {
                // أكبر رقم مستخدم فعلاً بهذه البادئة (0 إن لم يوجد)
                $roleSql = $role ? ' AND role = ?' : '';
                $params  = [strlen($prefix) + 1, $prefix . '%'];
                if ($role) $params[] = $role;
                $max = (int) DB::selectOne(
                    "SELECT COALESCE(MAX(CAST(SUBSTRING(display_code, ?) AS UNSIGNED)), 0) AS m
                     FROM {$table} WHERE display_code LIKE ?{$roleSql}",
                    $params
                )->m;

                $query = DB::table($table)->whereNull('display_code')->orderBy('id');
                if ($role) $query->where('role', $role);

                foreach ($query->pluck('id') as $id) {
                    DB::table($table)->where('id', $id)->update(['display_code' => $prefix . (++$max)]);
                }

                // مزامنة العدّاد إلى آخر رقم مصروف (أخذ الأكبر تحسّباً)
                DB::update(
                    'UPDATE code_sequences SET value = GREATEST(value, ?) WHERE name = ?',
                    [$max, $type]
                );
            });
        }
    }

    public function down(): void
    {
        // التراجع: تفريغ الأكواد فقط (العدّادات تبقى — لا نعيد استخدام الأرقام)
        DB::table('students')->update(['display_code' => null]);
        DB::table('users')->update(['display_code' => null]);
    }
};
