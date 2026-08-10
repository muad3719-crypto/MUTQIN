<?php

namespace Tests\Feature;

use App\Models\StudentRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * تفعيل/تعطيل مدير المركز بديلاً عن الحذف: المعطَّل لا يدخل، توكناته تُبطَل
 * فوراً، التبديل لمدير النظام حصراً، ولا يُوجَّه إليه طلب جديد — تؤول
 * الطلبات الداخلية لمدير النظام (وإلا بقيت معلّقة بلا مراجع).
 */
class ManagerStatusTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    public function test_inactive_manager_cannot_log_in(): void
    {
        $manager = $this->makeManager(null, ['is_active' => false]);

        $this->postJson('/api/auth/login', ['email' => $manager->email, 'password' => 'password'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'هذا الحساب غير نشط، راجع إدارة المركز');

        // النشط يدخل عادياً
        $active = $this->makeManager();
        $this->postJson('/api/auth/login', ['email' => $active->email, 'password' => 'password'])->assertOk();
    }

    public function test_deactivation_revokes_tokens_and_records_audit(): void
    {
        $admin   = $this->makeAdmin();
        $manager = $this->makeManager();
        $mToken  = $this->loginToken($manager);

        // التوكن يعمل قبل التعطيل
        $this->authed($mToken)->getJson('/api/manager/dashboard')->assertOk();
        $this->assertSame(1, $manager->tokens()->count());

        $this->flushHeaders();
        $this->authed($this->loginToken($admin))
            ->putJson("/api/admin/managers/{$manager->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.pending_transferred', 0);

        // الجلسات أُنهيت فوراً — لا انتظار للدخول التالي
        $this->assertSame(0, $manager->tokens()->count());
        $this->flushHeaders();
        $this->authed($mToken)->getJson('/api/manager/dashboard')->assertUnauthorized();

        $fresh = $manager->fresh();
        $this->assertFalse($fresh->is_active);
        $this->assertSame($admin->id, $fresh->status_changed_by);
        $this->assertNotNull($fresh->status_changed_at);

        // إعادة التفعيل تُعيد الدخول
        $this->flushHeaders();
        $this->authed($this->loginToken($admin))
            ->putJson("/api/admin/managers/{$manager->id}/status", ['is_active' => true])->assertOk();
        $this->flushHeaders();
        $this->postJson('/api/auth/login', ['email' => $manager->email, 'password' => 'password'])->assertOk();
    }

    public function test_only_admin_can_toggle_manager_status(): void
    {
        $center  = $this->makeCenter();
        $manager = $this->makeManager($center);
        $teacher = $this->makeTeacher($center);

        // مدير المركز لا يبدّل حالة نفسه
        $this->authed($this->loginToken($manager))
            ->putJson("/api/admin/managers/{$manager->id}/status", ['is_active' => false])->assertForbidden();

        // المحفّظ ممنوع كذلك
        $this->flushHeaders();
        $this->authed($this->loginToken($teacher))
            ->putJson("/api/admin/managers/{$manager->id}/status", ['is_active' => false])->assertForbidden();

        $this->assertTrue($manager->fresh()->is_active);
    }

    public function test_manager_deletion_route_is_gone(): void
    {
        $admin   = $this->makeAdmin();
        $manager = $this->makeManager();

        $this->authed($this->loginToken($admin))
            ->deleteJson("/api/admin/managers/{$manager->id}")->assertStatus(405);

        $this->assertDatabaseHas('users', ['id' => $manager->id]); // باقٍ بكل ارتباطاته
    }

    public function test_internal_requests_route_to_admin_while_manager_is_inactive(): void
    {
        $admin   = $this->makeAdmin();
        $center  = $this->makeCenter();
        $manager = $this->makeManager($center);
        $from    = $this->makeTeacher($center);
        $to      = $this->makeTeacher($center);
        $s1      = $this->makeStudent($from);
        $s2      = $this->makeStudent($from);

        // مدير نشط: الطلب الداخلي يذهب إليه هو
        $toToken = $this->loginToken($to);
        $this->authed($toToken)->postJson('/api/student-requests', [
            'type' => 'transfer', 'student_id' => $s1->id,
        ])->assertCreated()->assertJsonPath('message', 'تم إرسال طلب النقل لمدير المركز، سيُنفَّذ بعد الموافقة');
        $this->assertSame(1, $manager->notifications()->count());
        $this->assertSame(0, $admin->notifications()->count());

        // التعطيل يبلّغ الأدمن بالمعلّق الذي آل إليه
        $this->flushHeaders();
        $r = $this->authed($this->loginToken($admin))
            ->putJson("/api/admin/managers/{$manager->id}/status", ['is_active' => false])->assertOk();
        $this->assertSame(1, $r->json('data.pending_transferred'));
        $this->assertStringContainsString('آل 1 طلب معلّق لمدير النظام', $r->json('message'));
        $this->assertSame(1, $admin->notifications()->count());

        // وبعده: الطلب الداخلي الجديد يؤول لمدير النظام لا للمعطَّل
        $this->flushHeaders();
        $this->authed($toToken)->postJson('/api/student-requests', [
            'type' => 'transfer', 'student_id' => $s2->id,
        ])->assertCreated()->assertJsonPath('message', 'تم إرسال طلب النقل للمدير، سيُنفَّذ بعد الموافقة');

        $this->assertSame(1, $manager->notifications()->count()); // لم يزد
        $this->assertSame(2, $admin->notifications()->count());   // التنبيه + الطلب الجديد
        $this->assertSame(2, StudentRequest::where('status', 'pending')->count());
    }
}
