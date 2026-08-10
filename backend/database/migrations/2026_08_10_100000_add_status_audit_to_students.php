<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تدقيق خفيف لحالة الطالب: مَن غيّرها ومتى.
 * العمود is_active نفسه موجود منذ إنشاء الجدول — الناقص كان أثره الإداري فقط.
 * نفس نمط attendances.corrected_by و users.status_changed_by.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('status_changed_by')->nullable()->after('is_active')
                  ->constrained('users')->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable()->after('status_changed_by');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('status_changed_by');
            $table->dropColumn('status_changed_at');
        });
    }
};
