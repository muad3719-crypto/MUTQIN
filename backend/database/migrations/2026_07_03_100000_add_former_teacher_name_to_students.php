<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تمييز «محفّظ سابق» عن «بدون معلم»: teacher_id يصير null في الحالتين،
 * فنحفظ اسم المحفّظ لحظة حذفه ليبقى التاريخ مقروءاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('former_teacher_name')->nullable()->after('teacher_id');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('former_teacher_name');
        });
    }
};
