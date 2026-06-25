<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->string('time')->nullable()->after('date');
            $table->timestamp('imported_at')->nullable()->after('notes');
            $table->foreignId('center_id')->nullable()->after('teacher_id')->constrained('centers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropForeign(['center_id']);
            $table->dropColumn(['time', 'imported_at', 'center_id']);
        });
    }
};
