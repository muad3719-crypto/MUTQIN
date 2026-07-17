<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\WeeklyTest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * سلوك التكرار المعتمد:
 *  - الحضور: سجل واحد لكل (طالب، يوم) — تغيير حالة محفوظة يحتاج confirm=true
 *    (409 بدونها)، والتحديث لا يُنشئ صفاً ثانياً أبداً.
 *  - الاختبار الأسبوعي: تكرار حر — عدة اختبارات لنفس الطالب في نفس اليوم
 *    كلها سجلات مستقلة (اختبار السبت هو الأصل وبعض الطلاب يُختبرون استثناءً).
 */
class AttendanceDuplicationTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    public function test_duplicate_attendance_updates_instead_of_duplicating(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent($teacher);
        $token   = $this->loginToken($teacher);
        $date    = '2026-07-15';

        // 1) تسجيل أولي — ينجح ويُنشئ صفاً واحداً
        $this->authed($token)->postJson('/api/attendance', [
            'date' => $date, 'attendance' => [$student->id => 'present'],
        ])->assertOk();
        $this->assertSame(1, Attendance::where('student_id', $student->id)->where('date', $date)->count());

        // 2) نفس الحالة مرة أخرى — لا تعارض (لا شيء يتغيّر)
        $this->app['auth']->forgetGuards();
        $this->authed($token)->postJson('/api/attendance', [
            'date' => $date, 'attendance' => [$student->id => 'present'],
        ])->assertOk();

        // 3) حالة مختلفة بلا confirm — يُرفض بـ409 مع تفاصيل التعارض وبلا أي كتابة
        $this->app['auth']->forgetGuards();
        $r = $this->authed($token)->postJson('/api/attendance', [
            'date' => $date, 'attendance' => [$student->id => 'absent'],
        ]);
        $r->assertStatus(409);
        $this->assertSame($student->id, $r->json('data.conflicts.0.student_id'));
        $this->assertSame('present', $r->json('data.conflicts.0.current_status'));
        $this->assertSame('absent', $r->json('data.conflicts.0.new_status'));
        $this->assertSame('present', Attendance::where('student_id', $student->id)->where('date', $date)->value('status'));

        // 4) مع confirm=true — يُحدَّث السجل نفسه (صف واحد دائماً، لا تضاعف)
        $this->app['auth']->forgetGuards();
        $this->authed($token)->postJson('/api/attendance', [
            'date' => $date, 'attendance' => [$student->id => 'absent'], 'confirm' => true,
        ])->assertOk();
        $rows = Attendance::where('student_id', $student->id)->where('date', $date)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('absent', $rows->first()->status);
    }

    public function test_weekly_test_repeats_freely_same_day(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent($teacher);
        $token   = $this->loginToken($teacher);

        $payload = [
            'student_id' => $student->id,
            'exam_date'  => '2026-07-15',
            'questions'  => [['eighth_start' => 'قل هو الله أحد', 'result' => 'ناجح']],
        ];

        // اختباران لنفس الطالب في نفس اليوم — كلاهما يُقبل كسجل مستقل بلا تنبيه
        $this->authed($token)->postJson('/api/weekly-tests', $payload)->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->authed($token)->postJson('/api/weekly-tests', array_merge($payload, [
            'questions' => [['eighth_start' => 'تبارك الذي بيده الملك', 'result' => 'راسب']],
        ]))->assertCreated();

        $tests = WeeklyTest::where('student_id', $student->id)->whereDate('exam_date', '2026-07-15')->get();
        $this->assertCount(2, $tests);
        $this->assertSame(['ناجح', 'راسب'], $tests->pluck('result')->all());
    }
}
