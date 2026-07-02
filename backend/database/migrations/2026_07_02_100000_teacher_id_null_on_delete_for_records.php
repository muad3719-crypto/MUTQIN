<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سلامة البيانات (D1): حذف المحفّظ كان يمحو حضوره/حفظه/اختباراته (cascadeOnDelete)،
 * فتُفقد سجلّات طلابٍ ما زالوا مسجّلين — فقدان صامت غير قابل للاسترجاع.
 * نحوّل teacher_id في الجداول الثلاثة إلى nullOnDelete: يبقى السجل ويصبح المحفّظ null
 * (يُعرض «محفّظ سابق»). البيانات الموجودة تبقى كما هي.
 */
return new class extends Migration
{
    private array $tables = ['attendances', 'memorizations', 'weekly_tests'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['teacher_id']);
                $t->unsignedBigInteger('teacher_id')->nullable()->change();
                $t->foreign('teacher_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['teacher_id']);
                $t->foreign('teacher_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }
};
