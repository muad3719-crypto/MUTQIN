<?php

namespace App\Services;

use App\Models\User;
use App\Models\Center;
use App\Models\Student;
use App\Models\Attendance;
use App\Models\Memorization;
use App\Models\WeeklyTest;
use App\Support\Percentage;

/**
 * خدمة التقارير — كل منطق التجميع في مكان واحد، يُعاد استخدامه من:
 *  - ReportController (استجابات JSON)
 *  - ReportPdfController (ملفات PDF)
 * بلا تكرار للمنطق.
 */
class ReportService
{
    // عتبات الطلاب المتعثّرين (ثوابت بسيطة قابلة للتعديل)
    const ATTENDANCE_THRESHOLD = 70; // نسبة الحضور التي تحتها يُعتبر متعثّراً
    const FAIL_THRESHOLD       = 2;  // عدد مرات الرسوب التي عندها فأكثر يُعتبر متعثّراً

    // نسبة مئوية آمنة (تفوّض إلى المصدر الموحّد)
    protected function pct(int $part, int $total): int
    {
        return Percentage::of($part, $total);
    }

    /**
     * تقرير طالب واحد لشهر/سنة (نفس تجميع ReportController@student القديم).
     */
    public function studentData(Student $student, $month, $year): array
    {
        $student->loadMissing(['center', 'teacher']);

        $attendances = Attendance::where('student_id', $student->id)
            ->whereMonth('date', $month)->whereYear('date', $year)
            ->orderBy('date')->get();

        $present = $attendances->where('status', 'present')->count();
        $absent  = $attendances->where('status', 'absent')->count();
        $late    = $attendances->where('status', 'late')->count();
        $total   = $present + $absent + $late;

        $memorizations = Memorization::where('student_id', $student->id)
            ->whereMonth('date', $month)->whereYear('date', $year)
            ->orderBy('date')->get();

        $tests = WeeklyTest::where('student_id', $student->id)
            ->whereMonth('exam_date', $month)->whereYear('exam_date', $year)
            ->with('questions')->orderBy('exam_date')->get();

        $passed = $tests->where('result', 'ناجح')->count();
        $failed = $tests->where('result', 'راسب')->count();

        return [
            'student'           => $student,
            'attendances'       => $attendances,
            'memorizations'     => $memorizations,
            'tests'             => $tests,
            'present'           => $present,
            'absent'            => $absent,
            'late'              => $late,
            'total'             => $total,
            'attendancePercent' => $this->pct($present, $total),
            'passed'            => $passed,
            'failed'            => $failed,
            'passRate'          => $this->pct($passed, $tests->count()),
            'month'             => (int) $month,
            'year'              => (int) $year,
        ];
    }

    /**
     * صف ملخّص لطالب (نسبة حضور + نسبة نجاح) — يُستخدم في تقارير المجموعات.
     */
    public function studentSummaryRow(Student $s, $month, $year): array
    {
        $att = Attendance::where('student_id', $s->id)
            ->whereMonth('date', $month)->whereYear('date', $year)->get();
        $p = $att->where('status', 'present')->count();
        $a = $att->where('status', 'absent')->count();
        $l = $att->where('status', 'late')->count();
        $tot = $p + $a + $l;

        $tests = WeeklyTest::where('student_id', $s->id)
            ->whereMonth('exam_date', $month)->whereYear('exam_date', $year)->get();
        $tc = $tests->count();
        $passed = $tests->where('result', 'ناجح')->count();
        $failed = $tests->where('result', 'راسب')->count();

        return [
            'student'           => $s,
            'present'           => $p,
            'absent'            => $a,
            'late'              => $l,
            'attendancePercent' => $this->pct($p, $tot),
            'tests'             => $tc,
            'passed'            => $passed,
            'failed'            => $failed,
            'passRate'          => $this->pct($passed, $tc),
        ];
    }

    /**
     * تقرير مجموعة طلاب معلّم — صف لكل طالب + إجماليات.
     */
    public function teacherGroupData(User $teacher, $month, $year): array
    {
        $students = Student::where('teacher_id', $teacher->id)
            ->where('is_active', true)->with('center')->orderBy('name')->get();

        $rows = $students->map(fn ($s) => $this->studentSummaryRow($s, $month, $year));
        $avgAtt = $rows->count() ? (int) round($rows->avg('attendancePercent')) : 0;
        $avgPass = $rows->count() ? (int) round($rows->avg('passRate')) : 0;

        return [
            'teacher'    => $teacher->loadMissing('center'),
            'rows'       => $rows,
            'studentsCount' => $rows->count(),
            'avgAttendance' => $avgAtt,
            'avgPassRate'   => $avgPass,
            'month'      => (int) $month,
            'year'       => (int) $year,
        ];
    }

    /**
     * بيانات مركز شاملة.
     */
    public function centerData(Center $center, $month, $year): array
    {
        $teachers = User::where('role', 'teacher')->where('center_id', $center->id)->count();
        $students = Student::where('center_id', $center->id)->where('is_active', true)->get();
        $ids = $students->pluck('id');

        $att = Attendance::whereIn('student_id', $ids)
            ->whereMonth('date', $month)->whereYear('date', $year)->get();
        $present = $att->where('status', 'present')->count();

        $tests = WeeklyTest::whereIn('student_id', $ids)
            ->whereMonth('exam_date', $month)->whereYear('exam_date', $year)->get();
        $passed = $tests->where('result', 'ناجح')->count();
        $failed = $tests->where('result', 'راسب')->count();

        return [
            'center'        => $center,
            'teachersCount' => $teachers,
            'studentsCount' => $students->count(),
            'avgAttendance' => $this->pct($present, $att->count()),
            'testsCount'    => $tests->count(),
            'passed'        => $passed,
            'failed'        => $failed,
            'passRate'      => $this->pct($passed, $tests->count()),
            'month'         => (int) $month,
            'year'          => (int) $year,
        ];
    }

    /**
     * كل المراكز (صف لكل مركز).
     */
    public function allCentersData($month, $year): array
    {
        $rows = Center::orderBy('name')->get()->map(fn ($c) => $this->centerData($c, $month, $year));
        return ['rows' => $rows, 'month' => (int) $month, 'year' => (int) $year];
    }

    /**
     * تقرير أداء المعلمين (مرتّب حسب الأداء تنازلياً).
     */
    public function teachersPerformance($month, $year): array
    {
        $teachers = User::where('role', 'teacher')->with('center')->get();

        $rows = $teachers->map(function ($t) use ($month, $year) {
            $students = Student::where('teacher_id', $t->id)->where('is_active', true)->get();
            $ids = $students->pluck('id');

            $att = Attendance::whereIn('student_id', $ids)
                ->whereMonth('date', $month)->whereYear('date', $year)->get();
            $tests = WeeklyTest::whereIn('student_id', $ids)
                ->whereMonth('exam_date', $month)->whereYear('exam_date', $year)->get();
            $passed = $tests->where('result', 'ناجح')->count();

            return [
                'teacher'           => $t,
                'studentsCount'     => $students->count(),
                'attendancePercent' => $this->pct($att->where('status', 'present')->count(), $att->count()),
                'testsCount'        => $tests->count(),
                'passRate'          => $this->pct($passed, $tests->count()),
            ];
        })
        ->sortByDesc(fn ($r) => $r['passRate'] + $r['attendancePercent'] / 2) // الأداء = نجاح + نصف الحضور
        ->values();

        return ['rows' => $rows, 'month' => (int) $month, 'year' => (int) $year];
    }

    /**
     * الطلاب المتعثّرون (حضور منخفض أو رسوب متكرر).
     */
    public function atRiskStudents($month, $year): array
    {
        $students = Student::where('is_active', true)->with(['center', 'teacher'])->get();
        $rows = collect();

        foreach ($students as $s) {
            $att = Attendance::where('student_id', $s->id)
                ->whereMonth('date', $month)->whereYear('date', $year)->get();
            $total = $att->count();
            $pct = $this->pct($att->where('status', 'present')->count(), $total);

            $failed = WeeklyTest::where('student_id', $s->id)
                ->whereMonth('exam_date', $month)->whereYear('exam_date', $year)
                ->where('result', 'راسب')->count();

            $lowAtt = $total > 0 && $pct < self::ATTENDANCE_THRESHOLD;
            $manyFails = $failed >= self::FAIL_THRESHOLD;

            if ($lowAtt || $manyFails) {
                $reasons = [];
                if ($lowAtt) $reasons[] = 'حضور منخفض';
                if ($manyFails) $reasons[] = 'رسوب متكرر';
                $rows->push([
                    'student'           => $s,
                    'attendancePercent' => $pct,
                    'attendanceDays'    => $total,
                    'fails'             => $failed,
                    'reason'            => implode(' + ', $reasons),
                ]);
            }
        }

        $rows = $rows->sortBy('attendancePercent')->values();

        return [
            'rows'          => $rows,
            'month'         => (int) $month,
            'year'          => (int) $year,
            'attThreshold'  => self::ATTENDANCE_THRESHOLD,
            'failThreshold' => self::FAIL_THRESHOLD,
        ];
    }

    /**
     * الإحصاء العام للنظام.
     */
    public function overview($month, $year): array
    {
        $tests = WeeklyTest::whereMonth('exam_date', $month)->whereYear('exam_date', $year)->get();
        $passed = $tests->where('result', 'ناجح')->count();

        $memorizations = Memorization::whereMonth('date', $month)->whereYear('date', $year)->count();

        return [
            'centers'        => Center::count(),
            'teachers'       => User::where('role', 'teacher')->count(),
            'students'       => Student::where('is_active', true)->count(),
            'parents'        => User::where('role', 'parent')->count(),
            'testsCount'     => $tests->count(),
            'passed'         => $passed,
            'failed'         => $tests->where('result', 'راسب')->count(),
            'passRate'       => $this->pct($passed, $tests->count()),
            'memorizations'  => $memorizations,
            'month'          => (int) $month,
            'year'           => (int) $year,
        ];
    }
}
