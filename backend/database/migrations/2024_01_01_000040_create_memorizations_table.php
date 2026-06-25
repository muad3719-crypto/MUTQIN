<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');                            // تاريخ الحفظ

            // تحديد ما حفظه الطالب
            $table->string('surah_name');                    // اسم السورة
            $table->integer('juz')->nullable();              // رقم الجزء (1-30)
            $table->integer('hizb')->nullable();             // رقم الحزب (1-60)
            $table->integer('page_from')->nullable();        // من الصفحة
            $table->integer('page_to')->nullable();          // إلى الصفحة
            $table->string('eighth')->nullable();            // الثُمن (ربع، نصف، ثلاثة أرباع، إلخ)

            // التقييم
            $table->enum('quality', ['excellent', 'good', 'average', 'weak'])
                  ->default('good');                         // جودة الحفظ
            $table->text('notes')->nullable();               // ملاحظات المعلم
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memorizations');
    }
};
