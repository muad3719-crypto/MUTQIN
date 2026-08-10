<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * كود عرض المركز (C1, C2..) — نفس آلية code_sequences المعتمدة للطلاب
 * والمحفّظين ومدراء المراكز: عدّاد عام على مستوى النظام، حجز ذرّي عند
 * الإنشاء، ولا تُعبَّأ الفجوات (الكود لا يُعاد استخدامه بعد الحذف).
 * يشمل تعبئة المراكز القائمة مرتّبةً حسب id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('centers', function (Blueprint $table) {
            $table->string('display_code', 20)->nullable()->unique()->after('name');
        });

        // صف العدّاد (idempotent — لا يُكرَّر لو أُعيد التشغيل)
        if (!DB::table('code_sequences')->where('name', 'center')->exists()) {
            DB::table('code_sequences')->insert(['name' => 'center', 'value' => 0]);
        }

        // تعبئة القائمين مرتّبين حسب id، ثم مزامنة العدّاد على آخر رقم مصروف
        DB::transaction(function () {
            $max = (int) DB::selectOne(
                "SELECT COALESCE(MAX(CAST(SUBSTRING(display_code, 2) AS UNSIGNED)), 0) AS m
                 FROM centers WHERE display_code LIKE 'C%'"
            )->m;

            foreach (DB::table('centers')->whereNull('display_code')->orderBy('id')->pluck('id') as $id) {
                DB::table('centers')->where('id', $id)->update(['display_code' => 'C' . (++$max)]);
            }

            DB::update('UPDATE code_sequences SET value = GREATEST(value, ?) WHERE name = ?', [$max, 'center']);
        });
    }

    public function down(): void
    {
        Schema::table('centers', function (Blueprint $table) {
            $table->dropUnique(['display_code']);
            $table->dropColumn('display_code');
        });
        DB::table('code_sequences')->where('name', 'center')->delete();
    }
};
