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
     * $centerId اختياري: null → كل المحفّظين (الأدمن)، مُمرَّر → محفّظو المركز فقط.
     */
    public function teachersPerformance($month, $year, ?int $centerId = null): array
    {
        $teachers = User::where('role', 'teacher')
            ->when($centerId, fn ($q) => $q->where('center_id', $centerId))
            ->with('center')->get();

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
     * $centerId اختياري: null → كل النظام (سلوك الأدمن كما هو)، مُمرَّر →
     * مركز واحد فقط. تصفية الطلاب الأولى فقط تتغيّر — يبقى العدد 3 استعلامات.
     */
    public function atRiskStudents($month, $year, ?int $centerId = null): array
    {
        $students = Student::where('is_active', true)
            ->when($centerId, fn ($q) => $q->where('center_id', $centerId))
            ->with(['center', 'teacher'])->get();

        // استعلامان مجمّعان للجميع (بدل استعلامين لكل طالب — N+1)
        $attStats = Attendance::whereIn('student_id', $students->pluck('id'))
            ->whereMonth('date', $month)->whereYear('date', $year)
            ->selectRaw('student_id, COUNT(*) AS total, SUM(status = "present") AS present')
            ->groupBy('student_id')
            ->get()->keyBy('student_id');
        $failCounts = WeeklyTest::whereIn('student_id', $students->pluck('id'))
            ->whereMonth('exam_date', $month)->whereYear('exam_date', $year)
            ->where('result', 'راسب')
            ->selectRaw('student_id, COUNT(*) AS c')
            ->groupBy('student_id')
            ->get()->keyBy('student_id');

        $rows = collect();

        foreach ($students as $s) {
            $stat  = $attStats->get($s->id);
            $total = (int) ($stat->total ?? 0);
            $pct = $this->pct((int) ($stat->present ?? 0), $total);

            $failed = (int) ($failCounts->get($s->id)->c ?? 0);

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
     * ملخّص تقدّم الحفظ — مؤشّر «أكمل القرآن» (كل الأجزاء الـ30) ومتوسّط
     * الأجزاء المكتملة. مبنيّ على SurahReference بعد جلب سور الطلاب في
     * استعلام واحد (بلا N+1). $centerId اختياري (null = كل النظام).
     * ملاحظة: «الختمات» ميزة غير مبنية بعد — هذا مؤشّر إتمامٍ حاليّ محسوب،
     * لا عدّاد ختمات تاريخي ولا منسوب لمحفّظ.
     */
    public function progressSummary($month, $year, ?int $centerId = null): array
    {
        $students = Student::where('is_active', true)
            ->when($centerId, fn ($q) => $q->where('center_id', $centerId))
            ->get(['id']);

        $bySurah = Memorization::whereIn('student_id', $students->pluck('id'))
            ->whereNotNull('surah_name')
            ->get(['student_id', 'surah_name'])
            ->groupBy('student_id');

        $completedQuran = 0;
        $sumJuz = 0;
        foreach ($students as $s) {
            $names = ($bySurah[$s->id] ?? collect())->pluck('surah_name')->all();
            $p = \App\Support\SurahReference::progress($names);
            $sumJuz += $p['completed_count'];
            if ($p['completed_count'] === 30) $completedQuran++;
        }

        return [
            'studentsCount'   => $students->count(),
            'completedQuran'  => $completedQuran,                                        // أتمّوا كل القرآن حالياً
            'avgCompletedJuz' => $students->count() ? round($sumJuz / $students->count(), 1) : 0,
        ];
    }

    /**
     * تقارير إدارة المركز (المجموعة 2) — كلها لمركز واحد، مبنية على
     * استعلامات مجمّعة (بلا N+1 مهما كثُر المحفّظون/الطلاب):
     *  - أداء كل محفّظ: عدد طلابه، نسبة حضورهم، متوسّط تقدّم حفظهم، من أكمل القرآن
     *  - توزيع الطلاب (عدد لكل محفّظ) — لكشف الاختلال
     *  - طلاب بلا محفّظ: قائمة + عدد
     *  - ملخّص المركز العام
     */
    public function centerManagement(int $centerId, $month, $year): array
    {
        $teachers = User::where('role', 'teacher')->where('center_id', $centerId)
            ->orderBy('name')->get(['id', 'name', 'type']);
        $students = Student::where('center_id', $centerId)->where('is_active', true)
            ->get(['id', 'name', 'display_code', 'teacher_id']);
        $ids = $students->pluck('id');

        // استعلام مجمّع واحد للحضور + استعلام واحد لسور الحفظ
        $attStats = Attendance::whereIn('student_id', $ids)
            ->whereMonth('date', $month)->whereYear('date', $year)
            ->selectRaw('student_id, COUNT(*) AS total, SUM(status = "present") AS present')
            ->groupBy('student_id')->get()->keyBy('student_id');
        $bySurah = Memorization::whereIn('student_id', $ids)->whereNotNull('surah_name')
            ->get(['student_id', 'surah_name'])->groupBy('student_id');

        // تقدّم كل طالب (مرة واحدة) + تجميع في الذاكرة
        $progressOf = [];
        foreach ($students as $s) {
            $names = ($bySurah[$s->id] ?? collect())->pluck('surah_name')->all();
            $progressOf[$s->id] = \App\Support\SurahReference::progress($names)['completed_count'];
        }

        $byTeacher = $students->groupBy('teacher_id');

        // صف أداء لكل محفّظ
        $teacherRows = $teachers->map(function ($t) use ($byTeacher, $attStats, $progressOf) {
            $mine = $byTeacher->get($t->id, collect());
            $present = 0; $totalAtt = 0; $sumJuz = 0; $completed = 0;
            foreach ($mine as $s) {
                $st = $attStats->get($s->id);
                $present  += (int) ($st->present ?? 0);
                $totalAtt += (int) ($st->total ?? 0);
                $sumJuz   += $progressOf[$s->id];
                if ($progressOf[$s->id] === 30) $completed++;
            }
            return [
                'teacher'          => ['id' => $t->id, 'name' => $t->name, 'type' => $t->type],
                'studentsCount'    => $mine->count(),
                'attendancePercent'=> $this->pct($present, $totalAtt),
                'avgCompletedJuz'  => $mine->count() ? round($sumJuz / $mine->count(), 1) : 0,
                'completedQuran'   => $completed,
            ];
        })->values();

        // طلاب بلا محفّظ
        $noTeacher = $byTeacher->get(null, collect())->map(fn ($s) => [
            'id' => $s->id, 'name' => $s->name, 'display_code' => $s->display_code,
        ])->values();

        // ملخّص المركز
        $totalPresent = $attStats->sum('present');
        $totalAtt = $attStats->sum('total');
        $completedQuran = collect($progressOf)->filter(fn ($c) => $c === 30)->count();

        return [
            'teacherRows'   => $teacherRows,
            'noTeacher'     => ['count' => $noTeacher->count(), 'rows' => $noTeacher],
            'summary'       => [
                'teachersCount'  => $teachers->count(),
                'studentsCount'  => $students->count(),
                'avgAttendance'  => $this->pct((int) $totalPresent, (int) $totalAtt),
                'completedQuran' => $completedQuran,
            ],
            'month' => (int) $month, 'year' => (int) $year,
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
