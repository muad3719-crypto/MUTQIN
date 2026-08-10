<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Center;
use Illuminate\Http\Request;

class CenterController extends Controller
{
    public function index(Request $request)
    {
        $query = Center::withCount(['students', 'teachers'])->latest();

        // ?active=1 — المراكز النشطة فقط: لقوائم الاختيار (إضافة طالب/محفّظ/مدير)
        // كي لا يُضاف أحد لمركز معطَّل. صفحة إدارة المراكز والتقارير تعرض الكل.
        if ($request->boolean('active')) {
            $query->where('is_active', true);
        }

        if ($request->has('all') && $request->all == 1) {
            $centers = $query->get();
        } else {
            $centers = $query->paginate(10);
        }

        return response()->json([
            'success' => true,
            'data' => $centers
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'city' => 'nullable|string|max:255',
        ], [
            'name.required' => 'اسم المركز مطلوب',
        ]);

        $center = Center::create($request->only(['name', 'city', 'address', 'phone', 'is_active']));

        return response()->json([
            'success' => true,
            'message' => 'تم إضافة المركز بنجاح',
            'data' => $center
        ], 201);
    }

    /**
     * تفاصيل المركز لنافذة العرض: بياناته + عدد الطلاب والمحفّظين + قائمة
     * المحفّظين بنوعهم وحالتهم. فعّال: استعلام للمركز (بعدّين مجمّعين عبر
     * withCount) + استعلام واحد للمحفّظين — بلا N+1 مهما كثُر المحفّظون.
     */
    public function show($id)
    {
        $center = Center::withCount(['students', 'teachers'])->findOrFail($id);

        $teachers = \App\Models\User::where('role', 'teacher')
            ->where('center_id', $center->id)
            ->orderByRaw("FIELD(type,'محفظ أساسي','محفظ معاون')") // الأساسي أولاً
            ->orderBy('name')
            ->get(['id', 'name', 'display_code', 'type', 'is_active', 'email']);

        return response()->json([
            'success' => true,
            'data' => [
                'id'             => $center->id,
                'display_code'   => $center->display_code,
                'name'           => $center->name,
                'city'           => $center->city,
                'address'        => $center->address,
                'phone'          => $center->phone,
                'is_active'      => $center->is_active,
                'students_count' => $center->students_count,
                'teachers_count' => $center->teachers_count,
                'teachers'       => $teachers,
            ],
        ]);
    }

    public function update(Request $request, $id)
    {
        $center = Center::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'city' => 'nullable|string|max:255',
        ]);

        $center->update($request->only(['name', 'city', 'address', 'phone', 'is_active']));

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث بيانات المركز بنجاح',
            'data' => $center
        ]);
    }

    /**
     * تفعيل/تعطيل المركز — بديل الحذف نهائياً (الحذف يفقد كل تاريخ المركز).
     * قرار معتمد: المركز المعطَّل «مغلق» فعلياً — يُمنع منتسبوه (محفّظوه ومدير
     * مركزه، وهم وحدهم من يحملون center_id) من الدخول، وتُبطَل جلساتهم فوراً
     * وإلا واصلوا العمل حتى انتهاء التوكن (7 أيام). أولياء الأمور والطلاب لا
     * يتأثرون (الطلاب ليسوا مستخدمين، وأولياء الأمور بلا مركز).
     */
    public function toggleStatus(Request $request, $id)
    {
        $request->validate([
            'is_active' => 'required|boolean',
        ], ['is_active.required' => 'الحالة مطلوبة']);

        $center = Center::findOrFail($id);
        $active = $request->boolean('is_active');

        $affected = \Illuminate\Support\Facades\DB::transaction(function () use ($center, $active) {
            $center->update(['is_active' => $active]);

            if ($active) {
                return 0;
            }

            // إبطال جلسات منتسبي المركز فوراً (نفس آلية S1)
            $members = \App\Models\User::where('center_id', $center->id)->get();
            foreach ($members as $m) {
                $m->tokens()->delete();
            }
            return $members->count();
        });

        return response()->json([
            'success' => true,
            'message' => $active
                ? "تم تفعيل مركز «{$center->name}»"
                : "تم تعطيل مركز «{$center->name}» — أُنهيت جلسات {$affected} من منتسبيه ولن يستطيعوا الدخول",
            'data' => ['id' => $center->id, 'is_active' => $center->is_active, 'affected_members' => $affected],
        ]);
    }
}
