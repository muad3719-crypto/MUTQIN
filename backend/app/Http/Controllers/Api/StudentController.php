<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\User;
use App\Models\Center;
use App\Models\Attendance;
use App\Models\Memorization;
use App\Support\ArabicText;
use App\Support\Percentage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StudentController extends Controller
{
    /**
     * حضور الرقم الوطني في التحقق.
     * ⬅️ للتبديل لاحقاً إلى "إلزامي" غيّر هذا السطر فقط من 'nullable' إلى 'required'.
     */
    const NATIONAL_ID_PRESENCE = 'nullable';

    /**
     * قواعد التحقق من الرقم الوطني الليبي (12 رقماً يبدأ بـ1 ذكر أو 2 أنثى).
     * @param int|null $ignoreId معرّف الطالب لتجاهله في فحص التفرّد (عند التعديل)
     */
    protected function nationalIdRules($ignoreId = null): array
    {
        $unique = 'unique:students,national_id' . ($ignoreId ? ',' . $ignoreId : '');
        return [self::NATIONAL_ID_PRESENCE, 'digits:12', 'regex:/^[12]\d{11}$/', $unique];
    }

    protected function nationalIdMessages(): array
    {
        return [
            'national_id.digits'   => 'الرقم الوطني يجب أن يكون 12 رقماً',
            'national_id.regex'    => 'الرقم الوطني الليبي يبدأ بـ 1 (ذكر) أو 2 (أنثى) ويتكوّن من 12 رقماً',
            'national_id.unique'   => 'هذا الرقم الوطني مسجّل لطالب آخر',
            'national_id.required' => 'الرقم الوطني مطلوب',
        ];
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = Student::with(['center', 'teacher'])->latest();

        if (!$user->isAdmin()) {
            $query->where('teacher_id', $user->id); // تصفية الطلاب حسب المعلم المسجل حالياً بدلاً من المركز
        }

        if ($request->has('all') && $request->all == 1) {
            $students = $query->get();
        } else {
            $limit = $user->isAdmin() ? 15 : 10;
            $students = $query->paginate($limit);
        }

        return response()->json([
            'success' => true,
            'data' => $students
        ]);
    }

    public function store(Request $request)
    {
        // المسار محميّ للمدير فقط. ينشئ الطالب ويربطه بحساب ولي أمر (role='parent').
        $request->validate([
            'name'             => 'required|string|max:255',
            'national_id'      => $this->nationalIdRules(),     // اختياري الآن، يُتحقّق منه عند وجوده فقط
            'center_id'        => 'required|exists:centers,id',
            // يجب أن يكون المعلّم فعلاً بدور teacher (لا ولي أمر/مدير) حتى لا تنكسر صلاحيات «طلابه فقط»
            'teacher_id'       => ['nullable', \Illuminate\Validation\Rule::exists('users', 'id')->where('role', 'teacher')],
            'phone'            => 'nullable|string|max:20',
            'age'              => 'nullable|integer',
            // ولي أمر موجود (الوضع B): يجب أن يكون مستخدماً بدور parent
            'parent_id'        => ['nullable', \Illuminate\Validation\Rule::exists('users', 'id')->where('role', 'parent')],
            'guardian_name'    => 'nullable|string|max:255',
            'guardian_email'   => 'nullable|email|max:255',
            'guardian_phone'   => 'nullable|string|max:20',
            'guardian_password'=> 'nullable|string|min:6',
        ], array_merge([
            'name.required'           => 'اسم الطالب مطلوب',
            'center_id.required'      => 'يجب اختيار المركز',
            'teacher_id.exists'       => 'المعلّم المختار غير صالح',
            'parent_id.exists'        => 'ولي الأمر المختار غير صالح',
            'guardian_email.email'    => 'بريد ولي الأمر غير صحيح',
            'guardian_password.min'   => 'كلمة مرور ولي الأمر يجب أن تكون 6 أحرف على الأقل',
        ], $this->nationalIdMessages()));

        // ترتيب حسم ولي الأمر:
        //  (B) parent_id موجود → ربط مباشر بحساب موجود، بلا إنشاء حساب جديد.
        //  (A) بيانات ولي (اسم/هاتف/بريد) → مطابقة بالهاتف ثم إعادة استخدام/إنشاء.
        //  (C) لا شيء → parent_id = null.
        if ($request->filled('parent_id')) {
            $parentId = (int) $request->parent_id;
        } elseif ($request->filled('guardian_phone') || $request->filled('guardian_email') || $request->filled('guardian_name')) {
            $parentId = $this->resolveParentAccount($request)->id;
        } else {
            $parentId = null;
        }

        $student = Student::create([
            'name'            => $request->name,
            'national_id'     => $request->national_id ?: null, // اختياري
            'phone'           => $request->phone,
            'age'             => $request->age,
            'center_id'       => $request->center_id,
            'teacher_id'      => $request->teacher_id,
            'parent_id'       => $parentId,
            'guardian_name'   => $request->guardian_name,   // يبقى للعرض فقط
            'guardian_phone'  => $request->guardian_phone,  // يبقى للعرض فقط
            'enrollment_date' => now(),
            'is_active'       => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم إضافة الطالب وربطه بولي أمره بنجاح',
            'data' => $student->load(['center', 'teacher', 'parent'])
        ], 201);
    }

    /**
     * إيجاد حساب ولي الأمر بالمطابقة بالهاتف أولاً (شخص واحد ↔ عدة أبناء = حساب واحد)،
     * ثم بالبريد، وإلا إنشاء حساب جديد (يتطلب بريداً). كلمة المرور الافتراضية 'password'.
     */
    protected function resolveParentAccount(Request $request): User
    {
        $phone = $request->guardian_phone;
        $email = $request->guardian_email;

        // 1) المطابقة بالهاتف (الهاتف مُعرّف فريد للشخص)
        $parent = null;
        if ($phone) {
            $parent = User::where('role', 'parent')->where('phone', $phone)->first();
        }
        // 2) ثم بالبريد إن لم نجد بالهاتف — مقيّد بدور parent حتى لا نطابق/نعدّل حساب مدير أو معلم
        if (!$parent && $email) {
            $parent = User::where('role', 'parent')->where('email', $email)->first();
        }

        if ($parent) {
            $parent->fill([
                'name'  => $request->guardian_name ?: $parent->name,
                'phone' => $phone ?: $parent->phone,
            ])->save();
            return $parent;
        }

        // 3) إنشاء حساب جديد — يلزم بريد فريد
        $request->validate(
            ['guardian_email' => 'required|email|unique:users,email'],
            ['guardian_email.required' => 'بريد ولي الأمر مطلوب لإنشاء حساب جديد له']
        );

        // كلمة المرور: المُدخلة إن وُجدت، وإلا عشوائية قوية (لا «password» الثابتة).
        // الحساب الجديد بلا كلمة مرور معروفة يحتاج لاحقاً تدفّق «نسيت كلمة المرور» (راجع الميزات الناقصة).
        return User::create([
            'name'     => $request->guardian_name ?: 'ولي أمر',
            'email'    => $email,
            'phone'    => $phone,
            'role'     => 'parent',
            'password' => Hash::make($request->guardian_password ?: Str::random(24)),
        ]);
    }

    /**
     * بحث أولياء الأمور (admin فقط) — لاختيار ولي أمر موجود عند إضافة طالب.
     * يطابق الاسم (متجاهلاً التشكيل) أو الهاتف (تطابق جزئي). GET /api/parents/search?q=
     */
    public function searchParents(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $qName = ArabicText::stripTashkeel($q); // أسماء القاعدة بلا تشكيل أصلاً

        $parents = User::where('role', 'parent')
            ->where(function ($w) use ($q, $qName) {
                $w->where('phone', 'like', "%{$q}%")
                  ->orWhere('name', 'like', "%{$qName}%");
            })
            ->withCount('children')
            ->orderBy('name')
            ->limit(10)
            ->get()
            ->map(fn ($p) => [
                'id'             => $p->id,
                'name'           => $p->name,
                'phone'          => $p->phone,
                'email'          => $p->email,
                'children_count' => $p->children_count,
            ]);

        return response()->json(['success' => true, 'data' => $parents]);
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();
        $student = Student::with(['center', 'teacher'])->findOrFail($id);

        if (!$user->isAdmin() && $student->teacher_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بالوصول لهذا الطالب'
            ], 403);
        }

        $memorizations  = $student->memorizations()->with('teacher')->latest()->take(10)->get();
        $revisions      = $student->revisions()->with('teacher')->latest()->take(10)->get();
        $attendances    = $student->attendances()->with('teacher')->latest()->take(10)->get();
        $weeklyTests     = $student->weeklyTests()->with(['teacher', 'questions'])->latest()->take(10)->get(); // تحميل أجزاء الاختبار (الأثمان) مع الاختبارات الأسبوعية لصفحة تفاصيل الطالب

        return response()->json([
            'success' => true,
            'data' => [
                'student' => $student,
                'memorizations' => $memorizations,
                'revisions' => $revisions,
                'attendances' => $attendances,
                'weeklyTests' => $weeklyTests
            ]
        ]);
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();
        $student = Student::findOrFail($id);

        if (!$user->isAdmin() && $student->teacher_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بتعديل هذا الطالب'
            ], 403);
        }

        if ($user->isAdmin()) {
            $request->validate([
                'name' => 'required|string|max:255',
                'national_id' => $this->nationalIdRules($student->id), // يتجاهل الطالب نفسه في فحص التفرّد
                'phone' => 'nullable|string|max:20',
                'age' => 'nullable|integer',
                'center_id' => 'required|exists:centers,id',
                'teacher_id' => ['nullable', \Illuminate\Validation\Rule::exists('users', 'id')->where('role', 'teacher')],
            ], array_merge([
                'name.required' => 'اسم الطالب مطلوب',
                'center_id.required' => 'يجب اختيار المركز',
                'teacher_id.exists' => 'المعلّم المختار غير صالح',
            ], $this->nationalIdMessages()));

            $student->update([
                'name' => $request->name,
                'national_id' => $request->national_id ?: null,
                'phone' => $request->phone,
                'age' => $request->age,
                'center_id' => $request->center_id,
                'teacher_id' => $request->teacher_id,
            ]);
        } else {
            $request->validate([
                'name'           => 'required|string|max:255',
                'phone'          => 'nullable|string|max:20',
                'age'            => 'nullable|integer',
                'guardian_name'  => 'nullable|string|max:255',
                'guardian_phone' => 'nullable|string|max:20',
            ], [
                'name.required' => 'اسم الطالب مطلوب',
            ]);

            $student->update([
                'name'           => $request->name,
                'phone'          => $request->phone,
                'age'            => $request->age,
                'guardian_name'  => $request->guardian_name,
                'guardian_phone' => $request->guardian_phone,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث بيانات الطالب بنجاح',
            'data' => $student
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $student = Student::findOrFail($id);

        if (!$user->isAdmin() && $student->teacher_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بحذف هذا الطالب'
            ], 403);
        }

        $student->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم حذف الطالب بنجاح'
        ]);
    }

    // =============================================
    // مسارات ولي الأمر — Parent Routes
    // =============================================

    /**
     * جلب أبناء ولي الأمر مع ملخص البيانات
     * (الربط عبر students.parent_id = معرّف ولي الأمر الحالي)
     */
    public function parentChildren(Request $request)
    {
        $parentId = $request->user()->id;

        $students = Student::where('parent_id', $parentId)
            ->with(['center', 'teacher'])
            ->get();

        $result = $students->map(function ($student) {
            // آخر سورة محفوظة
            $lastMemo = Memorization::where('student_id', $student->id)
                ->latest('date')
                ->first();

            // ملخص الحضور (نسبة الحضور)
            $totalAttendance = Attendance::where('student_id', $student->id)->count();
            $presentCount = Attendance::where('student_id', $student->id)
                ->where('status', 'present')
                ->count();
            $attendancePercent = Percentage::of($presentCount, $totalAttendance);

            return [
                'id' => $student->id,
                'name' => $student->name,
                'age' => $student->age,
                'center' => $student->center ? $student->center->name : '--',
                'center_city' => $student->center ? $student->center->city : '--',
                'teacher_name' => $student->teacher ? $student->teacher->name : '--',
                'last_surah' => $lastMemo ? $lastMemo->surah_name : '--',
                'last_memo_date' => $lastMemo ? $lastMemo->date : null,
                'attendance_percent' => $attendancePercent,
                'total_attendance_days' => $totalAttendance,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $result
        ]);
    }

    /**
     * جلب تفاصيل طالب واحد لولي الأمر
     */
    public function parentStudentDetails(Request $request, $id)
    {
        $student = Student::with(['center', 'teacher'])->findOrFail($id);

        // التحقق من أن الطالب من أبناء ولي الأمر الحالي
        if ($student->parent_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'هذا الطالب غير مسجل تحت ولايتك'
            ], 403);
        }

        // سجل الحفظ الكامل
        $memorizations = Memorization::where('student_id', $student->id)
            ->latest('date')
            ->get()
            ->map(function ($m) {
                return [
                    'surah_name' => $m->surah_name,
                    'quality' => $m->quality,
                    'date' => $m->date,
                    'notes' => $m->notes,
                    'page_from' => $m->page_from,
                    'page_to' => $m->page_to,
                ];
            });

        // سجل الحضور الكامل
        $attendances = Attendance::where('student_id', $student->id)
            ->latest('date')
            ->get()
            ->map(function ($a) {
                $statusMap = [
                    'present' => 'حاضر',
                    'absent' => 'غائب',
                    'late' => 'متأخر',
                ];
                $dayNames = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

                return [
                    'date' => $a->date,
                    'day' => $dayNames[\Carbon\Carbon::parse($a->date)->dayOfWeek] ?? '--',
                    'status' => $statusMap[$a->status] ?? $a->status,
                    'status_raw' => $a->status,
                    'notes' => $a->notes,
                ];
            });

        // ملخص الحضور
        $totalAttendance = $attendances->count();
        $presentCount = $attendances->where('status_raw', 'present')->count();
        $attendancePercent = Percentage::of($presentCount, $totalAttendance);

        return response()->json([
            'success' => true,
            'data' => [
                'student' => [
                    'id' => $student->id,
                    'name' => $student->name,
                    'age' => $student->age,
                    'phone' => $student->phone,
                    'guardian_name' => $student->guardian_name,
                    'guardian_phone' => $student->guardian_phone,
                    'center' => $student->center ? $student->center->name : '--',
                    'center_city' => $student->center ? $student->center->city : '--',
                    'teacher_name' => $student->teacher ? $student->teacher->name : '--',
                    'enrollment_date' => $student->enrollment_date,
                ],
                'memorizations' => $memorizations,
                'attendances' => $attendances,
                'attendance_summary' => [
                    'total' => $totalAttendance,
                    'present' => $presentCount,
                    'absent' => $attendances->where('status_raw', 'absent')->count(),
                    'late' => $attendances->where('status_raw', 'late')->count(),
                    'percent' => $attendancePercent,
                ],
            ]
        ]);
    }
}
