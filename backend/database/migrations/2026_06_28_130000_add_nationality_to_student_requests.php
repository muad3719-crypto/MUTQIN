<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إضافة الجنسية ورقم الهوية إلى لقطة طلب الطالب (student_requests)،
 * لتوحيد نظام الطلبات مع طلاب/أولياء الأمور (libyan/foreigner + id_number).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_requests', function (Blueprint $table) {
            $table->enum('nationality_type', ['libyan', 'foreigner'])->default('libyan')->after('national_id');
            $table->string('nationality_name', 100)->nullable()->after('nationality_type');
            $table->enum('guardian_nationality_type', ['libyan', 'foreigner'])->default('libyan')->after('guardian_email');
            $table->string('guardian_nationality_name', 100)->nullable()->after('guardian_nationality_type');
            $table->string('guardian_id_number', 32)->nullable()->after('guardian_nationality_name'); // معرّف ولي الأمر للمطابقة عند الموافقة
        });
    }

    public function down(): void
    {
        Schema::table('student_requests', function (Blueprint $table) {
            $table->dropColumn([
                'nationality_type', 'nationality_name',
                'guardian_nationality_type', 'guardian_nationality_name', 'guardian_id_number',
            ]);
        });
    }
};
