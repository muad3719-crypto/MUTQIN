<?php

namespace Tests\Feature;

use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * معاينة كود الطالب التالي + تمكين مدير المركز من الإضافة:
 * المعاينة تقرأ ولا تحجز، display_code من العميل يُتجاهل، center_id مفروض
 * من نطاق المدير، والإضافات المتتالية تعطي أكواداً متتالية بلا تكرار.
 */
class StudentCodePreviewTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    public function test_preview_returns_next_code_without_reserving(): void
    {
        $admin   = $this->makeAdmin();
        $teacher = $this->makeTeacher();
        $this->makeStudent($teacher); // S1 → العدّاد = 1
        $token = $this->loginToken($admin);

        $before = DB::table('code_sequences')->where('name', 'student')->value('value');

        $r = $this->authed($token)->getJson('/api/students/next-code')->assertOk();
        $this->assertSame('S2', $r->json('data.code'));
        $this->assertSame(2, $r->json('data.number'));

        // معاينة ثانية تعطي نفس النتيجة — لا حجز، العدّاد لم يتغيّر
        $this->app['auth']->forgetGuards();
        $this->authed($token)->getJson('/api/students/next-code')->assertOk()->assertJsonPath('data.code', 'S2');
        $this->assertSame($before, DB::table('code_sequences')->where('name', 'student')->value('value'));
    }

    public function test_manager_can_add_student_and_client_display_code_is_ignored(): void
    {
        $center  = $this->makeCenter();
        $manager = $this->makeManager($center);
        $token   = $this->loginToken($manager);

        $r = $this->authed($token)->postJson('/api/manager/students', [
            'name'         => 'طالب المدير',
            'display_code' => 'S999',   // محاولة حقن — تُتجاهل
            'age'          => 10,
        ])->assertCreated();

        $this->assertSame('S1', $r->json('data.display_code')); // مولّد لا مُرسَل
        $this->assertSame($center->id, $r->json('data.center_id'));
        $this->assertDatabaseMissing('students', ['display_code' => 'S999']);
    }

    public function test_manager_cannot_add_student_to_another_center(): void
    {
        $centerA = $this->makeCenter(['name' => 'مركز أ']);
        $centerB = $this->makeCenter(['name' => 'مركز ب']);
        $manager = $this->makeManager($centerA);
        $token   = $this->loginToken($manager);

        // يرسل center_id لمركز آخر — يُفرض مركزه هو رغم ذلك
        $r = $this->authed($token)->postJson('/api/manager/students', [
            'name'      => 'طالب محاولة',
            'center_id' => $centerB->id,
            'age'       => 12,
        ])->assertCreated();

        $this->assertSame($centerA->id, $r->json('data.center_id')); // مركزه لا B
        $this->assertSame(0, Student::where('center_id', $centerB->id)->count());
    }

    public function test_consecutive_additions_get_sequential_codes(): void
    {
        $center  = $this->makeCenter();
        $manager = $this->makeManager($center);
        $token   = $this->loginToken($manager);

        $codes = [];
        foreach (['أول', 'ثانٍ', 'ثالث'] as $name) {
            $this->app['auth']->forgetGuards();
            $codes[] = $this->authed($token)->postJson('/api/manager/students', [
                'name' => "طالب {$name}", 'age' => 9,
            ])->assertCreated()->json('data.display_code');
        }

        $this->assertSame(['S1', 'S2', 'S3'], $codes); // متتالية بلا تكرار
        $this->assertCount(3, array_unique($codes));
    }

    public function test_teacher_cannot_access_code_preview(): void
    {
        $teacher = $this->makeTeacher();
        $token   = $this->loginToken($teacher);
        // المحفّظ ليس له صلاحية إضافة طالب → لا معاينة (المسار في بوابتَي admin/manager)
        $this->authed($token)->getJson('/api/students/next-code')->assertForbidden();
    }
}
