<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تفعيل/تعطيل حساب المستخدم — بديل الحذف (الحذف يفقد التاريخ).
 * نفس اصطلاح is_active القائم في centers وstudents (boolean، افتراضي نشط).
 * الحساب غير النشط يُمنع من تسجيل الدخول، وتبقى كل ارتباطاته التاريخية سليمة.
 * عمودا التدقيق على نمط corrected_by/corrected_at في الحضور: مَن غيّر الحالة ومتى.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('type');
            $table->foreignId('status_changed_by')->nullable()->after('is_active')
                  ->constrained('users')->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable()->after('status_changed_by');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('status_changed_by');
            $table->dropColumn(['is_active', 'status_changed_at']);
        });
    }
};
