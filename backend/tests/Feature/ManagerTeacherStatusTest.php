<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * تفعيل/تعطيل المحفّظ من مدير المركز — PUT /api/manager/teachers/{id}/status:
 * مضيَّق بمركزه (خارج المركز أو غير محفّظ → 403)، الأساسي الوحيد لا يُوقَف (422)،
 * والتعطيل يبطل توكنات المحفّظ فوراً (401 على الطلب التالي).
 */
class ManagerTeacherStatusTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    public function test_manager_toggles_own_center_teacher(): void
    {
        $center  = $this->makeCenter();
        $manager = $this->makeManager($center);
        $teacher = $this->makeTeacher($center); // معاون

        $token = $this->loginToken($manager);

        $this->authed($token)
            ->putJson("/api/manager/teachers/{$teacher->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        // التدقيق مسجَّل: مَن غيّر ومتى
        $fresh = $teacher->fresh();
        $this->assertFalse($fresh->is_active);
        $this->assertSame($manager->id, $fresh->status_changed_by);
        $this->assertNotNull($fresh->status_changed_at);

        // إعادة التفعيل من نفس المسار
        $this->authed($token)
            ->putJson("/api/manager/teachers/{$teacher->id}/status", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
        $this->assertTrue($teacher->fresh()->is_active);
    }

    public function test_other_center_teacher_is_forbidden(): void
    {
        $manager = $this->makeManager($this->makeCenter(['name' => 'مركز أ']));
        $other   = $this->makeTeacher($this->makeCenter(['name' => 'مركز ب']));

        $this->authed($this->loginToken($manager))
            ->putJson("/api/manager/teachers/{$other->id}/status", ['is_active' => false])
            ->assertForbidden();

        $this->assertTrue($other->fresh()->is_active); // لم يتغيّر
    }

    public function test_non_teacher_target_is_forbidden(): void
    {
        $center   = $this->makeCenter();
        $manager  = $this->makeManager($center);
        // مدير مركز آخر بنفس المركز مستحيل (مدير واحد) — نستهدف مستخدماً بدور غير teacher
        $parent = $this->makeParent(['center_id' => $center->id]);

        $this->authed($this->loginToken($manager))
            ->putJson("/api/manager/teachers/{$parent->id}/status", ['is_active' => false])
            ->assertForbidden();

        $this->assertTrue($parent->fresh()->is_active);
    }

    public function test_sole_primary_teacher_cannot_be_deactivated(): void
    {
        $center  = $this->makeCenter();
        $manager = $this->makeManager($center);
        $primary = $this->makeTeacher($center, ['type' => 'محفظ أساسي']);

        $r = $this->authed($this->loginToken($manager))
            ->putJson("/api/manager/teachers/{$primary->id}/status", ['is_active' => false])
            ->assertStatus(422);

        $this->assertStringContainsString('الأساسي', $r->json('errors.is_active.0'));
        $this->assertTrue($primary->fresh()->is_active); // لم يتغيّر
    }

    public function test_deactivation_revokes_teacher_tokens_immediately(): void
    {
        $center  = $this->makeCenter();
        $manager = $this->makeManager($center);
        $teacher = $this->makeTeacher($center);

        $teacherToken = $this->loginToken($teacher);
        // التوكن يعمل قبل التعطيل
        $this->authed($teacherToken)->getJson('/api/profile')->assertOk();

        $this->authed($this->loginToken($manager))
            ->putJson("/api/manager/teachers/{$teacher->id}/status", ['is_active' => false])
            ->assertOk();

        // التوكن أُبطل فوراً — الطلب التالي 401
        $this->authed($teacherToken)->getJson('/api/profile')->assertUnauthorized();
    }

    public function test_teacher_cannot_reach_manager_status_route(): void
    {
        $center  = $this->makeCenter();
        $teacher = $this->makeTeacher($center);
        $victim  = $this->makeTeacher($center);

        $this->authed($this->loginToken($teacher))
            ->putJson("/api/manager/teachers/{$victim->id}/status", ['is_active' => false])
            ->assertForbidden();

        $this->assertTrue($victim->fresh()->is_active);
    }
}
