<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StudentRequest;
use App\Models\Student;
use App\Models\User;
use App\Support\ArabicText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * نظام طلبات الطلاب — المحفّظ يُنشئ طلباً (إضافة طالب جديد أو نقل طالب موجود إليه)،
 * والأدمن وحده يوافق فيُنفَّذ التغيير فعلياً على جدول students.
 *
 * مسارات المحفّظ: store / index / searchStudents  (مجموعة teacher)
 * مسارات الأدمن:  adminIndex / approve / reject     (مجموعة admin)
 */
class StudentRequestController extends Controller
{
    /** قواعد الرقم الوطني الليبي (نفس قاعدة StudentController) — مطلوب هنا للتحقّق. */
    protected function nationalIdRules(): array
    {
        return ['required', 'digits:12', 'regex:/^[12]\d{11}$/'];
    }

    protected function nationalIdMessages(): array
    {
        return [
            'national_id.required' => 'الرقم الوطني مطلوب للتحقّق',
            'national_id.digits'   => 'الرقم الوطني يجب أن يكون 12 رقماً',
            'national_id.regex'    => 'الرقم الوطني الليبي يبدأ بـ 1 (ذكر) أو 2 (أنثى) ويتكوّن من 12 رقماً',
        ];
    }

    // =============================================
    // مسارات المحفّظ — Teacher Routes
    // =============================================

    /**
     * إنشاء طلب (إضافة / نقل). مقدّم الطلب يجب أن يكون محفّظاً (لا أدمن — الأدمن يضيف مباشرة).
     * وجهة الطلب دائماً = المحفّظ نفسه ومركزه (يطلب الطالب «إليه»).
     */
    public function store(Request $request)
    {
        $user = $request->user();

        // الأمان: مقدّم الطلب محفّظ فقط (الأدمن يمرّ من حارس teacher لكنه يضيف الطلاب مباشرة)
        if (!$user->isTeacher()) {
            return response()->json([
                'success' => false,
                'message' => 'إنشاء الطلبات متاح للمحفّظين فقط',
            ], 403);
        }
        if (!$user->center_id) {
            return response()->json([
                'success' => false,
                'message' => 'حسابك غير مرتبط بمركز — تواصل مع الأدمن',
            ], 422);
        }

        $type = $request->input('type', 'add');

        if ($type === 'transfer') {
            return $this->storeTransfer($request, $user);
        }

        return $this->storeAdd($request, $user);
    }

    /**
     * طلب إضافة طالب جديد. يتحقّق بالرقم الوطني:
     *  - غير موجود → ينشئ طلب add (pending).
     *  - موجود → لا ينشئ طلباً، يرجع exists=true + مركز/محفّظ الطالب الحالي + اقتراح النقل.
     */
    protected function storeAdd(Request $request, User $user)
    {
        $request->validate(array_merge([
            'name'           => 'required|string|max:255',
            'national_id'    => $this->nationalIdRules(),
            'age'            => 'nullable|integer',
            'phone'          => 'nullable|string|max:20',
            'guardian_name'  => 'nullable|string|max:255',
            'guardian_phone' => 'nullable|string|max:20',
            'guardian_email' => 'nullable|email|max:255',
        ], []), array_merge([
            'name.required' => 'اسم الطالب مطلوب',
        ], $this->nationalIdMessages()));

        // التحقّق بالرقم الوطني فقط (فريد ورسمي)
        $existing = Student::with(['center', 'teacher'])
            ->where('national_id', $request->national_id)
            ->first();

        if ($existing) {
            // الطالب موجود → لا نُنشئ طلب إضافة. نقترح النقل.
            $fromCenter  = $existing->center->name ?? '—';
            $fromTeacher = $existing->teacher->name ?? 'غير محدّد';
            return response()->json([
                'success' => true,
                'data' => [
                    'exists'        => true,
                    'student_id'    => $existing->id,
                    'student_name'  => $existing->name,
                    'from_center'   => $fromCenter,
                    'from_teacher'  => $fromTeacher,
                    'target_center' => $user->center->name ?? '—',
                ],
                'message' => "هذا الطالب موجود في مركز [{$fromCenter}] مع المحفّظ [{$fromTeacher}]. "
                    . "هل تريد طلب نقله إلى مركزك [" . ($user->center->name ?? '—') . "]؟",
            ]);
        }

        // منع تكرار طلب إضافة معلّق بنفس الرقم الوطني من نفس المحفّظ
        $dup = StudentRequest::where('type', 'add')
            ->where('status', 'pending')
            ->where('national_id', $request->national_id)
            ->where('requested_by', $user->id)
            ->exists();
        if ($dup) {
            return response()->json([
                'success' => false,
                'message' => 'لديك طلب إضافة معلّق بنفس الرقم الوطني قيد المراجعة',
            ], 422);
        }

        $req = StudentRequest::create([
            'type'              => 'add',
            'status'            => 'pending',
            'requested_by'      => $user->id,
            'target_center_id'  => $user->center_id,
            'target_teacher_id' => $user->id,
            'national_id'       => $request->national_id,
            'student_name'      => $request->name,
            'age'               => $request->age,
            'phone'             => $request->phone,
            'guardian_name'     => $request->guardian_name,
            'guardian_phone'    => $request->guardian_phone,
            'guardian_email'    => $request->guardian_email,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال الطلب للأدمن، سيُضاف الطالب بعد الموافقة',
            'data'    => ['exists' => false, 'request' => $req],
        ], 201);
    }

    /**
     * طلب نقل طالب موجود إلى المحفّظ مقدّم الطلب (داخل المركز أو عبر المراكز).
     */
    protected function storeTransfer(Request $request, User $user)
    {
        $request->validate([
            'student_id' => 'required|exists:students,id',
        ], [
            'student_id.required' => 'يجب تحديد الطالب المراد نقله',
            'student_id.exists'   => 'الطالب المحدّد غير موجود',
        ]);

        $student = Student::findOrFail($request->student_id);

        // الطالب أصلاً عند هذا المحفّظ — لا داعي للنقل
        if ($student->teacher_id === $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'هذا الطالب مسجّل لديك بالفعل',
            ], 422);
        }

        // منع تكرار طلب نقل معلّق لنفس الطالب من نفس المحفّظ
        $dup = StudentRequest::where('type', 'transfer')
            ->where('status', 'pending')
            ->where('student_id', $student->id)
            ->where('requested_by', $user->id)
            ->exists();
        if ($dup) {
            return response()->json([
                'success' => false,
                'message' => 'لديك طلب نقل معلّق لهذا الطالب قيد المراجعة',
            ], 422);
        }

        $req = StudentRequest::create([
            'type'              => 'transfer',
            'status'            => 'pending',
            'requested_by'      => $user->id,
            'student_id'        => $student->id,
            'national_id'       => $student->national_id,
            'student_name'      => $student->name,
            'from_center_id'    => $student->center_id,
            'from_teacher_id'   => $student->teacher_id,
            'target_center_id'  => $user->center_id,
            'target_teacher_id' => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال طلب النقل للأدمن، سيُنفَّذ بعد الموافقة',
            'data'    => ['request' => $req],
        ], 201);
    }

    /**
     * بحث الطلاب لإنشاء طلب نقل — عبر كل المراكز (بالاسم أو الرقم الوطني).
     * حقول مختصرة فقط (لا بيانات حسّاسة كاملة). متاح للمحفّظ والأدمن.
     */
    public function searchStudents(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $qName = ArabicText::stripTashkeel($q);

        $students = Student::with(['center', 'teacher'])
            ->where(function ($w) use ($q, $qName) {
                $w->where('national_id', 'like', "%{$q}%")
                  ->orWhere('name', 'like', "%{$qName}%");
            })
            ->orderBy('name')
            ->limit(10)
            ->get()
            ->map(fn ($s) => [
                'id'           => $s->id,
                'name'         => $s->name,
                'national_id'  => $s->national_id,
                'center_name'  => $s->center->name ?? '—',
                'teacher_name' => $s->teacher->name ?? 'غير محدّد',
            ]);

        return response()->json(['success' => true, 'data' => $students]);
    }

    /**
     * طلبات المحفّظ الحالي (قراءة فقط) — صفحة «طلباتي».
     */
    public function index(Request $request)
    {
        $requests = StudentRequest::with(['fromCenter', 'fromTeacher', 'targetCenter'])
            ->where('requested_by', $request->user()->id)
            ->latest()
            ->get()
            ->map(fn ($r) => $this->present($r));

        return response()->json(['success' => true, 'data' => $requests]);
    }

    // =============================================
    // مسارات الأدمن — Admin Routes
    // =============================================

    /**
     * كل الطلبات للأدمن (افتراضياً المعلّقة؛ ?status=all لكل الحالات).
     */
    public function adminIndex(Request $request)
    {
        $query = StudentRequest::with([
            'requestedBy', 'targetCenter', 'targetTeacher', 'fromCenter', 'fromTeacher', 'student',
        ])->latest();

        $status = $request->get('status', 'pending');
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $requests = $query->get()->map(fn ($r) => $this->present($r, true));

        return response()->json(['success' => true, 'data' => $requests]);
    }

    /**
     * موافقة الأدمن — تُنفّذ التغيير الفعلي على students داخل معاملة:
     *  - add      → ينشئ الطالب (مع ربط ولي الأمر) بعد إعادة فحص التكرار.
     *  - transfer → ينقل الطالب (center_id/teacher_id) للوجهة.
     * يعيد التحقّق من أن محفّظ الوجهة ما زال محفّظاً ضمن مركز الوجهة.
     */
    public function approve(Request $request, $id)
    {
        $req = StudentRequest::findOrFail($id);

        if ($req->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'تمت معالجة هذا الطلب مسبقاً',
            ], 422);
        }

        // الأمان: محفّظ الوجهة يجب أن يكون محفّظاً ضمن مركز الوجهة (قاعدة «المحفّظ ينتمي للمركز»)
        $targetTeacher = User::where('id', $req->target_teacher_id)
            ->where('role', 'teacher')
            ->where('center_id', $req->target_center_id)
            ->first();
        if (!$targetTeacher) {
            return response()->json([
                'success' => false,
                'message' => 'المحفّظ المستهدف لم يعد محفّظاً ضمن المركز المستهدف — لا يمكن تنفيذ الطلب',
            ], 422);
        }

        if ($req->type === 'add') {
            // إعادة فحص التكرار بالرقم الوطني (قد يكون أُضيف بين الإرسال والموافقة)
            if ($req->national_id && Student::where('national_id', $req->national_id)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'أصبح هناك طالب مسجّل بنفس الرقم الوطني. ارفض هذا الطلب واطلب نقلاً بدلاً منه.',
                ], 422);
            }

            $student = DB::transaction(function () use ($req) {
                $parentId = $this->resolveParentId($req);

                $student = Student::create([
                    'name'            => $req->student_name,
                    'national_id'     => $req->national_id ?: null,
                    'phone'           => $req->phone,
                    'age'             => $req->age,
                    'center_id'       => $req->target_center_id,
                    'teacher_id'      => $req->target_teacher_id,
                    'parent_id'       => $parentId,
                    'guardian_name'   => $req->guardian_name,
                    'guardian_phone'  => $req->guardian_phone,
                    'enrollment_date' => now(),
                    'is_active'       => true,
                ]);

                $req->update(['status' => 'approved', 'student_id' => $student->id]);
                return $student;
            });

            return response()->json([
                'success' => true,
                'message' => 'تمت الموافقة وإنشاء الطالب بنجاح',
                'data'    => $student->load(['center', 'teacher', 'parent']),
            ]);
        }

        // transfer
        $student = Student::find($req->student_id);
        if (!$student) {
            return response()->json([
                'success' => false,
                'message' => 'الطالب المطلوب نقله لم يعد موجوداً',
            ], 422);
        }

        DB::transaction(function () use ($req, $student) {
            $student->update([
                'center_id'  => $req->target_center_id,
                'teacher_id' => $req->target_teacher_id,
            ]);
            $req->update(['status' => 'approved']);
        });

        return response()->json([
            'success' => true,
            'message' => 'تمت الموافقة ونقل الطالب بنجاح',
            'data'    => $student->load(['center', 'teacher']),
        ]);
    }

    /**
     * رفض الطلب — لا تغيير على students، فقط الحالة + سبب اختياري.
     */
    public function reject(Request $request, $id)
    {
        $req = StudentRequest::findOrFail($id);

        if ($req->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'تمت معالجة هذا الطلب مسبقاً',
            ], 422);
        }

        $request->validate(
            ['admin_note' => 'nullable|string|max:500'],
            ['admin_note.max' => 'السبب طويل جداً (500 حرف كحدّ أقصى)']
        );

        $req->update([
            'status'     => 'rejected',
            'admin_note' => $request->admin_note,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم رفض الطلب',
        ]);
    }

    // =============================================
    // مساعدات داخلية
    // =============================================

    /**
     * حسم حساب ولي الأمر عند الموافقة على طلب add — مطابقة بالهاتف ثم البريد،
     * وإلا إنشاء حساب جديد إن توفّر بريد فريد (يماثل StudentController@resolveParentAccount).
     * يُرجع معرّف ولي الأمر أو null (يبقى guardian_name/phone للعرض فقط).
     */
    protected function resolveParentId(StudentRequest $req): ?int
    {
        $phone = $req->guardian_phone;
        $email = $req->guardian_email;

        if (!$phone && !$email && !$req->guardian_name) {
            return null;
        }

        $parent = null;
        if ($phone) {
            $parent = User::where('role', 'parent')->where('phone', $phone)->first();
        }
        if (!$parent && $email) {
            $parent = User::where('role', 'parent')->where('email', $email)->first();
        }

        if ($parent) {
            $parent->fill([
                'name'  => $req->guardian_name ?: $parent->name,
                'phone' => $phone ?: $parent->phone,
            ])->save();
            return $parent->id;
        }

        // لا يمكن إنشاء حساب جديد بلا بريد، أو إن كان البريد مستخدماً لغير ولي أمر
        if (!$email || User::where('email', $email)->exists()) {
            return null;
        }

        $parent = User::create([
            'name'     => $req->guardian_name ?: 'ولي أمر',
            'email'    => $email,
            'phone'    => $phone,
            'role'     => 'parent',
            'password' => Hash::make(Str::random(24)),
        ]);

        return $parent->id;
    }

    /**
     * تحويل الطلب لصيغة عرض موحّدة للواجهة.
     * @param bool $forAdmin هل نضمّن اسم المحفّظ مقدّم الطلب (لصفحة الأدمن)؟
     */
    protected function present(StudentRequest $r, bool $forAdmin = false): array
    {
        $out = [
            'id'             => $r->id,
            'type'           => $r->type,
            'status'         => $r->status,
            'student_name'   => $r->student_name,
            'national_id'    => $r->national_id,
            'target_center'  => $r->targetCenter->name ?? '—',
            'from_center'    => $r->fromCenter->name ?? null,
            'from_teacher'   => $r->fromTeacher->name ?? null,
            'admin_note'     => $r->admin_note,
            'created_at'     => $r->created_at,
        ];

        if ($forAdmin) {
            $out['requested_by'] = $r->requestedBy->name ?? '—';
            $out['target_teacher'] = $r->targetTeacher->name ?? '—';
        }

        return $out;
    }
}
