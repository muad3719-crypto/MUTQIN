<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');                            // تاريخ المراجعة
            $table->string('surah_name');                    // السورة المراجعة
            $table->integer('page_from')->nullable();        // من الصفحة
            $table->integer('page_to')->nullable();          // إلى الصفحة
            $table->enum('quality', ['excellent', 'good', 'average', 'weak'])
                  ->default('good');                         // جودة المراجعة
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revisions');
    }
};
