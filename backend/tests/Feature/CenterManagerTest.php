<?php

namespace Tests\Feature;

use App\Models\StudentRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * «مدير المركز» (center_manager): مصفوفة الأدوار، تضييق النطاق بمركزه،
 * مدير واحد لكل مركز، الاعتماد الداخلي، وfallback مدير النظام.
 */
class CenterManagerTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    // ===== مصفوفة الأدوار الرباعية =====

    public function test_manager_routes_matrix(): void
    {
        $admin   = $this->makeAdmin();
        $teacher = $this->makeTeacher();
        $parent  = $this->makeParent();
        $manager = $this->makeManager();

        $tokens = [
            'admin'   => $this->loginToken($admin),
            'teacher' => $this->loginToken($teacher),
            'parent'  => $this->loginToken($parent),
            'manager' => $this->loginToken($manager),
        ];

        // مسارات مدير المركز: له 200، ولغيره 403
        foreach (['/api/manager/dashboard', '/api/manager/teachers', '/api/manager/students', '/api/manager/student-requests'] as $route) {
            $this->authed($tokens['manager'])->getJson($route)->assertOk();
            foreach (['admin', 'teacher', 'parent'] as $other) {
                $this->authed($tokens[$other])->getJson($route)->assertStatus(403, "فشل: {$other} على {$route}");
            }
            $this->flushHeaders();
            $this->app['auth']->forgetGuards();
            $this->getJson($route)->assertStatus(401);
        }

        // مدير المركز على مسارات غيره → 403
        foreach (['/api/teachers', '/api/students', '/api/admin/student-requests', '/api/parent/children', '/api/admin/managers'] as $route) {
            $this->authed($tokens['manager'])->getJson($route)->assertStatus(403, "فشل: manager على {$route}");
        }
    }

    public function test_manager_token_cannot_escalate_even_if_role_tampered(): void
    {
        $manager = $this->makeManager();
        $token   = $this->loginToken($manager);

        $manager->update(['role' => 'admin']); // عبث بالدور بعد إصدار التوكن

        // قدرة التوكن 'manager' فقط — لا تبلغ مسارات مدير النظام ('*')
        $this->authed($token)->getJson('/api/teachers')->assertStatus(403);
    }

    // ===== تضييق النطاق بالمركز =====

    public function test_manager_scope_is_his_center_only(): void
    {
        $centerA = $this->makeCenter(['name' => 'مركز الفرقان']);
        $centerB = $this->makeCenter(['name' => 'مركز الصحابة']);
        $managerA = $this->makeManager($centerA);
        $teacherA = $this->makeTeacher($centerA);
        $teacherB = $this->makeTeacher($centerB);
        $this->makeStudent($teacherA);
        $this->makeStudent($teacherB);

        $token = $this->loginToken($managerA);

        // قائمة محفّظيه: محفّظو مركزه فقط
        $r = $this->authed($token)->getJson('/api/manager/teachers?all=1');
        $this->assertSame([$teacherA->id], collect($r->json('data'))->pluck('id')->all());

        // قائمة طلابه: طلاب مركزه فقط
        $r = $this->authed($token)->getJson('/api/manager/students');
        foreach ($r->json('data.data') as $s) {
            $this->assertSame($centerA->id, $s['center_id'], 'ظهر طالب من مركز آخر!');
        }

        // محفّظ مركز آخر: عرض/تعديل → 404
        $this->authed($token)->getJson('/api/manager/teachers/' . $teacherB->id)->assertStatus(404);
        $this->authed($token)->putJson('/api/manager/teachers/' . $teacherB->id, [
            'name' => 'اختراق', 'email' => $teacherB->email, 'type' => 'محفظ معاون',
        ])->assertStatus(404);

        // محفّظ مركزه: التعديل يعمل
        $this->authed($token)->putJson('/api/manager/teachers/' . $teacherA->id, [
            'name' => 'اسم محدث', 'email' => $teacherA->email, 'type' => 'محفظ معاون',
        ])->assertOk();
    }

    public function test_manager_approves_internal_transfer_but_not_cross_center(): void
    {
        $centerA = $this->makeCenter();
        $centerB = $this->makeCenter();
        $managerA = $this->makeManager($centerA);
        $tA1 = $this->makeTeacher($centerA);
        $tA2 = $this->makeTeacher($centerA);
        $tB  = $this->makeTeacher($centerB);

        // طلب داخلي: طالب عند tA1، يطلبه tA2 (from=target=A)
        $sA = $this->makeStudent($tA1);
        $this->authed($this->loginToken($tA2))->postJson('/api/student-requests', [
            'type' => 'transfer', 'student_id' => $sA->id,
        ])->assertStatus(201);
        $internal = StudentRequest::latest('id')->first();

        // طلب عابر: طالب في B يطلبه tA2 (from=B, target=A)
        $sB = $this->makeStudent($tB);
        $this->authed($this->loginToken($tA2))->postJson('/api/student-requests', [
            'type' => 'transfer', 'student_id' => $sB->id,
        ])->assertStatus(201);
        $cross = StudentRequest::latest('id')->first();

        $token = $this->loginToken($managerA);

        // قائمة المدير: الداخلي فقط
        $ids = collect($this->authed($token)->getJson('/api/manager/student-requests')->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($internal->id));
        $this->assertFalse($ids->contains($cross->id));

        // العابر → 403 برسالة عربية؛ الداخلي → اعتماد ينفّذ النقل
        $this->authed($token)->postJson("/api/manager/student-requests/{$cross->id}/approve")
            ->assertStatus(403);
        $this->authed($token)->postJson("/api/manager/student-requests/{$internal->id}/approve")
            ->assertOk();
        $this->assertSame($tA2->id, $sA->fresh()->teacher_id, 'النقل الداخلي لم يُنفَّذ');
    }

    // ===== مدير واحد لكل مركز + fallback =====

    public function test_one_manager_per_center_max(): void
    {
        $admin  = $this->makeAdmin();
        $center = $this->makeCenter();
        $this->makeManager($center);
        $token = $this->loginToken($admin);

        $this->authed($token)->postJson('/api/admin/managers', [
            'name' => 'مدير ثانٍ', 'email_prefix' => 'second.manager',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'center_id' => $center->id,
        ])->assertStatus(422)
          ->assertJsonPath('errors.center_id.0', 'لهذا المركز مدير بالفعل — مدير واحد لكل مركز كحدّ أقصى');
    }

    public function test_manager_creation_builds_scheme_email_and_requires_password(): void
    {
        $admin  = $this->makeAdmin();
        $center = $this->makeCenter();
        $token  = $this->loginToken($admin);

        // بلا كلمة مرور → 422 (لا توليد صامت)
        $this->authed($token)->postJson('/api/admin/managers', [
            'name' => 'مدير بلا كلمة', 'email_prefix' => 'no.pass', 'center_id' => $center->id,
        ])->assertStatus(422);

        // إنشاء سليم → البريد بمخطط {prefix}.centeradmin@mutqin.ly
        $r = $this->authed($token)->postJson('/api/admin/managers', [
            'name' => 'معاذ', 'email_prefix' => 'muad',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'center_id' => $center->id,
        ])->assertStatus(201);
        $this->assertSame('muad.centeradmin@mutqin.ly', $r->json('data.email'));

        // نفس الاسم اللاتيني لمركز آخر → 422 برسالة واضحة (البريد محجوز)
        $center2 = $this->makeCenter();
        $this->authed($token)->postJson('/api/admin/managers', [
            'name' => 'معاذ آخر', 'email_prefix' => 'muad',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'center_id' => $center2->id,
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['email_prefix']);
    }

    public function test_internal_request_notifies_manager_else_admin_fallback(): void
    {
        $admin = $this->makeAdmin();

        // مركز له مدير: الإشعار للمدير لا لمدير النظام
        $centerA = $this->makeCenter();
        $managerA = $this->makeManager($centerA);
        $tA1 = $this->makeTeacher($centerA);
        $tA2 = $this->makeTeacher($centerA);
        $sA = $this->makeStudent($tA1);
        $this->authed($this->loginToken($tA2))->postJson('/api/student-requests', [
            'type' => 'transfer', 'student_id' => $sA->id,
        ])->assertStatus(201);
        $this->assertSame(1, $managerA->notifications()->count(), 'مدير المركز لم يُشعَر');
        $this->assertSame(0, $admin->notifications()->count(), 'مدير النظام أُشعر رغم وجود مدير مركز');

        // مركز بلا مدير: fallback لمدير النظام
        $centerB = $this->makeCenter();
        $tB1 = $this->makeTeacher($centerB);
        $tB2 = $this->makeTeacher($centerB);
        $sB = $this->makeStudent($tB1);
        $this->authed($this->loginToken($tB2))->postJson('/api/student-requests', [
            'type' => 'transfer', 'student_id' => $sB->id,
        ])->assertStatus(201);
        $this->assertSame(1, $admin->notifications()->count(), 'الـ fallback لمدير النظام لم يعمل');
    }

    public function test_deleting_manager_hands_pending_requests_to_admin(): void
    {
        $admin   = $this->makeAdmin();
        $center  = $this->makeCenter(['name' => 'مركز الاختبار']);
        $manager = $this->makeManager($center);
        $t1 = $this->makeTeacher($center);
        $t2 = $this->makeTeacher($center);
        $s  = $this->makeStudent($t1);

        // طلب داخلي معلّق
        $this->authed($this->loginToken($t2))->postJson('/api/student-requests', [
            'type' => 'transfer', 'student_id' => $s->id,
        ])->assertStatus(201);

        $adminNotifsBefore = $admin->notifications()->count();
        $this->authed($this->loginToken($admin))
            ->deleteJson('/api/admin/managers/' . $manager->id)
            ->assertOk();

        // مدير النظام أُشعر بأيلولة الطلبات إليه
        $this->assertSame($adminNotifsBefore + 1, $admin->notifications()->count());
        $latest = $admin->notifications()->latest()->first();
        $this->assertStringContainsString('آل', $latest->data['title'] . $latest->data['body']);
        // والطلب لا يزال معلّقاً ويعتمده مدير النظام
        $req = StudentRequest::where('student_id', $s->id)->first();
        $this->authed($this->loginToken($admin))
            ->postJson("/api/admin/student-requests/{$req->id}/approve")
            ->assertOk();
    }
}
