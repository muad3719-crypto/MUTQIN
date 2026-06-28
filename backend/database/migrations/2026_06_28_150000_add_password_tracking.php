<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تتبّع تغييرات كلمة المرور:
 *  - عدّاد وتاريخ آخر تغيير على users.
 *  - سجل كامل (صف لكل تغيير) مع طريقة التغيير (otp/self/admin).
 * لا تُخزَّن كلمات المرور نفسها (مجزّأة أصلاً).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('password_changed_count')->default(0)->after('password');
            $table->timestamp('password_last_changed_at')->nullable()->after('password_changed_count');
        });

        Schema::create('password_change_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('changed_at');
            $table->enum('method', ['otp', 'self', 'admin']); // كيف تغيّرت كلمة المرور
            $table->timestamps();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_change_logs');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['password_changed_count', 'password_last_changed_at']);
        });
    }
};
