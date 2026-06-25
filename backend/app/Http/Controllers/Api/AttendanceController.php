<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Student;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $date = $request->get('date', today()->toDateString());

        $studentsQuery = Student::where('is_active', true);
        if (!$user->isAdmin()) {
            $studentsQuery->where('teacher_id', $user->id); // تصفية الطلاب لمطابقة معلمهم الحالي في الحضور والغياب
        } else if ($request->has('center_id')) {
            $studentsQuery->where('center_id', $request->center_id);
        }
        $students = $studentsQuery->get();

        $attendancesQuery = Attendance::where('date', $date);
        if (!$user->isAdmin()) {
            $attendancesQuery->where('teacher_id', $user->id);
        }
        $attendances = $attendancesQuery->pluck('status', 'student_id');

        return response()->json([
            'success' => true,
            'data' => [
                'students' => $students,
                'attendances' => $attendances,
                'date' => $date
            ]
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'date'        => 'required|date',
            'attendance'  => 'required|array',
        ]);

        foreach ($request->attendance as $studentId => $status) {
            // Validate that status is valid
            if (!in_array($status, ['present', 'absent', 'late'])) {
                continue;
            }

            // Optional: verify teacher owns student
            $student = Student::find($studentId);
            if (!$student) continue;

            if (!$user->isAdmin() && $student->teacher_id !== $user->id) {
                continue; // Skip unauthorized — الطالب ليس من طلاب هذا المعلم
            }

            Attendance::updateOrCreate(
                [
                    'student_id' => $studentId,
                    'date'       => $request->date,
                ],
                [
                    'teacher_id' => $user->isAdmin() ? ($student->teacher_id ?? $user->id) : $user->id,
                    'status'     => $status,
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'تم حفظ الحضور بنجاح'
        ]);
    }

    public function report(Request $request)
    {
        $user = $request->user();
        $month = $request->get('month', now()->month);
        $year  = $request->get('year', now()->year);

        $studentsQuery = Student::where('is_active', true);
        if (!$user->isAdmin()) {
            $studentsQuery->where('teacher_id', $user->id); // تصفية الطلاب لمطابقة معلمهم الحالي في تقرير الحضور
        } else if ($request->has('center_id')) {
            $studentsQuery->where('center_id', $request->center_id);
        }
        $students = $studentsQuery->get();
        $studentIds = $students->pluck('id');

        $attendances = Attendance::whereIn('student_id', $studentIds)
            ->whereMonth('date', $month)
            ->whereYear('date', $year)
            ->get()
            ->groupBy('student_id');

        // Transform grouped attendances to simplify frontend usage if needed,
        // but we'll return as is so we don't have to change Blade parsing.
        return response()->json([
            'success' => true,
            'data' => [
                'students' => $students,
                'attendances' => $attendances,
                'month' => $month,
                'year' => $year
            ]
        ]);
    }
}
