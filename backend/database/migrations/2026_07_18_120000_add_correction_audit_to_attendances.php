<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تدقيق خفيف لتصحيح الحضور: مَن صحّح السجل ومتى.
 * عمودان فقط (nullable) — يُملآن عند التصحيح اليدوي من صفحة مدير المركز،
 * ويبقيان NULL لسجلات البصمة/التسجيل الأصلي. الحالة السابقة لا تُحفَظ
 * (قرار معتمد: تدقيق خفيف يكفي للمساءلة عند نزاع، بلا تاريخ كامل).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('corrected_by')->nullable()->after('imported_at')
                  ->constrained('users')->nullOnDelete();
            $table->timestamp('corrected_at')->nullable()->after('corrected_by');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('corrected_by');
            $table->dropColumn('corrected_at');
        });
    }
};
