<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Attendance;
use App\Models\Memorization;
use App\Models\WeeklyTest;
use App\Services\ReportService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function student(Request $request, $id, ReportService $reports)
    {
        $user = $request->user();
        $student = Student::findOrFail($id);

        if (!$user->isAdmin() && $student->teacher_id !== $user->id) { // التحقق من أن هذا المعلم هو المسؤول عن الطالب لمشاهدة تقريره
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بمشاهدة تقرير هذا الطالب'
            ], 403);
        }

        $month = $request->get('month', now()->month);
        $year  = $request->get('year', now()->year);

        // إعادة استخدام منطق التجميع من ReportService مع الحفاظ على نفس شكل الاستجابة القديم
        $d = $reports->studentData($student, $month, $year);

        return response()->json([
            'success' => true,
            'data' => [
                'student'       => $d['student'],
                'attendances'   => $d['attendances'],
                'memorizations' => $d['memorizations'],
                'tests'         => $d['tests'],
                'presentCount'  => $d['present'],
                'absentCount'   => $d['absent'],
                'lateCount'     => $d['late'],
                'passedTests'   => $d['passed'],
                'failedTests'   => $d['failed'],
                'avgScore'      => $d['passRate'],
                'month'         => $d['month'],
                'year'          => $d['year'],
            ]
        ]);
    }

    /**
     * جاهزية الأوقاف: قائمة الطلاب بلا رقم وطني (يحتاج إكمالها قبل رفع التقارير الرسمية).
     * GET /reports/admin/missing-national-id
     */
    public function missingNationalId(Request $request)
    {
        $students = Student::missingNationalId()
            ->with(['center', 'teacher'])
            ->orderBy('name')
            ->get()
            ->map(function ($s) {
                return [
                    'id'           => $s->id,
                    'name'         => $s->name,
                    'center'       => $s->center->name ?? '—',
                    'teacher'      => $s->teacher->name ?? '—',
                    'guardian_phone' => $s->guardian_phone,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'count'    => $students->count(),
                'students' => $students,
            ],
        ]);
    }

    public function weekly(Request $request)
    {
        $user = $request->user();

        $studentsQuery = Student::where('is_active', true);
        if (!$user->isAdmin()) {
            $studentsQuery->where('teacher_id', $user->id); // تصفية الطلاب لمطابقة معلمهم الحالي في التقرير الأسبوعي
        } else if ($request->has('center_id')) {
            $studentsQuery->where('center_id', $request->center_id);
        }
        $students = $studentsQuery->get();

        $startOfWeek = now()->startOfWeek();
        $endOfWeek   = now()->endOfWeek();

        $data = $students->map(function ($student) use ($startOfWeek, $endOfWeek) {
            $attendances = Attendance::where('student_id', $student->id)
                ->whereBetween('date', [$startOfWeek, $endOfWeek])
                ->get();

            return [
                'student'       => $student,
                'present'       => $attendances->where('status', 'present')->count(),
                'absent'        => $attendances->where('status', 'absent')->count(),
                'late'          => $attendances->where('status', 'late')->count(),
                'memorizations' => Memorization::where('student_id', $student->id)
                    ->whereBetween('date', [$startOfWeek, $endOfWeek])
                    ->count(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'report' => $data,
                'startOfWeek' => $startOfWeek->toDateString(),
                'endOfWeek' => $endOfWeek->toDateString()
            ]
        ]);
    }
}
