<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tajweed_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');

            // تقييم قواعد التجويد
            $table->integer('makharij_score')->default(0);   // تقييم المخارج (0-10)
            $table->integer('sifat_score')->default(0);      // تقييم الصفات (0-10)
            $table->integer('madd_score')->default(0);       // تقييم المدود (0-10)
            $table->integer('waqf_score')->default(0);       // تقييم الوقف والابتداء (0-10)
            $table->integer('total_score')->default(0);      // المجموع (0-40)

            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tajweed_evaluations');
    }
};
