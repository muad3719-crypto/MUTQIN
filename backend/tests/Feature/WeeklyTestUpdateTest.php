<?php

namespace Tests\Feature;

use App\Models\WeeklyTest;
use App\Models\WeeklyTestQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * تعديل الاختبار الأسبوعي بديلاً عن حذفه — PUT /api/weekly-tests/{id}:
 * استبدال كلي للأثمان داخل transaction (فشل جزئي يُرجع كل شيء)، إعادة حساب
 * النتيجة الكلية (قلب ناجح↔راسب)، الملكية (اختبارات المحفّظ نفسه فقط → 403)،
 * وDELETE صار 405 (قرار «لا حذف»).
 */
class WeeklyTestUpdateTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    /** اختبار قائم بثُمنين: الأول راسب — النتيجة الكلية «راسب». */
    private function makeTest(): array
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent($teacher);
        $test = WeeklyTest::create([
            'student_id' => $student->id, 'teacher_id' => $teacher->id,
            'exam_date' => '2026-07-04', 'result' => 'راسب',
        ]);
        foreach ([['هل جزاء الإحسان', 'راسب'], ['عم يتساءلون', 'ناجح']] as [$start, $res]) {
            WeeklyTestQuestion::create([
                'weekly_test_id' => $test->id, 'student_id' => $student->id,
                'eighth_start' => $start, 'result' => $res,
            ]);
        }

        return [$teacher, $test];
    }

    public function test_update_replaces_questions_and_flips_result(): void
    {
        [$teacher, $test] = $this->makeTest();

        // قلب الراسب إلى ناجح + ثمن ثالث جديد → النتيجة الكلية تصير «ناجح»
        $r = $this->authed($this->loginToken($teacher))
            ->putJson("/api/weekly-tests/{$test->id}", [
                'exam_date' => '2026-07-11',
                'questions' => [
                    ['eighth_start' => 'هل جزاء الإحسان', 'result' => 'ناجح', 'mistake' => null],
                    ['eighth_start' => 'عم يتساءلون', 'result' => 'ناجح', 'mistake' => null],
                    ['eighth_start' => 'تبارك الذي بيده الملك', 'result' => 'ناجح', 'mistake' => null],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.result', 'ناجح')
            ->assertJsonCount(3, 'data.questions');

        // من قاعدة البيانات (تسلسل JSON يحوّل التاريخ لUTC فيُزيحه يوماً)
        $this->assertSame('2026-07-11', $test->fresh()->exam_date->toDateString());
        // الأسئلة استُبدلت كلياً (لا تراكم) وطالبها طالب الاختبار نفسه
        $this->assertSame(3, WeeklyTestQuestion::where('weekly_test_id', $test->id)->count());
        $this->assertSame(0, WeeklyTestQuestion::where('weekly_test_id', $test->id)->where('result', 'راسب')->count());
        $this->assertSame($test->student_id, WeeklyTestQuestion::where('weekly_test_id', $test->id)->value('student_id'));
    }

    public function test_other_teachers_test_is_forbidden(): void
    {
        [, $test] = $this->makeTest();
        $other = $this->makeTeacher();

        $this->authed($this->loginToken($other))
            ->putJson("/api/weekly-tests/{$test->id}", [
                'exam_date' => '2026-07-11',
                'questions' => [['eighth_start' => 'أي شيء', 'result' => 'ناجح']],
            ])
            ->assertForbidden();

        // لم يتغيّر شيء
        $this->assertSame('راسب', $test->fresh()->result);
        $this->assertSame(2, WeeklyTestQuestion::where('weekly_test_id', $test->id)->count());
    }

    public function test_delete_route_is_gone_405(): void
    {
        [$teacher, $test] = $this->makeTest();

        $this->authed($this->loginToken($teacher))
            ->deleteJson("/api/weekly-tests/{$test->id}")
            ->assertStatus(405); // المسار أُلغي — لا حذف

        $this->assertDatabaseHas('weekly_tests', ['id' => $test->id]);
    }

    public function test_partial_failure_rolls_back_everything(): void
    {
        [$teacher, $test] = $this->makeTest();

        // ثمن ثانٍ بنص أطول من عمود eighth_start (255) → خطأ SQL في منتصف
        // الإدراج بعد حذف الأسئلة القديمة داخل الـtransaction — يجب أن يرجع كل شيء
        $r = $this->authed($this->loginToken($teacher))
            ->putJson("/api/weekly-tests/{$test->id}", [
                'exam_date' => '2026-07-11',
                'questions' => [
                    ['eighth_start' => 'هل جزاء الإحسان', 'result' => 'ناجح'],
                    ['eighth_start' => str_repeat('ثمن', 200), 'result' => 'ناجح'],
                ],
            ]);
        $this->assertGreaterThanOrEqual(500, $r->getStatusCode());

        // الأسئلة القديمة عادت كما كانت والنتيجة لم تتغيّر
        $fresh = $test->fresh();
        $this->assertSame('راسب', $fresh->result);
        $this->assertSame('2026-07-04', $fresh->exam_date->toDateString());
        $this->assertSame(2, WeeklyTestQuestion::where('weekly_test_id', $test->id)->count());
        $this->assertSame(1, WeeklyTestQuestion::where('weekly_test_id', $test->id)->where('result', 'راسب')->count());
    }
}
