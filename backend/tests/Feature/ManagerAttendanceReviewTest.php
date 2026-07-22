<?php

namespace Tests\Feature;

use App\Models\Attendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * مراجعة وتصحيح الحضور لمدير المركز: يرى طلاب مركزه فقط، لا يصحّح سجل
 * طالب خارج مركزه (403)، التصحيح يُحدّث سجلاً واحداً بلا تضاعف، والفلاتر تعمل.
 */
class ManagerAttendanceReviewTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    private function att(int $studentId, int $teacherId, int $centerId, string $date, string $status): Attendance
    {
        return Attendance::create([
            'student_id' => $studentId, 'teacher_id' => $teacherId,
            'center_id'  => $centerId, 'date' => $date, 'status' => $status,
        ]);
    }

    public function test_manager_sees_only_his_center_attendance(): void
    {
        $centerA = $this->makeCenter(['name' => 'مركز أ']);
        $centerB = $this->makeCenter(['name' => 'مركز ب']);
        $teacherA = $this->makeTeacher($centerA);
        $teacherB = $this->makeTeacher($centerB);
        $sA = $this->makeStudent($teacherA);
        $sB = $this->makeStudent($teacherB);
        $this->att($sA->id, $teacherA->id, $centerA->id, '2026-07-15', 'present');
        $this->att($sB->id, $teacherB->id, $centerB->id, '2026-07-15', 'absent');

        $manager = $this->makeManager($centerA);
        $token = $this->loginToken($manager);

        $r = $this->authed($token)->getJson('/api/manager/attendance')->assertOk();
        $this->assertSame(1, $r->json('data.total')); // سجل مركزه فقط
        $this->assertSame($sA->display_code, $r->json('data.data.0.display_code'));
        $this->assertSame('present', $r->json('data.data.0.status'));
        $this->assertSame('manual', $r->json('data.data.0.source'));
    }

    public function test_manager_cannot_correct_attendance_of_another_center(): void
    {
        $centerA = $this->makeCenter();
        $centerB = $this->makeCenter();
        $teacherB = $this->makeTeacher($centerB);
        $sB = $this->makeStudent($teacherB);
        $recB = $this->att($sB->id, $teacherB->id, $centerB->id, '2026-07-15', 'present');

        $manager = $this->makeManager($centerA);
        $token = $this->loginToken($manager);

        $this->authed($token)->putJson("/api/manager/attendance/{$recB->id}/status", ['status' => 'absent'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'خارج نطاق صلاحيتك');

        $this->assertSame('present', $recB->fresh()->status); // لم يتغيّر
    }

    public function test_correction_updates_single_record_and_records_audit(): void
    {
        $center = $this->makeCenter();
        $teacher = $this->makeTeacher($center);
        $student = $this->makeStudent($teacher);
        $rec = $this->att($student->id, $teacher->id, $center->id, '2026-07-15', 'absent');

        $manager = $this->makeManager($center);
        $token = $this->loginToken($manager);

        $this->authed($token)->putJson("/api/manager/attendance/{$rec->id}/status", ['status' => 'late'])
            ->assertOk()->assertJsonPath('data.status', 'late');

        // سجل واحد فقط لنفس (طالب، يوم) — لا تضاعف
        $this->assertSame(1, Attendance::where('student_id', $student->id)->where('date', '2026-07-15')->count());
        $fresh = $rec->fresh();
        $this->assertSame('late', $fresh->status);
        $this->assertSame($manager->id, $fresh->corrected_by); // تدقيق: مَن صحّح
        $this->assertNotNull($fresh->corrected_at);            // ومتى
    }

    public function test_filters_by_status_and_date(): void
    {
        $center = $this->makeCenter();
        $teacher = $this->makeTeacher($center);
        $s1 = $this->makeStudent($teacher);
        $s2 = $this->makeStudent($teacher);
        $this->att($s1->id, $teacher->id, $center->id, '2026-07-15', 'present');
        $this->att($s2->id, $teacher->id, $center->id, '2026-07-15', 'absent');
        $this->att($s1->id, $teacher->id, $center->id, '2026-07-16', 'late');

        $manager = $this->makeManager($center);
        $token = $this->loginToken($manager);

        // فلتر الحالة
        $this->app['auth']->forgetGuards();
        $r = $this->authed($token)->getJson('/api/manager/attendance?status=absent')->assertOk();
        $this->assertSame(1, $r->json('data.total'));
        $this->assertSame('absent', $r->json('data.data.0.status'));

        // فلتر التاريخ المفرد
        $this->app['auth']->forgetGuards();
        $r = $this->authed($token)->getJson('/api/manager/attendance?date=2026-07-16')->assertOk();
        $this->assertSame(1, $r->json('data.total'));
        $this->assertSame('late', $r->json('data.data.0.status'));

        // فلتر المدى
        $this->app['auth']->forgetGuards();
        $r = $this->authed($token)->getJson('/api/manager/attendance?from=2026-07-15&to=2026-07-16')->assertOk();
        $this->assertSame(3, $r->json('data.total'));
    }
}
