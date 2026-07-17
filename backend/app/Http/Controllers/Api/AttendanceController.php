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

    /**
     * حفظ الحضور الجماعي — «تكرار مسموح مع تأكيد» (Upsert بتأكيد):
     * القيد الفريد (student_id, date) يضمن سجلاً واحداً لكل طالب في اليوم.
     * تغيير حالةٍ محفوظة (حاضر↔غائب↔متأخر) يحتاج confirm=true صريحاً —
     * وإلا أُرجع 409 مع قائمة التعارضات بلا أي كتابة (الأمان في الطرفين:
     * الواجهة تعرض التأكيد، وقاعدة البيانات تمنع التضاعف بالقيد الفريد).
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'date'        => 'required|date',
            'attendance'  => 'required|array',
            'confirm'     => 'nullable|boolean',
        ]);

        // 1) تثبيت الصفوف المصرّح بها (نفس قواعد الملكية) — جلبة واحدة بلا N+1
        $students = Student::whereIn('id', array_keys($request->attendance))->get()->keyBy('id');
        $rows = []; // student_id => ['status' =>، 'student' =>]
        foreach ($request->attendance as $studentId => $status) {
            if (!in_array($status, ['present', 'absent', 'late'])) {
                continue;
            }
            $student = $students->get((int) $studentId);
            if (!$student) continue;
            if (!$user->isAdmin() && $student->teacher_id !== $user->id) {
                continue; // الطالب ليس من طلاب هذا المعلم
            }
            $rows[$student->id] = ['status' => $status, 'student' => $student];
        }

        // 2) «موجود مسبقاً»: سجل بنفس اليوم بحالة مختلفة عن المُرسلة → تعارض
        //    (نفس الحالة = لا تغيير؛ طالب بلا سجل = يُنشأ بحرية بلا تأكيد)
        $existing = Attendance::where('date', $request->date)
            ->whereIn('student_id', array_keys($rows))
            ->get()
            ->keyBy('student_id');

        $conflicts = [];
        foreach ($rows as $sid => $r) {
            $cur = $existing->get($sid);
            if ($cur && $cur->status !== $r['status']) {
                $conflicts[] = [
                    'student_id'     => $sid,
                    'name'           => $r['student']->name,
                    'current_status' => $cur->status,
                    'new_status'     => $r['status'],
                ];
            }
        }

        if ($conflicts && !$request->boolean('confirm')) {
            return response()->json([
                'success' => false,
                'message' => 'تم تسجيل حضور بعض الطلاب من قبل في هذا اليوم — تغيير الحالة يحتاج تأكيداً',
                'data'    => ['conflicts' => $conflicts],
            ], 409);
        }

        // 3) الكتابة كلها في transaction: تنجح معاً أو تُلغى معاً
        \Illuminate\Support\Facades\DB::transaction(function () use ($rows, $request, $user) {
            foreach ($rows as $sid => $r) {
                Attendance::updateOrCreate(
                    [
                        'student_id' => $sid,
                        'date'       => $request->date,
                    ],
                    [
                        'teacher_id' => $user->isAdmin() ? ($r['student']->teacher_id ?? $user->id) : $user->id,
                        'center_id'  => $r['student']->center_id, // كما في الاستيراد — وإلا فقدت تقاريرُ المراكز الحضورَ اليدوي
                        'status'     => $r['status'],
                    ]
                );
            }
        });

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
