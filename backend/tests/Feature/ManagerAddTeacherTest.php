<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * مدير المركز يضيف محفّظاً مباشرة — الأمان في الطرفين:
 * center_id والدور وdisplay_code كلها مفروضة بالكود لا من العميل،
 * ومنع تعدد الأساسي، والمعاينة بلا حجز، وأكواد متتالية بلا تكرار.
 */
class ManagerAddTeacherTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    /** حمولة صالحة (كلمة المرور مطلوبة، والاسم اللاتيني أحرف ونقاط فقط). */
    private function payload(array $over = []): array
    {
        return array_merge([
            'name'                  => 'محفّظ جديد',
            'email_prefix'          => 'ahmed.ali',
            'phone'                 => '0912345678',
            'password'              => 'secret123',
            'password_confirmation' => 'secret123',
            'type'                  => 'محفظ معاون',
        ], $over);
    }

    public function test_injected_center_id_is_ignored(): void
    {
        $centerA = $this->makeCenter(['name' => 'مركز أ']);
        $centerB = $this->makeCenter(['name' => 'مركز ب']);
        $manager = $this->makeManager($centerA);

        $r = $this->authed($this->loginToken($manager))
            ->postJson('/api/manager/teachers', $this->payload(['center_id' => $centerB->id]))
            ->assertCreated();

        $this->assertSame($centerA->id, $r->json('data.center_id')); // مركزه هو
        $this->assertSame(0, User::where('role', 'teacher')->where('center_id', $centerB->id)->count());
    }

    public function test_injected_role_is_ignored_only_teacher_is_created(): void
    {
        $manager = $this->makeManager();

        $r = $this->authed($this->loginToken($manager))
            ->postJson('/api/manager/teachers', $this->payload(['role' => 'admin']))
            ->assertCreated();

        $this->assertSame('teacher', $r->json('data.role'));
        // لم يُنشأ أي مدير نظام جديد (الأدمن الوحيد لو وُجد هو من البذر)
        $this->assertSame(0, User::where('role', 'admin')->count());
        $this->assertSame(0, User::where('role', 'center_manager')->where('id', '!=', $manager->id)->count());
    }

    public function test_injected_display_code_is_ignored(): void
    {
        $manager = $this->makeManager();

        $r = $this->authed($this->loginToken($manager))
            ->postJson('/api/manager/teachers', $this->payload(['display_code' => 'T999']))
            ->assertCreated();

        $this->assertNotSame('T999', $r->json('data.display_code'));
        $this->assertMatchesRegularExpression('/^T\d+$/', $r->json('data.display_code'));
        $this->assertDatabaseMissing('users', ['display_code' => 'T999']);
    }

    public function test_second_primary_is_rejected_with_current_primary_name(): void
    {
        $center  = $this->makeCenter();
        $manager = $this->makeManager($center);
        $existing = $this->makeTeacher($center, ['name' => 'الشيخ الأساسي', 'type' => 'محفظ أساسي']);

        $r = $this->authed($this->loginToken($manager))
            ->postJson('/api/manager/teachers', $this->payload(['type' => 'محفظ أساسي']))
            ->assertStatus(422);

        $this->assertStringContainsString($existing->name, $r->json('errors.type.0'));
        $this->assertStringContainsString('لا يمكن إضافة أساسي ثانٍ', $r->json('errors.type.0'));

        // المعاون يُقبل في نفس المركز
        $this->app['auth']->forgetGuards();
        $this->authed($this->loginToken($manager))
            ->postJson('/api/manager/teachers', $this->payload(['email_prefix' => 'saleh.ali']))
            ->assertCreated();
    }

    public function test_teacher_cannot_reach_manager_add_route(): void
    {
        $teacher = $this->makeTeacher();

        $this->authed($this->loginToken($teacher))
            ->postJson('/api/manager/teachers', $this->payload())->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->authed($this->loginToken($teacher))
            ->getJson('/api/manager/teachers/next-code')->assertForbidden();
    }

    public function test_preview_does_not_reserve_and_codes_are_sequential(): void
    {
        $manager = $this->makeManager();
        $token   = $this->loginToken($manager);

        $before = DB::table('code_sequences')->where('name', 'teacher')->value('value');

        // معاينتان متتاليتان — لا حجز، نفس الكود، والعدّاد ثابت
        $c1 = $this->authed($token)->getJson('/api/manager/teachers/next-code')->assertOk()->json('data.code');
        $this->app['auth']->forgetGuards();
        $c2 = $this->authed($token)->getJson('/api/manager/teachers/next-code')->assertOk()->json('data.code');
        $this->assertSame($c1, $c2);
        $this->assertSame($before, DB::table('code_sequences')->where('name', 'teacher')->value('value'));

        // إضافتان متتاليتان → T{n} ثم T{n+1} بلا تكرار
        $this->app['auth']->forgetGuards();
        $a = $this->authed($token)->postJson('/api/manager/teachers', $this->payload(['email_prefix' => 'first.one']))
            ->assertCreated()->json('data.display_code');
        $this->app['auth']->forgetGuards();
        $b = $this->authed($token)->postJson('/api/manager/teachers', $this->payload(['email_prefix' => 'second.one']))
            ->assertCreated()->json('data.display_code');

        $this->assertSame($c1, $a); // الأول أخذ الكود المعروض
        $this->assertSame((int) ltrim($a, 'T') + 1, (int) ltrim($b, 'T'));
        $this->assertNotSame($a, $b);
    }
}
