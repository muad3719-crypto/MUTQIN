<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WeeklyTest;
use App\Models\WeeklyTestQuestion; // استيراد كائن أسئلة الاختبار
use App\Models\Student;
use App\Notifications\InAppNotification;
use Illuminate\Http\Request;

class WeeklyTestController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = WeeklyTest::with(['student', 'questions'])->latest(); // تحميل تفاصيل الأثمان المختبرة مع نتائج الاختبارات
        if (!$user->isAdmin()) {
            $query->where('teacher_id', $user->id);
        } else if ($request->has('teacher_id')) {
            $query->where('teacher_id', $request->teacher_id);
        }

        $tests = $query->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $tests
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'student_id'        => 'required|exists:students,id',
            'exam_date'         => 'required|date',
            'questions'         => 'required|array|min:1',
            'questions.*.eighth_start' => 'required|string',
            'questions.*.result'       => 'required|in:ناجح,راسب',
            'questions.*.mistake'      => 'nullable|string',
        ], [
            'student_id.required'             => 'اختر الطالب',
            'exam_date.required'              => 'اختر تاريخ الاختبار',
            'questions.required'              => 'يجب إضافة ثمن واحد على الأقل للاختبار',
            'questions.*.eighth_start.required' => 'يجب تحديد بداية الثمن المختبر',
            'questions.*.result.required'     => 'يجب تحديد نتيجة كل ثمن',
        ]);

        $student = Student::findOrFail($request->student_id);

        if (!$user->isAdmin() && $student->teacher_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بإضافة اختبار لهذا الطالب'
            ], 403);
        }

        // احتساب النتيجة الكلية للاختبار: راسب إذا كان هناك ثمن واحد على الأقل راسب
        $overallResult = collect($request->questions)->contains('result', 'راسب') ? 'راسب' : 'ناجح';

        // معاملة واحدة: لا يُحفظ اختبار بلا أسئلته إن فشلت الكتابة في المنتصف
        $test = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $user, $overallResult) {
            $test = WeeklyTest::create([
                'student_id'  => $request->student_id,
                'teacher_id'  => $user->id,
                'exam_date'   => $request->exam_date,
                'result'      => $overallResult,
            ]);

            // حفظ تفاصيل كل ثمن تم اختباره
            foreach ($request->questions as $q) {
                WeeklyTestQuestion::create([
                    'weekly_test_id' => $test->id,
                    'student_id'     => $request->student_id,
                    'eighth_start'   => $q['eighth_start'],
                    'result'         => $q['result'],
                    'mistake'        => $q['mistake'] ?? null,
                ]);
            }

            return $test;
        });

        // إشعار ولي أمر الطالب (إن وُجد) — ثانوي، لا يُسقط التسجيل
        InAppNotification::sendSafe(
            $student->parent,
            'test_added',
            'اختبار أسبوعي جديد',
            'تم تسجيل اختبار أسبوعي لابنك «' . $student->name . '» — النتيجة: ' . $overallResult . '.',
            $test->id,
            'parent/child.html?id=' . $student->id
        );

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل الاختبار الأسبوعي بنجاح بمستوى الأثمان',
            'data' => $test->load('questions')
        ], 201);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $test = WeeklyTest::findOrFail($id);
        $student = Student::findOrFail($test->student_id);

        if (!$user->isAdmin() && $student->teacher_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بحذف هذا الاختبار'
            ], 403);
        }

        $test->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم الحذف بنجاح'
        ]);
    }

    // عرض تفاصيل اختبار أسبوعي محدد مع تفاصيل الأثمان والطلاب
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $test = WeeklyTest::with(['student', 'questions', 'teacher'])->findOrFail($id);

        if (!$user->isAdmin() && $test->student->teacher_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بمشاهدة تفاصيل هذا الاختبار'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $test
        ]);
    }
}
