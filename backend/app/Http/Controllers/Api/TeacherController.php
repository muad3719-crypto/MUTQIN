<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class TeacherController extends Controller
{
    public function index(Request $request)
    {
        $query = User::where('role', 'teacher')->withCount('students')->latest();

        // فلتر اختياري بالمركز — لقائمة المحفّظ التابعة للمركز في نماذج الطلاب
        if ($request->filled('center_id')) {
            $query->where('center_id', (int) $request->center_id);
        }

        // بحث حي اختياري q بالاسم المطبَّع («احمد»=«أحمد») — يتجمّع مع فلتر المركز
        if (($q = trim((string) $request->get('q', ''))) !== '') {
            $norm = \App\Support\ArabicText::normalize($q);
            $query->whereRaw(\App\Support\ArabicText::sqlNormalize('name') . ' LIKE ?', ['%' . $norm . '%']);
        }

        if ($request->has('all') && $request->all == 1) {
            $teachers = $query->get();
        } else {
            $teachers = $query->paginate(20)->withQueryString();
        }

        return response()->json([
            'success' => true,
            'data' => $teachers
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'phone'    => 'nullable|string|max:20',
            'password' => 'required|min:6|confirmed',
            'center_id'=> 'required|exists:centers,id',
            'type'     => 'required|in:محفظ أساسي,محفظ معاون',
        ], [
            'name.required'      => 'اسم المحفظ مطلوب',
            'email.required'     => 'البريد الإلكتروني مطلوب',
            'email.unique'       => 'هذا البريد مستخدم مسبقاً',
            'password.required'  => 'كلمة المرور مطلوبة',
            'password.min'       => 'كلمة المرور 6 أحرف على الأقل',
            'password.confirmed' => 'كلمتا المرور غير متطابقتين',
            'center_id.required' => 'يجب اختيار المركز',
            'type.required'      => 'يجب تحديد نوع المحفظ',
        ]);

        // قاعدة: محفّظ أساسي واحد فقط لكل مركز
        $this->assertSinglePrimary($request->center_id, $request->type);

        $teacher = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'phone'    => \App\Support\PhoneNumber::normalize($request->phone),
            'role'     => 'teacher',
            'password' => Hash::make($request->password),
            'center_id'=> $request->center_id,
            'type'     => $request->type,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم إضافة المحفظ بنجاح للمركز',
            'data' => $teacher
        ], 201);
    }

    public function show($id)
    {
        // بيانات المحفّظ مجمّعة لنافذة «تفاصيل» (للأدمن): المركز + النوع + عدد الطلاب + أسماء الطلاب.
        // فعّال: استعلام للمحفّظ (مع المركز وعدّ الطلاب) + استعلام واحد للطلاب — بلا N+1.
        $teacher = User::where('role', 'teacher')
            ->with('center:id,name')
            ->withCount('students')
            ->findOrFail($id);

        $students = $teacher->students()
            ->orderBy('name')
            ->get(['id', 'name', 'age']);

        // سجل تغييرات كلمة المرور (للأدمن — عرض فقط، لا كلمات مرور)
        $pwdLogs = $teacher->passwordChangeLogs()
            ->latest('changed_at')
            ->limit(10)
            ->get(['changed_at', 'method']);

        // عدد السجلّات التاريخية التي سجّلها هذا المحفّظ (لتحذير الأثر قبل الحذف)
        $recordsCount = \App\Models\Attendance::where('teacher_id', $teacher->id)->count()
            + \App\Models\Memorization::where('teacher_id', $teacher->id)->count()
            + \App\Models\WeeklyTest::where('teacher_id', $teacher->id)->count();

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
                'records_count'  => $recordsCount,
                'students'       => $students,
                'password_changed_count'   => $teacher->password_changed_count,
                'password_last_changed_at' => $teacher->password_last_changed_at,
                'password_logs'            => $pwdLogs,
            ],
        ]);
    }

    public function update(Request $request, $id)
    {
        $teacher = User::where('role', 'teacher')->findOrFail($id);

        $request->validate([
            'name'      => 'required|string|max:255',
            'email'     => 'required|email|unique:users,email,' . $teacher->id,
            'phone'     => 'nullable|string|max:20',
            'center_id' => 'required|exists:centers,id',
            'type'      => 'required|in:محفظ أساسي,محفظ معاون',
        ]);

        // قاعدة: محفّظ أساسي واحد لكل مركز (مع السماح لنفس المحفّظ الحالي)
        $this->assertSinglePrimary($request->center_id, $request->type, $teacher->id);

        $data = [
            'name'      => $request->name,
            'email'     => $request->email,
            'phone'     => \App\Support\PhoneNumber::normalize($request->phone),
            'center_id' => $request->center_id,
            'type'      => $request->type,
        ];

        if ($request->filled('password')) {
            $request->validate(['password' => 'min:6|confirmed']);
            $data['password'] = Hash::make($request->password);
        }

        $teacher->update($data);

        if ($request->filled('password')) {
            $teacher->recordPasswordChange('admin'); // تتبّع تغيير الأدمن لكلمة المرور
        }

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث بيانات المعلم بنجاح',
            'data' => $teacher
        ]);
    }

    public function destroy($id)
    {
        $teacher = User::where('role', 'teacher')->findOrFail($id);

        // قبل الحذف: نختم اسم المحفّظ على طلابه ليظهر «محفّظ سابق: فلان»
        // (teacher_id سيصير null بالحذف — D1 — فلا يبقى تمييز بينه وبين «بدون معلم»)
        \App\Models\Student::where('teacher_id', $teacher->id)
            ->update(['former_teacher_name' => $teacher->name]);

        $teacher->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم حذف المعلم بنجاح'
        ]);
    }

    /**
     * يفرض قاعدة «محفّظ أساسي واحد لكل مركز».
     * يرمي خطأ تحقّق (422) إن كان النوع «أساسي» ووُجد أساسي آخر في نفس المركز.
     * $ignoreId: يُستثنى من الفحص (المحفّظ نفسه عند التعديل).
     */
    protected function assertSinglePrimary($centerId, $type, $ignoreId = null): void
    {
        if ($type !== 'محفظ أساسي') {
            return; // المعاونون بلا حدّ
        }

        $exists = User::where('role', 'teacher')
            ->where('type', 'محفظ أساسي')
            ->where('center_id', $centerId)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'type' => ['هذا المركز له محفّظ أساسي بالفعل، لا يمكن إضافة محفّظ أساسي آخر'],
            ]);
        }
    }

    /**
     * هل للمركز محفّظ أساسي؟ (لتعطيل الخيار في الواجهة). GET /api/centers/{id}/has-primary
     */
    public function hasPrimary($id)
    {
        $primary = User::where('role', 'teacher')
            ->where('type', 'محفظ أساسي')
            ->where('center_id', $id)
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'has_primary'     => (bool) $primary,
                'primary_teacher' => $primary ? ['id' => $primary->id, 'name' => $primary->name] : null,
            ],
        ]);
    }
}
