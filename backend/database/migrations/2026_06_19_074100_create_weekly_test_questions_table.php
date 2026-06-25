<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('weekly_test_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('weekly_test_id')->constrained('weekly_tests')->cascadeOnDelete(); // معرف الاختبار الأسبوعي المرتبط
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete(); // معرف الطالب المختبر
            $table->string('eighth_start'); // بداية الثمن المختبر فيه - مثل: "هل جزاء الإحسان إلا الإحسان"
            $table->enum('result', ['ناجح', 'راسب'])->default('ناجح'); // نتيجة الثمن
            $table->text('mistake')->nullable(); // تفاصيل الخطأ في حال الرسوب
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('weekly_test_questions');
    }
};
