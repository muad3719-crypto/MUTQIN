<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * شفرات استعادة كلمة المرور (OTP) — مجزّأة، مؤقّتة، محدودة المحاولات.
 * لا تُخزَّن الشفرة كنص صريح أبداً (otp_hash فقط).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_resets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('otp_hash');                       // Hash::make(الشفرة)
            $table->timestamp('expires_at');                  // وقت الانتهاء (مثلاً +10 دقائق)
            $table->unsignedTinyInteger('attempts')->default(0); // عدّاد محاولات التحقّق الفاشلة
            $table->timestamps();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_resets');
    }
};
