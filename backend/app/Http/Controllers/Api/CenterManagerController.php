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

    /**
     * سجل الحضور للمراجعة — مرقّم، مضيَّق بطلاب مركزه حصراً (whereHas student
     * center_id من الحساب، لا من المدخلات). فلاتر اختيارية: تاريخ مفرد أو مدى،
     * محفّظ، بحث طالب حي (اسم/كود/رقم وطني)، حالة. المصدر يُشتق من imported_at.
     */
    public function attendanceIndex(Request $request)
    {
        $centerId = $request->user()->center_id;

        $query = Attendance::with(['student:id,name,display_code,national_id', 'teacher:id,name'])
            ->whereHas('student', fn ($s) => $s->where('center_id', $centerId)) // النطاق: طلاب مركزه فقط
            ->latest('date')->latest('id');

        // فلتر التاريخ: مدى (from/to) أو يوم مفرد
        if ($request->filled('from') && $request->filled('to')) {
            $query->whereBetween('date', [$request->from, $request->to]);
        } elseif ($request->filled('date')) {
            $query->whereDate('date', $request->date);
        }

        // المحفّظ (يُتحقق أنه من المركز)
        if ($request->filled('teacher_id')) {
            $query->where('teacher_id', (int) $request->teacher_id)
                  ->whereHas('teacher', fn ($t) => $t->where('center_id', $centerId));
        }

        // الحالة
        if (in_array($request->input('status'), ['present', 'absent', 'late'], true)) {
            $query->where('status', $request->status);
        }

        // بحث الطالب الحي (اسم مطبَّع / كود العرض / رقم وطني) — نفس نمط قوائم الأدمن
        if (($q = trim((string) $request->get('q', ''))) !== '') {
            $norm = ArabicText::normalize($q);
            $query->whereHas('student', function ($s) use ($q, $norm) {
                $s->whereRaw(ArabicText::sqlNormalize('name') . ' LIKE ?', ['%' . $norm . '%'])
                  ->orWhere('display_code', 'like', "%{$q}%")
                  ->orWhere('national_id', 'like', "%{$q}%");
            });
        }

        $page = $query->paginate(20)->withQueryString();

        // تسطيح كل سجل: كود + اسم + محفّظ + تاريخ + حالة + مصدر + من صحّح
        $page->getCollection()->transform(fn ($a) => [
            'id'           => $a->id,
            'display_code' => $a->student?->display_code,
            'student_name' => $a->student?->name,
            'teacher_name' => $a->teacher?->name,
            'date'         => $a->date?->toDateString(), // Y-m-d نظيف (لا طابع زمني)
            'status'       => $a->status,
            'source'       => $a->imported_at ? 'fingerprint' : 'manual', // بصمة / يدوي
            'corrected_at' => $a->corrected_at?->toDateTimeString(),      // متى صُحّح (إن صُحّح)
        ]);

        return response()->json(['success' => true, 'data' => $page]);
    }

    /**
     * تصحيح حالة سجل حضور واحد (حاضر↔غائب↔متأخر) — مضيَّق بطلاب مركزه:
     * السجل الذي طالبه خارج مركز المستخدم → 403 «خارج نطاق صلاحيتك».
     * تحديث سجلٍ واحد بالـ id، فلا يمكن أن يُنشئ صفاً ثانياً (القيد الفريد
     * قائم أصلاً). transaction + تدقيق خفيف (corrected_by/at).
     */
    public function correctAttendance(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:present,absent,late',
        ], [
            'status.required' => 'الحالة مطلوبة',
            'status.in'       => 'الحالة غير صالحة',
        ]);

        $user = $request->user();
        $attendance = Attendance::with('student:id,center_id')->find($id);

        // خارج النطاق (غير موجود أو طالبه من مركز آخر) → 403 موحّد
        if (!$attendance || !$attendance->student || $attendance->student->center_id !== $user->center_id) {
            return response()->json([
                'success' => false,
                'message' => 'خارج نطاق صلاحيتك',
            ], 403);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($attendance, $request, $user) {
            $attendance->update([
                'status'       => $request->status,
                'corrected_by' => $user->id,
                'corrected_at' => now(),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'تم تصحيح حالة الحضور',
            'data'    => ['id' => $attendance->id, 'status' => $attendance->status],
        ]);
    }

    /** شهر/سنة من الطلب (افتراض: الشهر الحالي بتوقيت طرابلس). */
    private function period(Request $request): array
    {
        return [(int) $request->get('month', now()->month), (int) $request->get('year', now()->year)];
    }

    /**
     * المجموعة 1 — تقارير النظام مضيَّقة بمركزه: ملخّص المركز (حضور/اختبارات)،
     * الطلاب المتعثّرون، ومؤشّر تقدّم الحفظ. النطاق مفروض من الحساب (center_id)،
     * لا يُقبل أي مركز من العميل. يُعاد استخدام ReportService الموجود.
     */
    public function reportsSystem(Request $request)
    {
        $centerId = $request->user()->center_id;
        [$month, $year] = $this->period($request);
        $reports = app(\App\Services\ReportService::class);

        $center = \App\Models\Center::find($centerId);

        return response()->json([
            'success' => true,
            'data' => [
                'center'   => $center,
                'summary'  => $reports->centerData($center, $month, $year),          // حضور/اختبارات/نجاح
                'atRisk'   => $reports->atRiskStudents($month, $year, $centerId),     // المتعثّرون (مضيَّق)
                'progress' => $reports->progressSummary($month, $year, $centerId),    // أكمل القرآن + متوسّط الأجزاء
                'month'    => $month,
                'year'     => $year,
            ],
        ]);
    }
}
