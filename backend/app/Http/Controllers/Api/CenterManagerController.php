<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Student;
use App\Models\StudentRequest;
use App\Models\User;
use App\Support\ArabicText;
use App\Support\PhoneNumber;
use App\Support\PrimaryTeacherRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * «مدير المركز» — كل العمليات مضيَّقة بمركزه حصراً (center_id من حسابه،
 * لا يُقبل من المدخلات أبداً). الحذف والإنشاء يبقيان لمدير النظام.
 */
class CenterManagerController extends Controller
{
    /** لوحة مدير المركز: إحصاءات مركزه فقط. */
    public function dashboard(Request $request)
    {
        $centerId = $request->user()->center_id;
        $studentIds = Student::where('center_id', $centerId)->pluck('id');

        return response()->json([
            'success' => true,
            'data' => [
                'center' => \App\Models\Center::find($centerId, ['id', 'name', 'city']),
                'stats' => [
                    'total_students'   => Student::where('center_id', $centerId)->where('is_active', true)->count(),
                    // عدّاد بارز: طلاب بلا محفّظ (بعد نقل/حذف محفّظ) — يحتاجون إعادة توزيع
                    'students_without_teacher' => Student::where('center_id', $centerId)->where('is_active', true)->whereNull('teacher_id')->count(),
                    'total_teachers'   => User::where('role', 'teacher')->where('center_id', $centerId)->count(),
                    'today_present'    => Attendance::whereIn('student_id', $studentIds)->where('date', today())->where('status', 'present')->count(),
                    'today_absent'     => Attendance::whereIn('student_id', $studentIds)->where('date', today())->where('status', 'absent')->count(),
                    'pending_requests' => StudentRequest::where('status', 'pending')
                        ->where('type', 'transfer')
                        ->where('from_center_id', $centerId)
                        ->where('target_center_id', $centerId)
                        ->count(),
                ],
            ],
        ]);
    }

    /** محفّظو مركزه فقط — مرقّم + بحث مطبَّع (نفس نمط قائمة المدير). */
    public function teachers(Request $request)
    {
        $query = User::where('role', 'teacher')
            ->where('center_id', $request->user()->center_id)
            ->withCount('students')
            ->latest();

        if (($q = trim((string) $request->get('q', ''))) !== '') {
            $norm = ArabicText::normalize($q);
            $query->whereRaw(ArabicText::sqlNormalize('name') . ' LIKE ?', ['%' . $norm . '%']);
        }

        return response()->json([
            'success' => true,
            'data' => $request->has('all') && $request->all == 1
                ? $query->get()
                : $query->paginate(20)->withQueryString(),
        ]);
    }

    /** تفاصيل محفّظ من مركزه فقط (نفس حمولة نافذة «تفاصيل» عند المدير). */
    public function showTeacher(Request $request, $id)
    {
        $teacher = User::where('role', 'teacher')
            ->where('center_id', $request->user()->center_id) // خارج مركزه → 404
            ->with('center:id,name')
            ->withCount('students')
            ->findOrFail($id);

        $students = $teacher->students()->orderBy('name')->get(['id', 'name', 'display_code', 'age']);

        return response()->json([
            'success' => true,
            'data' => [
                'id'             => $teacher->id,
                'name'           => $teacher->name,
                'email'          => $teacher->email,
                'phone'          => $teacher->phone,
                'type'           => $teacher->type,
                'center_name'    => $teacher->center->name ?? null,
                'students_count' => $teacher->students_count,
                'students'       => $students,
            ],
        ]);
    }

    /**
     * تعديل بيانات محفّظ من مركزه (لا حذف — الحذف لمدير النظام، ولا نقل مركز
     * من هنا — النقل عبر سير نقل المحفّظين). يحترم قاعدة الأساسي الواحد.
     */
    public function updateTeacher(Request $request, $id)
    {
        $manager = $request->user();
        $teacher = User::where('role', 'teacher')
            ->where('center_id', $manager->center_id) // خارج مركزه → 404
            ->findOrFail($id);

        $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $teacher->id,
            'phone' => 'nullable|string|max:20',
            'type'  => 'required|in:محفظ أساسي,محفظ معاون',
        ], [
            'name.required'  => 'اسم المحفظ مطلوب',
            'email.required' => 'البريد الإلكتروني مطلوب',
            'email.unique'   => 'هذا البريد مستخدم مسبقاً',
            'type.required'  => 'يجب تحديد نوع المحفظ',
        ]);

        // قاعدة: محفّظ أساسي واحد لكل مركز (المصدر الموحّد)
        PrimaryTeacherRule::assert($manager->center_id, $request->type, $teacher->id);

        $data = [
            'name'  => $request->name,
            'email' => $request->email,
            'phone' => PhoneNumber::normalize($request->phone),
            'type'  => $request->type,
            // center_id لا يتغيّر من هنا عمداً
        ];

        if ($request->filled('password')) {
            $request->validate(['password' => 'min:6|confirmed']);
            $data['password'] = Hash::make($request->password);
        }

        $teacher->update($data);

        if ($request->filled('password')) {
            $teacher->recordPasswordChange('admin'); // تغيير إداري (مدير المركز)
        }

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث بيانات المحفظ بنجاح',
            'data' => $teacher,
        ]);
    }
}
