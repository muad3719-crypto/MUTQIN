<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Memorization;
use App\Models\Student;
use App\Notifications\InAppNotification;
use App\Support\SurahReference;
use Illuminate\Http\Request;

class MemorizationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $studentQuery = Student::query();
        if (!$user->isAdmin()) {
            $studentQuery->where('teacher_id', $user->id); // طلاب هذا المعلم فقط
        }
        $studentIds = $studentQuery->pluck('id');

        $query = Memorization::whereIn('student_id', $studentIds)
            ->with('student')
            ->latest();

        // فلتر اختياري بجزء السورة: يُشتقّ الجزء من اسم السورة عبر المرجع الثابت
        // (لا نعتمد على memorizations.juz لأنه غير موثوق). يُرجِع سجلات سور الجزء N فقط.
        if ($request->filled('juz')) {
            $query->whereIn('surah_name', SurahReference::namesOfJuz((int) $request->juz));
        }

        $memorizations = $query->paginate(15)->withQueryString();

        return response()->json([
            'success' => true,
            'data' => $memorizations
        ]);
    }

    /**
     * تقدّم الطلاب في حفظ الأجزاء — يُحسب بدلالة «السور المكتملة» لا برقم الجزء وحده.
     *
     * الحفظ معكوس: يبدأ من الناس (جزء 30) نزولاً نحو الفاتحة (جزء 1).
     * الجزء لا يُحتسب مكتملاً إلا بإتمام «كل» سوره (مرجع السور: {@see SurahReference}).
     * لكل طالب: reached_juz = جزء آخر سورة وصل إليها، مع حالة (أتمّ/داخل) وآخر سورة،
     * وعدد الأجزاء المكتملة فعلاً.
     *
     * فلتر juz=N: من أتمّ كل سور الأجزاء 30..N (أي completed_down_to <= N).
     * فلتر age (اختياري، قابل للدمج).
     *
     * الكفاءة: استعلامان فقط (طلاب + سجلات الحفظ) ثم حساب في الذاكرة — بلا N+1.
     */
    public function studentsProgress(Request $request)
    {
        $user = $request->user();

        $studentQuery = Student::query()->select('id', 'name', 'age');
        if (!$user->isAdmin()) {
            $studentQuery->where('teacher_id', $user->id); // المعلم يرى طلابه فقط
        }
        if ($request->filled('age')) {
            $studentQuery->where('age', (int) $request->age); // فلتر العمر (قابل للدمج)
        }
        $students = $studentQuery->orderBy('name')->get();

        // سور كل طالب (استعلام واحد) ثم تجميع في الذاكرة
        $bySurah = Memorization::whereIn('student_id', $students->pluck('id'))
            ->whereNotNull('surah_name')
            ->get(['student_id', 'surah_name'])
            ->groupBy('student_id');

        $juzFilter = $request->filled('juz') ? (int) $request->juz : null;

        $result = $students->map(function ($s) use ($bySurah) {
            $names = ($bySurah[$s->id] ?? collect())->pluck('surah_name')->all();
            $p = SurahReference::progress($names);

            return [
                'id'                => $s->id,
                'name'              => $s->name,
                'age'               => $s->age,
                'reached_juz'       => $p['reached_juz'],       // جزء آخر سورة وصل إليها
                'reached_juz_done'  => $p['reached_juz_done'],  // هل أتمّ هذا الجزء؟
                'last_surah'        => $p['last_surah'],         // آخر سورة محفوظة
                'completed_count'   => $p['completed_count'],    // عدد الأجزاء المكتملة فعلاً
                'completed_down_to' => $p['completed_down_to'],  // أصغر جزء أُكمل (متّصلاً من 30)
            ];
        });

        // فلتر الجزء: من أتمّ كل سور 30..N
        if ($juzFilter !== null) {
            $result = $result->filter(fn ($r) =>
                $r['completed_down_to'] !== null && $r['completed_down_to'] <= $juzFilter
            )->values();
        }

        return response()->json(['success' => true, 'data' => $result]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'student_id'  => 'required|exists:students,id',
            'date'        => 'required|date',
            // السورة يجب أن تكون من المرجع الثابت — خطأ إملائي كان يمرّ بصمت
            // فيسقط السجل من حساب التقدّم (المبني على مطابقة الاسم)
            'surah_name'  => ['required', 'string', \Illuminate\Validation\Rule::in(array_keys(\App\Support\SurahReference::SURAHS))],
            'juz'         => 'nullable|integer|between:1,30',
            'quality'     => 'required|in:excellent,good,average,weak',
        ], [
            'student_id.required' => 'اختر الطالب',
            'surah_name.required' => 'اختر السورة',
            'surah_name.in'       => 'اسم السورة غير معروف — اختر سورة من القائمة',
            'juz.between'         => 'رقم الجزء يجب أن يكون بين 1 و30',
            'quality.required'    => 'اختر تقييم الجودة',
        ]);

        $student = Student::findOrFail($request->student_id);

        // check authorization
        if (!$user->isAdmin() && $student->teacher_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بإضافة حفظ لهذا الطالب'
            ], 403);
        }

        $memorization = Memorization::create([
            'student_id'  => $request->student_id,
            'teacher_id'  => $user->id,
            'date'        => $request->date,
            'surah_name'  => $request->surah_name,
            'juz'         => $request->juz,
            'hizb'        => $request->hizb,
            'page_from'   => $request->page_from,
            'page_to'     => $request->page_to,
            'eighth'      => $request->eighth,
            'quality'     => $request->quality,
            'notes'       => $request->notes,
        ]);

        // إشعار ولي أمر الطالب (إن وُجد) — ثانوي، لا يُسقط التسجيل
        $qualityLabels = ['excellent' => 'ممتاز', 'good' => 'جيد', 'average' => 'مقبول', 'weak' => 'ضعيف'];
        InAppNotification::sendSafe(
            $student->parent,
            'memorization_added',
            'تسجيل حفظ جديد',
            'سجّل المحفّظ حفظاً جديداً لابنك «' . $student->name . '»: سورة ' . $request->surah_name
                . ' (التقييم: ' . ($qualityLabels[$request->quality] ?? $request->quality) . ').',
            $student->id,
            'parent/child.html?id=' . $student->id
        );

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل الحفظ بنجاح',
            'data' => $memorization
        ], 201);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $memorization = Memorization::findOrFail($id);
        $student = Student::findOrFail($memorization->student_id);

        if (!$user->isAdmin() && $student->teacher_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بحذف هذا السجل'
            ], 403);
        }

        $memorization->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم الحذف بنجاح'
        ]);
    }

    public function surahs()
    {
        // مصدر واحد للسور: المرجع الثابت (ترتيب المصحف) — لا نسخة منسوخة بعد اليوم
        return response()->json([
            'success' => true,
            'data' => array_keys(\App\Support\SurahReference::SURAHS),
        ]);
    }
}
