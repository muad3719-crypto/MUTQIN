<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * مصفوفة الأدوار: النموذج الأمني = فحص مزدوج (الدور + صلاحية التوكن).
 * كل دور يصل لمساراته (200) ويُمنع من مسارات غيره (403)، وبلا توكن 401.
 */
class RoleMatrixTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    public function test_matrix_of_roles_against_routes(): void
    {
        $admin   = $this->makeAdmin();
        $teacher = $this->makeTeacher();
        $parent  = $this->makeParent();

        $tokens = [
            'admin'   => $this->loginToken($admin),
            'teacher' => $this->loginToken($teacher),
            'parent'  => $this->loginToken($parent),
        ];

        // [المسار => الحالة المتوقعة لكل دور]
        $matrix = [
            '/api/teachers'               => ['admin' => 200, 'teacher' => 403, 'parent' => 403],
            '/api/centers'                => ['admin' => 200, 'teacher' => 403, 'parent' => 403],
            '/api/admin/student-requests' => ['admin' => 200, 'teacher' => 403, 'parent' => 403],
            '/api/students'               => ['admin' => 200, 'teacher' => 200, 'parent' => 403],
            '/api/attendance'             => ['admin' => 200, 'teacher' => 200, 'parent' => 403],
            '/api/memorizations'          => ['admin' => 200, 'teacher' => 200, 'parent' => 403],
            '/api/weekly-tests'           => ['admin' => 200, 'teacher' => 200, 'parent' => 403],
            '/api/parent/children'        => ['admin' => 403, 'teacher' => 403, 'parent' => 200],
        ];

        foreach ($matrix as $route => $expectations) {
            foreach ($expectations as $role => $expected) {
                $this->authed($tokens[$role])->getJson($route)
                    ->assertStatus($expected, "فشل: {$role} على {$route}");
            }
            // بلا توكن → 401 دائماً (نفرغ الترويسات الملتصقة من withHeader أولاً)
            $this->flushHeaders();
            $this->app['auth']->forgetGuards();
            $this->getJson($route)->assertStatus(401);
        }
    }

    public function test_parent_scoped_token_cannot_reach_admin_routes_even_if_role_tampered(): void
    {
        // توكن ولي أمر صلاحيته ['parent'] فقط: حتى لو صار الدور admin لاحقاً
        // (سيناريو تصعيد)، الفحص المزدوج tokenCan('*') يمنعه.
        $parent = $this->makeParent();
        $token  = $this->loginToken($parent);

        $parent->update(['role' => 'admin']);

        $this->authed($token)->getJson('/api/teachers')->assertStatus(403);
    }

    public function test_student_creation_is_admin_only(): void
    {
        $teacher = $this->makeTeacher();
        $token   = $this->loginToken($teacher);

        $this->authed($token)->postJson('/api/students', [
            'name'      => 'طالب غير مصرح',
            'center_id' => $teacher->center_id,
        ])->assertStatus(403);
    }
}
