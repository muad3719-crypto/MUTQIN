<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * الرقم الوطني للطالب — معرّف رسمي لتقارير وزارة الأوقاف، ويُستخدم أيضاً
     * كرقم تسجيل البصمة. اختياري الآن (nullable) لكنه فريد عند وجوده.
     * (MySQL يسمح بعدّة قيم NULL ضمن الفهرس الفريد، فلا يمنع تعدّد الطلاب بلا رقم.)
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('national_id', 12)->nullable()->unique()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique(['national_id']);
            $table->dropColumn('national_id');
        });
    }
};
