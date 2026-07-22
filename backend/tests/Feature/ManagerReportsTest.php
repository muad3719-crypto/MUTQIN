<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * تقارير مدير المركز: مركزه فقط، لا وصول لمركز آخر بأي معامل، تقارير الأدمن
 * لم تنكسر، وأداء atRiskStudents لم يتدهور بعد إضافة النطاق.
 */
class ManagerReportsTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    public function test_manager_reports_are_scoped_to_his_center(): void
    {
        $centerA = $this->makeCenter(['name' => 'مركز أ']);
        $centerB = $this->makeCenter(['name' => 'مركز ب']);
        $teacherA = $this->makeTeacher($centerA);
        $teacherB = $this->makeTeacher($centerB);
        $sA = $this->makeStudent($teacherA);
        $sB = $this->makeStudent($teacherB);
        Attendance::create(['student_id' => $sA->id, 'teacher_id' => $teacherA->id, 'center_id' => $centerA->id, 'date' => '2026-07-15', 'status' => 'present']);
        Attendance::create(['student_id' => $sB->id, 'teacher_id' => $teacherB->id, 'center_id' => $centerB->id, 'date' => '2026-07-15', 'status' => 'present']);

        $manager = $this->makeManager($centerA);
        $token = $this->loginToken($manager);

        // المجموعة 1
        $r = $this->authed($token)->getJson('/api/manager/reports/system?month=7&year=2026')->assertOk();
        $this->assertSame($centerA->id, $r->json('data.center.id'));
        $this->assertSame(1, $r->json('data.summary.studentsCount')); // طالب مركزه فقط

        // المجموعة 2
        $this->app['auth']->forgetGuards();
        $r2 = $this->authed($token)->getJson('/api/manager/reports/management?month=7&year=2026')->assertOk();
        $this->assertSame(1, $r2->json('data.summary.teachersCount'));  // محفّظ مركزه فقط
        $this->assertSame(1, $r2->json('data.summary.studentsCount'));
    }

    public function test_manager_cannot_request_another_center_via_param(): void
    {
        $centerA = $this->makeCenter();
        $centerB = $this->makeCenter();
        $tB = $this->makeTeacher($centerB);
        $this->makeStudent($tB);
        $this->makeStudent($tB); // مركز B فيه طالبان

        $manager = $this->makeManager($centerA); // مركزه A فارغ
        $token = $this->loginToken($manager);

        // يمرّر center_id لمركز B — يُتجاهل، يبقى مركزه A (0 طلاب)
        $r = $this->authed($token)->getJson('/api/manager/reports/system?center_id=' . $centerB->id)->assertOk();
        $this->assertSame($centerA->id, $r->json('data.center.id'));
        $this->assertSame(0, $r->json('data.summary.studentsCount'));
    }

    public function test_admin_reports_still_work_unscoped(): void
    {
        $admin = $this->makeAdmin();
        $centerA = $this->makeCenter();
        $centerB = $this->makeCenter();
        $tA = $this->makeTeacher($centerA);
        $tB = $this->makeTeacher($centerB);
        $this->makeStudent($tA);
        $this->makeStudent($tB);
        $token = $this->loginToken($admin);

        // atRisk PDF للأدمن بلا نطاق يشمل كل المراكز (لم ينكسر)
        $svc = app(ReportService::class);
        $allTeachers = $svc->teachersPerformance(7, 2026);          // بلا centerId
        $this->assertCount(2, $allTeachers['rows']);                 // محفّظا المركزين
        $scoped = $svc->teachersPerformance(7, 2026, $centerA->id);  // نطاق A
        $this->assertCount(1, $scoped['rows']);

        // مسار الأدمن للـ at-risk PDF يعمل (200 application/pdf)
        $this->authed($token)->get('/api/reports/admin/at-risk/pdf')
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_at_risk_query_count_stays_constant_with_scope(): void
    {
        $center = $this->makeCenter();
        $teacher = $this->makeTeacher($center);
        for ($i = 0; $i < 6; $i++) {
            $s = $this->makeStudent($teacher);
            Attendance::create(['student_id' => $s->id, 'teacher_id' => $teacher->id, 'center_id' => $center->id, 'date' => '2026-07-15', 'status' => 'absent']);
        }
        $svc = app(ReportService::class);

        // النطاق لا يُدخل N+1: نفس عدد الاستعلامات مع 6 طلاب (3: طلاب + إحصاءان)
        DB::flushQueryLog();
        DB::enableQueryLog();
        $svc->atRiskStudents(7, 2026, $center->id);
        $scopedCount = count(DB::getQueryLog());

        DB::flushQueryLog();
        $svc->atRiskStudents(7, 2026); // بلا نطاق
        $unscopedCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($unscopedCount, $scopedCount); // النطاق لم يضف استعلامات
        // ثابت صغير مستقل عن عدد الطلاب (طلاب + center/teacher مُحمّلان + إحصاءان)
        // — N+1 مع 6 طلاب كان سيقفز إلى 13+
        $this->assertLessThanOrEqual(5, $scopedCount);
    }
}
