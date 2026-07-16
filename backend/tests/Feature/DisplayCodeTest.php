<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * كود العرض (S/T/CA): البادئة الصحيحة حسب الدور، التفرّد، العدّ المستقل،
 * عدم إعادة استخدام الأرقام بعد الحذف، ولا كود للأدمن وولي الأمر.
 */
class DisplayCodeTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    public function test_student_created_via_api_gets_sequential_s_code(): void
    {
        $admin  = $this->makeAdmin();
        $center = $this->makeCenter();
        $token  = $this->loginToken($admin);

        $r1 = $this->authed($token)->postJson('/api/students', [
            'name' => 'طالب الكود الأول', 'center_id' => $center->id, 'age' => 10,
        ])->assertCreated();
        $r2 = $this->authed($token)->postJson('/api/students', [
            'name' => 'طالب الكود الثاني', 'center_id' => $center->id, 'age' => 11,
        ])->assertCreated();

        $this->assertSame('S1', $r1->json('data.display_code'));
        $this->assertSame('S2', $r2->json('data.display_code'));
    }

    public function test_teacher_and_manager_counters_are_independent(): void
    {
        $center = $this->makeCenter();

        // منشآت مباشرة عبر النموذج (خطاف creating يعمل من أي مسار)
        $t1 = $this->makeTeacher($center);
        $m1 = $this->makeManager($center);
        $t2 = $this->makeTeacher($center);

        $this->assertSame('T1',  $t1->display_code);
        $this->assertSame('CA1', $m1->display_code);
        $this->assertSame('T2',  $t2->display_code); // عدّ المحفّظين لم يتأثر بالمدير
    }

    public function test_deleted_record_code_is_never_reused(): void
    {
        $teacher = $this->makeTeacher();
        $s1 = $this->makeStudent($teacher);
        $s2 = $this->makeStudent($teacher);
        $this->assertSame(['S1', 'S2'], [$s1->display_code, $s2->display_code]);

        $s2->delete();
        $s3 = $this->makeStudent($teacher);

        // S2 حُذف لكن الكود لا يُعاد استخدامه — التالي S3
        $this->assertSame('S3', $s3->display_code);
        $this->assertSame(1, Student::where('display_code', 'S3')->count());
    }

    public function test_codes_are_unique_per_type(): void
    {
        $teacher = $this->makeTeacher();
        for ($i = 0; $i < 5; $i++) {
            $this->makeStudent($teacher);
        }

        $codes = Student::pluck('display_code');
        $this->assertCount(5, $codes->unique());
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^S\d+$/', $code);
        }
    }

    public function test_admin_and_parent_get_no_display_code(): void
    {
        $admin  = $this->makeAdmin();
        $parent = $this->makeParent();

        $this->assertNull($admin->display_code);
        $this->assertNull($parent->display_code);
    }

    public function test_manager_created_via_api_gets_ca_code_and_lists_expose_codes(): void
    {
        $admin  = $this->makeAdmin();
        $center = $this->makeCenter();
        $token  = $this->loginToken($admin);

        $r = $this->authed($token)->postJson('/api/admin/managers', [
            'name' => 'مدير كود', 'email_prefix' => 'codetest', 'center_id' => $center->id,
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertCreated();
        $this->assertSame('CA1', $r->json('data.display_code'));

        // القوائم تعيد display_code (المدراء أعمدة صريحة — والطلاب/المحفّظون نماذج كاملة)
        $this->app['auth']->forgetGuards();
        $list = $this->authed($token)->getJson('/api/admin/managers')->assertOk();
        $this->assertSame('CA1', $list->json('data.0.display_code'));

        $teacher = $this->makeTeacher($center);
        $this->makeStudent($teacher);
        $this->app['auth']->forgetGuards();
        $students = $this->authed($token)->getJson('/api/students')->assertOk();
        $this->assertSame('S1', $students->json('data.data.0.display_code'));
    }
}
