<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * كود العرض (Display Code) — معرّف قصير فريد على مستوى النظام كله:
 *   طالب S1..، محفّظ T1..، مدير مركز CA1.. (المدير العام وولي الأمر: بلا كود).
 *
 * جدول code_sequences: عدّاد مستقل لكل نوع (بديل AUTO_INCREMENT الثاني
 * المستحيل في MySQL). الحجز بأمر UPDATE ذرّي واحد:
 *   UPDATE code_sequences SET value = LAST_INSERT_ID(value + 1) WHERE name = ?
 * قفل الصف يمنع السباق، والعدّاد لا يعود للخلف أبداً → لا إعادة استخدام بعد الحذف.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('code_sequences', function (Blueprint $table) {
            $table->string('name', 40)->primary();      // student | teacher | center_manager
            $table->unsignedBigInteger('value')->default(0);
        });

        // صفوف العدّادات الثلاثة (تُنشأ هنا لا في seeder — الاختبارات تعتمد عليها)
        DB::table('code_sequences')->insert([
            ['name' => 'student',        'value' => 0],
            ['name' => 'teacher',        'value' => 0],
            ['name' => 'center_manager', 'value' => 0],
        ]);

        Schema::table('students', function (Blueprint $table) {
            $table->string('display_code', 20)->nullable()->unique()->after('name');
        });

        Schema::table('users', function (Blueprint $table) {
            // nullable: الأدمن وأولياء الأمور بلا كود (MySQL يسمح بعدة NULL مع unique)
            $table->string('display_code', 20)->nullable()->unique()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique(['display_code']);
            $table->dropColumn('display_code');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['display_code']);
            $table->dropColumn('display_code');
        });
        Schema::dropIfExists('code_sequences');
    }
};
