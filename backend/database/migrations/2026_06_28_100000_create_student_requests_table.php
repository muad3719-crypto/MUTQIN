<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * جدول طلبات الطلاب — المحفّظ يُنشئ «طلباً» (إضافة طالب جديد أو نقل طالب موجود
     * إليه) لا يُنفَّذ فعلياً على جدول students إلا بعد موافقة الأدمن.
     *  - type: نوع الطلب (add | transfer)
     *  - status: حالته (pending | approved | rejected)
     *  - requested_by: المحفّظ مقدّم الطلب
     *  - target_center_id / target_teacher_id: مركز/محفّظ الوجهة (غالباً = مقدّم الطلب ومركزه)
     *  - student_id: الطالب الموجود (يُملأ في طلب النقل)
     *  - national_id + بيانات الطالب: للتحقّق وللإنشاء عند الموافقة على add
     *  - from_center_id / from_teacher_id: مصدر الطالب في طلب النقل
     *  - admin_note: سبب الرفض مثلاً
     */
    public function up(): void
    {
        Schema::create('student_requests', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['add', 'transfer']);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');

            // المحفّظ مقدّم الطلب + وجهة الطالب (مركز/محفّظ المستلِم)
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('target_center_id')->constrained('centers');
            $table->foreignId('target_teacher_id')->constrained('users');

            // الطالب الموجود (طلب النقل، أو يُملأ بعد إنشاء طالب add)
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();

            // بيانات الطالب المُدخلة (إضافة طالب جديد) + الرقم الوطني للتحقّق في الحالتين
            $table->string('national_id', 12)->nullable();
            $table->string('student_name')->nullable();
            $table->integer('age')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('guardian_name')->nullable();
            $table->string('guardian_phone', 20)->nullable();
            $table->string('guardian_email')->nullable(); // يلزم لإنشاء حساب ولي أمر جديد عند الموافقة

            // مصدر الطالب في طلب النقل
            $table->foreignId('from_center_id')->nullable()->constrained('centers');
            $table->foreignId('from_teacher_id')->nullable()->constrained('users');

            $table->text('admin_note')->nullable(); // سبب الرفض / ملاحظة الأدمن
            $table->timestamps();

            $table->index('status');
            $table->index('national_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_requests');
    }
};
