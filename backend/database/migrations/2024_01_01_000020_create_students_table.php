<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('name');                  // اسم الطالب
            $table->date('birth_date')->nullable();  // تاريخ الميلاد
            $table->string('phone')->nullable();     // رقم الهاتف
            $table->string('guardian_name')->nullable();  // اسم ولي الأمر
            $table->string('guardian_phone')->nullable(); // هاتف ولي الأمر
            $table->foreignId('center_id')->nullable()->constrained('centers')->nullOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('enrollment_date')->nullable(); // تاريخ الالتحاق
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
