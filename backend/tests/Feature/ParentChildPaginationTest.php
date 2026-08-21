<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Memorization;
use App\Models\WeeklyTest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * ترقيم سجلات الابن لولي الأمر — GET /api/parent/students/{id}:
 * 5 لكل صفحة بمعاملات مستقلة (memo_page/att_page/tests_page)، الملخّصات تُحسب
 * على كل السجلات لا الصفحة الظاهرة، وفحص الملكية يسبق كل شيء (ابن غيره → 403).
 */
class ParentChildPaginationTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    /** يبذر 7 حفظ + 7 حضور + 6 اختبارات لطالب مربوط بولي الأمر. */
    private function seedChild(): array
    {
        $teacher = $this->makeTeacher();
        $parent  = $this->makeParent();
        $student = $this->makeStudent($teacher, $parent);

        foreach (range(1, 7) as $i) {
            Memorization::create([
                'student_id' => $student->id, 'teacher_id' => $teacher->id,
                'date' => "2026-07-0{$i}", 'surah_name' => 'الناس', 'quality' => 'excellent',
            ]);
            Attendance::create([
                'student_id' => $student->id, 'teacher_id' => $teacher->id,
                'date' => "2026-07-0{$i}", 'status' => $i <= 5 ? 'present' : 'absent',
            ]);
        }
        foreach (range(1, 6) as $i) {
            WeeklyTest::create([
                'student_id' => $student->id, 'teacher_id' => $teacher->id,
                'exam_date' => "2026-07-0{$i}", 'result' => $i === 6 ? 'ناجح' : 'راسب',
            ]);
        }

        return [$parent, $student];
    }

    public function test_records_are_paginated_five_per_page_with_full_totals(): void
    {
        [$parent, $student] = $this->seedChild();

        $r = $this->authed($this->loginToken($parent))
            ->getJson("/api/parent/students/{$student->id}")
            ->assertOk();

        // الصفحة 1: خمسة صفوف لكل سجل + إجمالياته كاملة
        $this->assertCount(5, $r->json('data.memorizations.data'));
        $this->assertSame(7, $r->json('data.memorizations.total'));
        $this->assertSame(2, $r->json('data.memorizations.last_page'));
        $this->assertCount(5, $r->json('data.attendances.data'));
        $this->assertSame(7, $r->json('data.attendances.total'));
        $this->assertCount(5, $r->json('data.weekly_tests.data'));
        $this->assertSame(6, $r->json('data.weekly_tests.total'));

        // الملخّصات على كل السجلات لا الصفحة الظاهرة
        $this->assertSame(7, $r->json('data.attendance_summary.total'));
        $this->assertSame(5, $r->json('data.attendance_summary.present'));
        $this->assertSame(2, $r->json('data.attendance_summary.absent'));
        $this->assertSame(71, $r->json('data.attendance_summary.percent')); // 5/7
        $this->assertSame(6, $r->json('data.tests_summary.total'));
        $this->assertSame('ناجح', $r->json('data.tests_summary.last_result')); // الأحدث (07-06)
    }

    public function test_page_two_is_independent_per_record(): void
    {
        [$parent, $student] = $this->seedChild();
        $token = $this->loginToken($parent);

        // صفحة 2 للحفظ فقط — الحضور يبقى في صفحته الأولى
        $r = $this->authed($token)
            ->getJson("/api/parent/students/{$student->id}?memo_page=2")
            ->assertOk();

        $this->assertCount(2, $r->json('data.memorizations.data')); // 7 = 5 + 2
        $this->assertSame(2, $r->json('data.memorizations.current_page'));
        $this->assertCount(5, $r->json('data.attendances.data'));
        $this->assertSame(1, $r->json('data.attendances.current_page'));

        // صفحة 2 للاختبارات: السادس المتبقي
        $this->app['auth']->forgetGuards();
        $r2 = $this->authed($token)
            ->getJson("/api/parent/students/{$student->id}?tests_page=2")
            ->assertOk();
        $this->assertCount(1, $r2->json('data.weekly_tests.data'));
    }

    public function test_other_parents_child_is_forbidden(): void
    {
        [, $student] = $this->seedChild();
        $stranger = $this->makeParent();

        $this->authed($this->loginToken($stranger))
            ->getJson("/api/parent/students/{$student->id}")
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->authed($this->loginToken($stranger))
            ->getJson("/api/parent/students/{$student->id}?memo_page=2")
            ->assertForbidden(); // الترقيم لا يلتفّ على فحص الملكية
    }
}
