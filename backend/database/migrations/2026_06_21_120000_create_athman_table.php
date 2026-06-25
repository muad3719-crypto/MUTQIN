<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * فهرس الأثمان الكامل (477 ثمناً) — مرجع للبحث في الحفظ والاختبارات.
     */
    public function up(): void
    {
        Schema::create('athman', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('hizb');           // 1..60
            $table->unsignedTinyInteger('thumn_in_hizb');  // 1..8
            $table->unsignedSmallInteger('global_order');  // 1..477
            $table->string('surah_name');
            $table->text('start_text');                    // بداية الثمن كما في المصحف
            $table->text('start_text_norm');               // نسخة منزوعة التشكيل/موحّدة للبحث
            $table->unsignedSmallInteger('page')->nullable();
            $table->timestamps();

            $table->unique(['hizb', 'thumn_in_hizb']);
            $table->index('surah_name');
            $table->index('global_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('athman');
    }
};
