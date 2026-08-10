<?php

namespace Tests\Feature;

use App\Models\Center;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * المراكز: كود C{n} مولّد من الباك، وتفعيل/تعطيل بديلاً عن الحذف مع تتالي
 * الأثر على منتسبي المركز (منع الدخول + إبطال الجلسات)، وتفاصيل بلا N+1.
 */
class CenterStatusTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    public function test_center_gets_sequential_c_code_and_client_value_is_ignored(): void
    {
        $admin = $this->makeAdmin();
        $token = $this->loginToken($admin);

        $r1 = $this->authed($token)->postJson('/api/centers', [
            'name' => 'مركز الأول', 'city' => 'طرابلس', 'display_code' => 'C999',
        ])->assertCreated();
        $this->app['auth']->forgetGuards();
        $r2 = $this->authed($token)->postJson('/api/centers', ['name' => 'مركز الثاني'])->assertCreated();

        $this->assertSame('C1', $r1->json('data.display_code')); // مولّد لا C999
        $this->assertSame('C2', $r2->json('data.display_code')); // متتالٍ
        $this->assertDatabaseMissing('centers', ['display_code' => 'C999']);
        $this->assertSame('طرابلس', $r1->json('data.city')); // city يُحفظ فعلاً
    }

    public function test_deactivating_center_blocks_members_and_revokes_their_tokens(): void
    {
        $admin   = $this->makeAdmin();
        $center  = $this->makeCenter();
        $teacher = $this->makeTeacher($center);
        $manager = $this->makeManager($center);
        $parent  = $this->makeParent(); // بلا مركز — لا يتأثر

        $tToken = $this->loginToken($teacher);
        $this->app['auth']->forgetGuards();
        $mToken = $this->loginToken($manager);
        $this->assertSame(1, $teacher->tokens()->count());

        // الأدمن يعطّل المركز
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $r = $this->authed($this->loginToken($admin))
            ->putJson("/api/centers/{$center->id}/status", ['is_active' => false])->assertOk();
        $this->assertSame(2, $r->json('data.affected_members')); // المحفّظ + مدير المركز

        // جلساتهم أُبطلت فوراً
        $this->assertSame(0, $teacher->tokens()->count());
        $this->assertSame(0, $manager->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->authed($tToken)->getJson('/api/profile')->assertUnauthorized();

        // ويُمنعون من الدخول برسالة المركز
        $this->flushHeaders();
        $this->postJson('/api/auth/login', ['email' => $teacher->email, 'password' => 'password'])
            ->assertStatus(403)->assertJsonPath('message', 'مركزك غير نشط حالياً، راجع إدارة النظام');
        $this->postJson('/api/auth/login', ['email' => $manager->email, 'password' => 'password'])
            ->assertStatus(403);

        // ولي الأمر (بلا مركز) يدخل عادياً
        $this->postJson('/api/auth/login', ['email' => $parent->email, 'password' => 'password'])->assertOk();

        // إعادة التفعيل تُعيد الدخول
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->authed($this->loginToken($admin))
            ->putJson("/api/centers/{$center->id}/status", ['is_active' => true])->assertOk();
        $this->flushHeaders();
        $this->postJson('/api/auth/login', ['email' => $teacher->email, 'password' => 'password'])->assertOk();
    }

    public function test_center_deletion_route_is_gone(): void
    {
        $admin  = $this->makeAdmin();
        $center = $this->makeCenter();

        $this->authed($this->loginToken($admin))
            ->deleteJson("/api/centers/{$center->id}")->assertStatus(405);

        $this->assertDatabaseHas('centers', ['id' => $center->id]); // باقٍ بكل تاريخه
    }

    public function test_inactive_center_hidden_from_selection_lists_only(): void
    {
        $admin  = $this->makeAdmin();
        $active = $this->makeCenter(['name' => 'مركز نشط']);
        $off    = $this->makeCenter(['name' => 'مركز معطَّل', 'is_active' => false]);
        $token  = $this->loginToken($admin);

        // قوائم الاختيار: النشط فقط
        $r = $this->authed($token)->getJson('/api/centers?all=1&active=1')->assertOk();
        $this->assertCount(1, $r->json('data'));
        $this->assertSame($active->id, $r->json('data.0.id'));

        // صفحة الإدارة: الكل
        $this->app['auth']->forgetGuards();
        $r = $this->authed($token)->getJson('/api/centers?all=1')->assertOk();
        $this->assertCount(2, $r->json('data'));
    }

    public function test_center_details_include_teachers_without_n_plus_1(): void
    {
        $admin   = $this->makeAdmin();
        $center  = $this->makeCenter(['name' => 'مركز التفاصيل', 'city' => 'بنغازي']);
        $primary = $this->makeTeacher($center, ['name' => 'الأساسي', 'type' => 'محفظ أساسي']);
        for ($i = 0; $i < 4; $i++) {
            $t = $this->makeTeacher($center, ['type' => 'محفظ معاون']);
            $this->makeStudent($t);
        }
        $token = $this->loginToken($admin);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $r = $this->authed($token)->getJson("/api/centers/{$center->id}")->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($center->display_code, $r->json('data.display_code'));
        $this->assertSame('بنغازي', $r->json('data.city'));
        $this->assertSame(5, $r->json('data.teachers_count'));
        $this->assertSame(4, $r->json('data.students_count'));
        $this->assertCount(5, $r->json('data.teachers'));
        $this->assertSame($primary->name, $r->json('data.teachers.0.name')); // الأساسي أولاً
        $this->assertSame('محفظ أساسي', $r->json('data.teachers.0.type'));
        $this->assertTrue($r->json('data.teachers.0.is_active'));

        // عدد الاستعلامات ثابت صغير رغم 5 محفّظين (N+1 كان سيقفز)
        $this->assertLessThanOrEqual(5, $queries);
    }

    public function test_only_admin_can_toggle_center_status(): void
    {
        $center  = $this->makeCenter();
        $manager = $this->makeManager($center);
        $teacher = $this->makeTeacher($center);

        $this->authed($this->loginToken($manager))
            ->putJson("/api/centers/{$center->id}/status", ['is_active' => false])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->authed($this->loginToken($teacher))
            ->putJson("/api/centers/{$center->id}/status", ['is_active' => false])->assertForbidden();

        $this->assertTrue($center->fresh()->is_active);
    }
}
