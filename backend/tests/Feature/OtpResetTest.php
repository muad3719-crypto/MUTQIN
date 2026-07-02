<?php

namespace Tests\Feature;

use App\Models\OtpReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * استعادة كلمة المرور بالهاتف (OTP): توليد، تحقّق، إبطال، وأمان الاستجابة.
 */
class OtpResetTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    public function test_request_is_neutral_and_never_leaks_otp_outside_local(): void
    {
        $parent = $this->makeParent(['phone' => '0926111222']);

        // رقم مسجّل: رسالة محايدة + لا dev_otp (بيئة الاختبار ليست local — S3)
        $this->postJson('/api/auth/forgot-password/request', ['phone' => '0926111222'])
            ->assertOk()
            ->assertJsonMissing(['dev_otp'])
            ->assertJsonPath('message', 'إن كان هذا الرقم مسجّلاً، فستُسلَّم شفرة التحقّق (6 أرقام) إلى صاحبه.');

        $this->assertDatabaseHas('otp_resets', ['user_id' => $parent->id]);

        // رقم غير مسجّل: نفس الرسالة المحايدة تماماً (منع تعداد الأرقام)
        $this->postJson('/api/auth/forgot-password/request', ['phone' => '0900000000'])
            ->assertOk()
            ->assertJsonMissing(['dev_otp'])
            ->assertJsonPath('message', 'إن كان هذا الرقم مسجّلاً، فستُسلَّم شفرة التحقّق (6 أرقام) إلى صاحبه.');
    }

    public function test_correct_otp_resets_password_and_revokes_old_tokens(): void
    {
        $parent = $this->makeParent(['phone' => '0926333444']);

        // جلسة قديمة (توكن) قبل التغيير
        $oldToken = $this->loginToken($parent);
        $this->assertSame(1, $parent->tokens()->count());

        OtpReset::create([
            'user_id'    => $parent->id,
            'otp_hash'   => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->postJson('/api/auth/forgot-password/verify', [
            'phone'    => '0926333444',
            'otp'      => '123456',
            'password' => 'new-secret-1',
        ])->assertOk()->assertJsonPath('success', true);

        // S1: التوكنات القديمة أُبطلت، والشفرة أُبطلت
        $this->assertSame(0, $parent->tokens()->count());
        $this->assertDatabaseMissing('otp_resets', ['user_id' => $parent->id]);
        $this->authed($oldToken)->getJson('/api/auth/user')->assertStatus(401);

        // الدخول بالكلمة الجديدة يعمل، وبالقديمة يُرفض
        $this->postJson('/api/auth/login', ['email' => $parent->email, 'password' => 'new-secret-1'])->assertOk();
        $this->postJson('/api/auth/login', ['email' => $parent->email, 'password' => 'password'])->assertStatus(422);

        // تتبّع التغيير (المرحلة 2 من ميزة OTP): عدّاد + سجل otp
        $parent->refresh();
        $this->assertSame(1, $parent->password_changed_count);
        $this->assertDatabaseHas('password_change_logs', ['user_id' => $parent->id, 'method' => 'otp']);
    }

    public function test_wrong_or_expired_otp_is_rejected_with_generic_message(): void
    {
        $parent = $this->makeParent(['phone' => '0926555666']);
        OtpReset::create([
            'user_id'    => $parent->id,
            'otp_hash'   => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
        ]);

        // شفرة خاطئة
        $this->postJson('/api/auth/forgot-password/verify', [
            'phone' => '0926555666', 'otp' => '000000', 'password' => 'whatever1',
        ])->assertStatus(422)->assertJsonPath('message', 'الشفرة غير صحيحة أو منتهية الصلاحية');

        // شفرة منتهية
        OtpReset::where('user_id', $parent->id)->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/api/auth/forgot-password/verify', [
            'phone' => '0926555666', 'otp' => '123456', 'password' => 'whatever1',
        ])->assertStatus(422)->assertJsonPath('message', 'الشفرة غير صحيحة أو منتهية الصلاحية');

        // كلمة المرور لم تتغيّر
        $this->postJson('/api/auth/login', ['email' => $parent->email, 'password' => 'password'])->assertOk();
    }

    public function test_otp_verify_attempts_are_capped_at_5(): void
    {
        $parent = $this->makeParent(['phone' => '0926777888']);
        OtpReset::create([
            'user_id'    => $parent->id,
            'otp_hash'   => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/forgot-password/verify', [
                'phone' => '0926777888', 'otp' => '000000', 'password' => 'whatever1',
            ])->assertStatus(422);
        }

        // بعد 5 محاولات فاشلة تُرفض حتى الشفرة الصحيحة (تُحذف السجلّة)
        $this->postJson('/api/auth/forgot-password/verify', [
            'phone' => '0926777888', 'otp' => '123456', 'password' => 'whatever1',
        ])->assertStatus(422);
    }
}
