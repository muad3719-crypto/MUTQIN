<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * تفعيل/تعطيل المحفّظ بديلاً عن الحذف: المعطَّل لا يدخل، توكناته تُبطَل فوراً،
 * التبديل لمدير النظام حصراً، والبحث الموحّد يطابق الكود واسم المركز.
 */
class TeacherStatusTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    public function test_inactive_teacher_cannot_log_in(): void
    {
        $teacher = $this->makeTeacher(null, ['is_active' => false]);

        $this->postJson('/api/auth/login', ['email' => $teacher->email, 'password' => 'password'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'هذا الحساب غير نشط، راجع إدارة المركز');

        // النشط يدخل عادياً (الفحص لا يكسر المسار الطبيعي)
        $active = $this->makeTeacher();
        $this->postJson('/api/auth/login', ['email' => $active->email, 'password' => 'password'])->assertOk();
    }

    public function test_deactivation_revokes_tokens_immediately(): void
    {
        $admin   = $this->makeAdmin();
        $teacher = $this->makeTeacher();
        $teacherToken = $this->loginToken($teacher);

        // التوكن يعمل قبل التعطيل
        $this->app['auth']->forgetGuards();
        $this->authed($teacherToken)->getJson('/api/profile')->assertOk();
        $this->assertSame(1, $teacher->tokens()->count());

        // الأدمن يعطّله
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->authed($this->loginToken($admin))
            ->putJson("/api/teachers/{$teacher->id}/status", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        // كل توكناته أُبطلت فوراً — لا انتظار للدخول التالي
        $this->assertSame(0, $teacher->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->authed($teacherToken)->getJson('/api/profile')->assertUnauthorized();

        // التدقيق مسجَّل: مَن غيّر ومتى
        $fresh = $teacher->fresh();
        $this->assertFalse($fresh->is_active);
        $this->assertSame($admin->id, $fresh->status_changed_by);
        $this->assertNotNull($fresh->status_changed_at);
    }

    public function test_only_admin_can_toggle_status(): void
    {
        $center  = $this->makeCenter();
        $teacher = $this->makeTeacher($center);
        $manager = $this->makeManager($center);
        $other   = $this->makeTeacher($center);

        // مدير المركز ممنوع
        $this->authed($this->loginToken($manager))
            ->putJson("/api/teachers/{$teacher->id}/status", ['is_active' => false])->assertForbidden();

        // محفّظ ممنوع
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->authed($this->loginToken($other))
            ->putJson("/api/teachers/{$teacher->id}/status", ['is_active' => false])->assertForbidden();

        $this->assertTrue($teacher->fresh()->is_active); // لم يتغيّر
    }

    public function test_teacher_deletion_route_is_gone(): void
    {
        $admin   = $this->makeAdmin();
        $teacher = $this->makeTeacher();

        // لا حذف إطلاقاً — المسار أُزيل (405)
        $this->authed($this->loginToken($admin))
            ->deleteJson("/api/teachers/{$teacher->id}")->assertStatus(405);

        $this->assertDatabaseHas('users', ['id' => $teacher->id]); // باقٍ بكل ارتباطاته
    }

    public function test_unified_search_matches_code_and_center_name(): void
    {
        $admin    = $this->makeAdmin();
        $centerA  = $this->makeCenter(['name' => 'مركز الفرقان']);
        $centerB  = $this->makeCenter(['name' => 'مركز البشائر']);
        $t1 = $this->makeTeacher($centerA, ['name' => 'أحمد الأول']);   // T1
        $this->makeTeacher($centerB, ['name' => 'سالم الثاني']);        // T2
        $token = $this->loginToken($admin);

        $q = function ($term) use ($token) {
            $this->app['auth']->forgetGuards();
            return $this->authed($token)->getJson('/api/teachers?q=' . urlencode($term))->assertOk();
        };

        // بالكود بصوره الثلاث
        foreach (['T1', '1', '١'] as $term) {
            $r = $q($term);
            $this->assertSame(1, $r->json('data.total'), "فشل البحث بـ{$term}");
            $this->assertSame($t1->id, $r->json('data.data.0.id'));
        }

        // باسم المركز — مع اختلاف الهمزة/التاء («الفرقان» بلا همزة قطع)
        $r = $q('الفرقان');
        $this->assertSame(1, $r->json('data.total'));
        $this->assertSame($t1->id, $r->json('data.data.0.id'));

        // بالاسم المطبَّع
        $r = $q('احمد');
        $this->assertSame(1, $r->json('data.total'));
    }
}
