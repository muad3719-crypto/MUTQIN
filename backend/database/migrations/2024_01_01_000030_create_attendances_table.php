<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');                                          // تاريخ الحضور
            $table->enum('status', ['present', 'absent', 'late'])         // حاضر / غائب / متأخر
                  ->default('present');
            $table->text('notes')->nullable();                             // ملاحظات
            $table->timestamps();

            // لا يمكن تكرار نفس الطالب في نفس اليوم
            $table->unique(['student_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
