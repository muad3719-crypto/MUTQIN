<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مراسلة ولي الأمر ↔ المحفّظ — محادثة واحدة لكل زوج مقيّدة بابن محدّد:
 * المحادثة مفتاحها student_id (الطالب يحمل parent_id وteacher_id فهو
 * يحدّد الطرفين معاً). لا مرفقات ولا حذف رسائل — نص فقط مع أثر قراءة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->string('sender_role', 16); // 'parent' أو 'teacher' — دور المرسل لحظة الإرسال
            $table->text('body');
            $table->timestamp('read_at')->nullable(); // متى قرأها الطرف الآخر
            $table->timestamps();

            $table->index(['student_id', 'id']);                     // جلب الخيط
            $table->index(['student_id', 'sender_role', 'read_at']); // عدّ غير المقروء
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
