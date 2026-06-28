<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * إضافة الجنسية ورقم الهوية للطلاب وأولياء الأمور.
 *  - students: nationality_type + nationality_name، مع تعميم national_id ليتّسع للأجانب.
 *  - users:    nationality_type + nationality_name + id_number (المعرّف الموحّد لولي الأمر، فريد).
 * الصفوف الحالية تُعتبر libyan افتراضياً (هجرة آمنة).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->enum('nationality_type', ['libyan', 'foreigner'])->default('libyan')->after('national_id');
            $table->string('nationality_name', 100)->nullable()->after('nationality_type'); // اسم الجنسية للأجنبي
        });
        // توسعة الرقم الوطني ليتّسع لجواز/إقامة الأجنبي (يبقى nullable + unique)
        DB::statement('ALTER TABLE students MODIFY national_id VARCHAR(32) NULL');

        Schema::table('users', function (Blueprint $table) {
            $table->enum('nationality_type', ['libyan', 'foreigner'])->default('libyan')->after('type');
            $table->string('nationality_name', 100)->nullable()->after('nationality_type');
            $table->string('id_number', 32)->nullable()->after('nationality_name'); // المعرّف الموحّد لولي الأمر
            $table->unique('id_number'); // فريد فعلياً لمن يملك هوية (NULL متعدّد مسموح)
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['nationality_type', 'nationality_name']);
        });
        DB::statement('ALTER TABLE students MODIFY national_id VARCHAR(12) NULL');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['id_number']);
            $table->dropColumn(['nationality_type', 'nationality_name', 'id_number']);
        });
    }
};
