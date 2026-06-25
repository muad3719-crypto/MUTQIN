<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('center_id')->nullable()->constrained('centers')->nullOnDelete();
            $table->enum('type', ['محفظ أساسي', 'محفظ معاون'])->nullable();
        });

        Schema::table('students', function (Blueprint $table) {
            $table->integer('age')->nullable()->after('phone');
        });

        Schema::table('weekly_tests', function (Blueprint $table) {
            $table->renameColumn('date', 'exam_date');
            $table->dropColumn(['test_type', 'passed']);
            $table->enum('result', ['ناجح', 'راسب'])->default('ناجح')->after('teacher_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['center_id']);
            $table->dropColumn('center_id');
            $table->dropColumn('type');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('age');
        });

        Schema::table('weekly_tests', function (Blueprint $table) {
            $table->renameColumn('exam_date', 'date');
            $table->dropColumn('result');
            $table->enum('test_type', ['memorization', 'revision', 'tajweed', 'general'])->default('memorization');
            $table->boolean('passed')->default(false);
        });
    }
};
