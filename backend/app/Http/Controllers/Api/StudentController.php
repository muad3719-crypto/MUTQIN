<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\User;
use App\Models\Center;
use App\Models\Attendance;
use App\Models\Memorization;
use App\Support\ArabicText;
use App\Support\ParentResolver;
use App\Support\Percentage;
use App\Support\PhoneNumber;
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
     * قواعد رقم هوية الطالب حسب الجنسية (فريد بين الطلاب دائماً).
     *  - ليبي:   12 رقماً يبدأ بـ1 (ذكر) أو 2 (أنثى) — الصيغة الليبية الحالية.
     *  - أجنبي:  نص حرّ (جواز/إقامة) بحدّ 32 حرفاً، بلا صيغة ليبية.
     * @param int|null $ignoreId معرّف الطالب لتجاهله في فحص التفرّد (عند التعديل)
     */
    protected function studentIdentityRules(string $natType, $ignoreId = null): array
    {
        $unique = 'unique:students,national_id' . ($ignoreId ? ',' . $ignoreId : '');
        $format = $natType === 'foreigner'
            ? ['string', 'max:32']
            : ['digits:12', 'regex:/^[12]\d{11}$/'];
        return array_merge([self::NATIONAL_ID_PRESENCE], $format, [$unique]);
    }

    /**
     * قواعد رقم هوية ولي الأمر حسب الجنسية — بلا قيد unique في التحقّق،
     * لأن التفرّد يُفرض منطقياً عند الإنشاء فقط (وإلا تعذّر ربط الابن الثاني بنفس الرقم).
     */
    protected function parentIdentityRules(string $natType): array
    {
        return $natType === 'foreigner'
            ? ['nullable', 'string', 'max:32']
            : ['nullable', 'digits:12', 'regex:/^[12]\d{11}$/'];
    }

    protected function nationalIdMessages(): array
    {
        return [
            'national_id.digits'   => 'الرقم الوطني يجب أن يكون 12 رقماً',
            'national_id.regex'    => 'الرقم الوطني الليبي يبدأ بـ 1 (ذكر) أو 2 (أنثى) ويتكوّن من 12 رقماً',
            'national_id.unique'   => 'هذا الرقم الوطني مسجّل لطالب آخر',
            'national_id.required' => 'الرقم الوطني مطلوب',
            'age.between'          => 'العمر يجب أن يكون بين 1 و120 سنة',
            'nationality_name.required_if'          => 'اسم الجنسية مطلوب للطالب الأجنبي',
            'guardian_nationality_name.required_if' => 'اسم الجنسية مطلوب لولي الأمر الأجنبي',
            'guardian_id_number.digits' => 'الرقم الوطني لولي الأمر يجب أن يكون 12 رقماً',
            'guardian_id_number.regex'  => 'الرقم الوطني الليبي لولي الأمر يبدأ بـ 1/2 ويتكوّن من 12 رقماً',
        ];
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = Student::with(['center', 'teacher'])->latest();

        if (!$user->isAdmin()) {
            $query->where('teacher_id', $user->id); // تصفية الطلاب حسب المعلم المسجل حالياً بدلاً من المركز
        }

        // فلاتر اختيارية (تُدمج مع AND؛ الفلترة في الـ Backend)
        if ($request->filled('center_id')) {
            $query->where('center_id', (int) $request->center_id);
        }
        if ($request->filled('teacher_id')) {
            $query->where('teacher_id', (int) $request->teacher_id);
        }
        if (in_array($request->input('nationality'), ['libyan', 'foreigner'], true)) {
            $query->where('nationality_type', $request->input('nationality'));
        }
        if ($request->boolean('missing_national_id')) {
            $query->whereNull('national_id'); // جاهزية الأوقاف: بلا رقم وطني
        }

        // بحث حي اختياري q: الاسم (مطبَّعاً — «احمد»=«أحمد») + الرقم الوطني + الهاتف.
        // يتجمّع مع الفلاتر أعلاه بـ AND ويعمل مع الترقيم.
        if (($q = trim((string) $request->get('q', ''))) !== '') {
            $norm = ArabicText::normalize($q);
            // شرطا الرقم/الهاتف فقط لاستعلام يحوي أرقاماً — وإلا صار normalize(نص عربي)
            // فارغاً فطابق LIKE '%%' كلَّ من له هاتف (خلل مطابقة زائفة)
            $digits = PhoneNumber::normalize($q);
            $query->where(function ($w) use ($q, $norm, $digits) {
                $w->whereRaw(ArabicText::sqlNormalize('name') . ' LIKE ?', ['%' . $norm . '%']);
                if ($digits) {
                    $w->orWhere('national_id', 'like', "%{$q}%")
                      ->orWhere('phone', 'like', "%{$digits}%");
                }
            });
        }

        if ($request->has('all') && $request->all == 1) {
            $students = $query->get();
        } else {
            // ترقيم من الخادم (data + current_page/last_page/total ضمن كائن الترقيم)
            $students = $query->paginate(20)->withQueryString();
        }

        return response()->json([
            'success' => true,
            'data' => $students
        ]);
    }

    public function store(Request $request)
    {
        // المسار محميّ للمدير فقط. ينشئ الطالب ويربطه بحساب ولي أمر (role='parent').
        $natType  = $request->input('nationality_type', 'libyan');
        $gNatType = $request->input('guardian_nationality_type', 'libyan');

        $request->validate([
            'name'              => 'required|string|max:255',
            'nationality_type'  => 'nullable|in:libyan,foreigner',
            'nationality_name'  => 'required_if:nationality_type,foreigner|nullable|string|max:100', // اسم الجنسية للأجنبي
            'national_id'       => $this->studentIdentityRules($natType), // اختياري؛ الصيغة الليبية تُفرض للّيبي فقط
            'center_id'         => 'required|exists:centers,id',
            // المعلّم بدور teacher (S3) وينتمي للمركز المختار (لا محفّظ من مركز آخر)
            'teacher_id'        => ['nullable', \Illuminate\Validation\Rule::exists('users', 'id')->where('role', 'teacher')->where('center_id', $request->input('center_id'))],
            'phone'             => 'nullable|string|max:20',
            'age'               => 'nullable|integer|between:1,120',
            // ولي أمر موجود (الوضع B): يجب أن يكون مستخدماً بدور parent
            'parent_id'         => ['nullable', \Illuminate\Validation\Rule::exists('users', 'id')->where('role', 'parent')],
            'guardian_name'     => 'nullable|string|max:255',
            'guardian_email'    => 'nullable|email|max:255',
            'guardian_phone'    => 'nullable|string|max:20',
            'guardian_password' => 'nullable|string|min:6',
            'guardian_nationality_type' => 'nullable|in:libyan,foreigner',
            'guardian_nationality_name' => 'required_if:guardian_nationality_type,foreigner|nullable|string|max:100',
            'guardian_id_number'        => $this->parentIdentityRules($gNatType), // المعرّف الموحّد لولي الأمر
        ], array_merge([
            'name.required'           => 'اسم الطالب مطلوب',
            'center_id.required'      => 'يجب اختيار المركز',
            'teacher_id.exists'       => 'المعلّم المختار غير صالح أو لا ينتمي للمركز المختار',
            'parent_id.exists'        => 'ولي الأمر المختار غير صالح',
            'guardian_email.email'    => 'بريد ولي الأمر غير صحيح',
            'guardian_password.min'   => 'كلمة مرور ولي الأمر يجب أن تكون 6 أحرف على الأقل',
        ], $this->nationalIdMessages()));

        // ترتيب حسم ولي الأمر:
        //  (B) parent_id موجود → ربط مباشر بحساب موجود، بلا إنشاء حساب جديد.
        //  (A) بيانات ولي → المنطق الموحّد ParentResolver (هوية → هاتف مطبَّع → بريد، وإلا إنشاء).
        //  (C) لا شيء → null.
        $parentId = $request->filled('parent_id')
            ? (int) $request->parent_id
            : ParentResolver::resolve([
                'name'             => $request->guardian_name,
                'email'            => $request->guardian_email,
                'phone'            => $request->guardian_phone,
                'password'         => $request->guardian_password,
                'nationality_type' => $gNatType,
                'nationality_name' => $request->guardian_nationality_name,
                'id_number'        => $request->guardian_id_number,
            ])?->id;

        $student = Student::create([
            'name'             => $request->name,
            'national_id'      => $request->national_id ?: null, // اختياري (وطني لو ليبي / جواز لو أجنبي)
            'nationality_type' => $natType,
            'nationality_name' => $natType === 'foreigner' ? $request->nationality_name : null,
            'phone'            => PhoneNumber::normalize($request->phone),
            'age'              => $request->age,
            'center_id'        => $request->center_id,
            'teacher_id'       => $request->teacher_id,
            'parent_id'        => $parentId,
            'guardian_name'    => $request->guardian_name,   // يبقى للعرض فقط
            'guardian_phone'   => PhoneNumber::normalize($request->guardian_phone),  // يبقى للعرض فقط
            'enrollment_date'  => now(),
            'is_active'        => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم إضافة الطالب وربطه بولي أمره بنجاح',
            'data' => $student->load(['center', 'teacher', 'parent'])
        ], 201);
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

        // ملاحظة: كانت تُجلب هنا «المراجعات» (revisions) وتُعاد بلا أي مستهلك في الواجهة —
        // أُزيل الجلبُ الميت (6ج). الجدول والموديل باقيان لبناء الميزة لاحقاً.
        $memorizations  = $student->memorizations()->with('teacher')->latest()->take(10)->get();
        $attendances    = $student->attendances()->with('teacher')->latest()->take(10)->get();
        $weeklyTests     = $student->weeklyTests()->with(['teacher', 'questions'])->latest()->take(10)->get(); // تحميل أجزاء الاختبار (الأثمان) مع الاختبارات الأسبوعية لصفحة تفاصيل الطالب

        return response()->json([
            'success' => true,
            'data' => [
                'student' => $student,
                'memorizations' => $memorizations,
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
            $natType = $request->input('nationality_type', $student->nationality_type ?: 'libyan');
            $request->validate([
                'name' => 'required|string|max:255',
                'nationality_type' => 'nullable|in:libyan,foreigner',
                'nationality_name' => 'required_if:nationality_type,foreigner|nullable|string|max:100',
                'national_id' => $this->studentIdentityRules($natType, $student->id), // يتجاهل الطالب نفسه في فحص التفرّد
                'phone' => 'nullable|string|max:20',
                'age' => 'nullable|integer|between:1,120',
                'center_id' => 'required|exists:centers,id',
                'teacher_id' => ['nullable', \Illuminate\Validation\Rule::exists('users', 'id')->where('role', 'teacher')->where('center_id', $request->input('center_id'))],
            ], array_merge([
                'name.required' => 'اسم الطالب مطلوب',
                'center_id.required' => 'يجب اختيار المركز',
                'teacher_id.exists' => 'المعلّم المختار غير صالح أو لا ينتمي للمركز المختار',
            ], $this->nationalIdMessages()));

            $student->update([
                'name' => $request->name,
                'national_id' => $request->national_id ?: null,
                'nationality_type' => $natType,
                'nationality_name' => $natType === 'foreigner' ? $request->nationality_name : null,
                'phone' => $request->phone,
                'age' => $request->age,
                'center_id' => $request->center_id,
                'teacher_id' => $request->teacher_id,
            ]);
        } else {
            $request->validate([
                'name'           => 'required|string|max:255',
                'phone'          => 'nullable|string|max:20',
                'age'            => 'nullable|integer|between:1,120',
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

        // استعلامان مجمّعان لكل الأبناء (بدل 3 استعلامات لكل ابن — N+1)
        $ids = $students->pluck('id');
        $attStats = Attendance::whereIn('student_id', $ids)
            ->selectRaw('student_id, COUNT(*) AS total, SUM(status = "present") AS present')
            ->groupBy('student_id')
            ->get()
            ->keyBy('student_id');
        $lastMemos = Memorization::whereIn('student_id', $ids)
            ->orderByDesc('date')->orderByDesc('id')
            ->get()
            ->groupBy('student_id')
            ->map(fn ($g) => $g->first());

        $result = $students->map(function ($student) use ($attStats, $lastMemos) {
            $lastMemo = $lastMemos->get($student->id);

            // ملخص الحضور (نسبة الحضور)
            $stat = $attStats->get($student->id);
            $totalAttendance = (int) ($stat->total ?? 0);
            $presentCount = (int) ($stat->present ?? 0);
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

        // سجل الاختبارات الأسبوعية (نموذج الأثمان: نتيجة كلية + سؤال لكل ثمن)
        $weeklyTests = \App\Models\WeeklyTest::with('questions')
            ->where('student_id', $student->id)
            ->latest('exam_date')
            ->get()
            ->map(fn ($t) => [
                'date'            => $t->exam_date,
                'result'          => $t->result,
                'notes'           => $t->notes,
                'questions_count' => $t->questions->count(),
                'passed_count'    => $t->questions->where('result', 'ناجح')->count(),
                'failed_count'    => $t->questions->where('result', 'راسب')->count(),
                'questions'       => $t->questions->map(fn ($q) => [
                    'eighth_start' => $q->eighth_start,
                    'result'       => $q->result,
                    'mistake'      => $q->mistake,
                ])->values(),
            ]);

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
                'weekly_tests' => $weeklyTests,
                'tests_summary' => [
                    'total'       => $weeklyTests->count(),
                    'last_result' => $weeklyTests->first()['result'] ?? null, // الأحدث أولاً
                ],
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
