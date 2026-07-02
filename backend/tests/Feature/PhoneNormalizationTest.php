<?php

namespace Tests\Feature;

use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * تطبيع الهاتف الليبي: كل الصيَغ (+218 / 00218 / أرقام عربية / مسافات) = نفس الرقم،
 * والمطابقة الموحّدة تمنع تكرار حساب ولي الأمر.
 */
class PhoneNormalizationTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    public function test_normalize_variants(): void
    {
        $this->assertSame('0912345678', PhoneNumber::normalize('0912345678'));
        $this->assertSame('0912345678', PhoneNumber::normalize('+218912345678'));
        $this->assertSame('0912345678', PhoneNumber::normalize('00218 91 234-5678'));
        $this->assertSame('0912345678', PhoneNumber::normalize('٠٩١٢٣٤٥٦٧٨'));
        $this->assertSame('0912345678', PhoneNumber::normalize(' 091 234 5678 '));
        $this->assertNull(PhoneNumber::normalize(null));
        $this->assertNull(PhoneNumber::normalize('  '));
    }

    public function test_same_guardian_in_different_formats_maps_to_one_parent_account(): void
    {
        $admin  = $this->makeAdmin();
        $center = $this->makeCenter();
        $token  = $this->loginToken($admin);

        // الابن الأول: هاتف الولي بصيغة محلية
        $r1 = $this->authed($token)->postJson('/api/students', [
            'name'           => 'الابن الأول',
            'center_id'      => $center->id,
            'guardian_name'  => 'ولي التطبيع',
            'guardian_phone' => '0926010203',
            'guardian_email' => 'normalize.parent@test.ly',
        ]);
        $r1->assertStatus(201);
        $p1 = $r1->json('data.parent_id');

        // الابن الثاني: نفس الرقم بصيغة دولية وبمسافات — بلا بريد (يطابق بالهاتف)
        $r2 = $this->authed($token)->postJson('/api/students', [
            'name'           => 'الابن الثاني',
            'center_id'      => $center->id,
            'guardian_name'  => 'ولي التطبيع',
            'guardian_phone' => '+218 92 601-0203',
        ]);
        $r2->assertStatus(201);

        $this->assertNotNull($p1);
        $this->assertSame($p1, $r2->json('data.parent_id'), 'صيغتا الهاتف لم تُطابقا نفس الحساب');

        // الهاتف مخزَّن مطبَّعاً على الحساب
        $this->assertDatabaseHas('users', ['id' => $p1, 'phone' => '0926010203', 'role' => 'parent']);
    }

    public function test_otp_request_matches_international_format(): void
    {
        $this->makeParent(['phone' => '0926999888']);

        // طلب OTP بصيغة +218 لنفس الرقم → يجد المستخدم ويُنشئ شفرة
        $this->postJson('/api/auth/forgot-password/request', ['phone' => '+218 92 699 9888'])
            ->assertOk();

        $this->assertDatabaseCount('otp_resets', 1);
    }
}
