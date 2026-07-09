<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * إدارة «مدراء المراكز» — لمدير النظام فقط.
 * القاعدة: مدير واحد لكل مركز كحدّ أقصى.
 */
class ManagerManagementController extends Controller
{
    /** مدير واحد لكل مركز كحدّ أقصى. */
    protected function assertSingleSupervisor($centerId, $ignoreId = null): void
    {
        $exists = User::where('role', 'center_manager')
            ->where('center_id', $centerId)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'center_id' => ['لهذا المركز مدير بالفعل — مدير واحد لكل مركز كحدّ أقصى'],
            ]);
        }
    }

    public function index(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => User::where('role', 'center_manager')
                ->with('center:id,name,city')
                ->latest()
                ->get(['id', 'name', 'email', 'phone', 'center_id', 'created_at']),
        ]);
    }

    public function store(Request $request)
    {
        // البريد بمخطط النظام name.family.id@mutqin.ly — يُدخل المدير الجزء اللاتيني
        // (email_prefix مثل ali.faraj) والمعرّف يُلحق بعد الإدراج (لا يوجد قبله).
        // كلمة المرور مطلوبة صراحةً عند الإنشاء — لا توليد صامت (درس S1).
        $request->validate([
            'name'         => 'required|string|max:255',
            'email_prefix' => ['required', 'string', 'max:60', 'regex:/^[a-z]+(\.[a-z]+)+$/'],
            'phone'        => 'nullable|string|max:20',
            'password'     => 'required|min:6|confirmed',
            'center_id'    => 'required|exists:centers,id',
        ], [
            'name.required'         => 'اسم مدير المركز مطلوب',
            'email_prefix.required' => 'اسم المستخدم اللاتيني مطلوب (مثل: ali.faraj)',
            'email_prefix.regex'    => 'الصيغة: أحرف لاتينية صغيرة بنقطة بينها، مثل ali.faraj',
            'password.required'     => 'كلمة المرور مطلوبة',
            'password.min'          => 'كلمة المرور 6 أحرف على الأقل',
            'password.confirmed'    => 'كلمتا المرور غير متطابقتين',
            'center_id.required'    => 'يجب اختيار المركز',
        ]);

        $this->assertSingleSupervisor($request->center_id);

        // خطوتان: إدراج ببريد مؤقّت ثم ضبط البريد النهائي بالمعرّف (النمط المعتمد في النظام)
        $manager = User::create([
            'name'      => $request->name,
            'email'     => 'tmp-' . \Illuminate\Support\Str::random(18) . '@mutqin.ly',
            'phone'     => PhoneNumber::normalize($request->phone),
            'role'      => 'center_manager',
            'password'  => Hash::make($request->password),
            'center_id' => $request->center_id,
        ]);
        $manager->email = $request->email_prefix . '.' . $manager->id . '@mutqin.ly';
        $manager->save();

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء مدير المركز بنجاح — بريده: ' . $manager->email,
            'data' => $manager,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $manager = User::where('role', 'center_manager')->findOrFail($id);

        $request->validate([
            'name'      => 'required|string|max:255',
            'email'     => 'required|email|unique:users,email,' . $manager->id,
            'phone'     => 'nullable|string|max:20',
            'center_id' => 'required|exists:centers,id',
        ], [
            'name.required'      => 'اسم مدير المركز مطلوب',
            'email.unique'       => 'هذا البريد مستخدم مسبقاً',
            'center_id.required' => 'يجب اختيار المركز',
        ]);

        $this->assertSingleSupervisor($request->center_id, $manager->id);

        $data = [
            'name'      => $request->name,
            'email'     => $request->email,
            'phone'     => PhoneNumber::normalize($request->phone),
            'center_id' => $request->center_id,
        ];

        if ($request->filled('password')) {
            $request->validate(['password' => 'min:6|confirmed']);
            $data['password'] = Hash::make($request->password);
        }

        $manager->update($data);

        if ($request->filled('password')) {
            $manager->recordPasswordChange('admin');
        }

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث بيانات مدير المركز بنجاح',
            'data' => $manager,
        ]);
    }

    public function destroy($id)
    {
        $manager = User::where('role', 'center_manager')->findOrFail($id);
        $centerId = $manager->center_id;

        // الأثر: طلبات مركزه الداخلية المعلّقة تؤول فوراً لمدير النظام
        // (الاعتماد أصلاً ديناميكي — بلا مدير للمركز يعتمدها مدير النظام —
        // لكن نُشعر مدراء النظام بها كي لا تبقى معلّقة بصمت).
        $pending = \App\Models\StudentRequest::where('status', 'pending')
            ->where('type', 'transfer')
            ->where('from_center_id', $centerId)
            ->where('target_center_id', $centerId)
            ->count();

        $centerName = \App\Models\Center::where('id', $centerId)->value('name') ?? '—';
        $manager->delete();

        if ($pending > 0) {
            \App\Notifications\InAppNotification::sendSafe(
                User::where('role', 'admin')->get(),
                'request_created',
                'طلبات آلت إليك بعد حذف مدير مركز',
                'حُذف مدير مركز «' . $centerName . '» وله ' . $pending . ' طلب نقل داخلي معلّق — اعتمادها أو رفضها صار إليك.',
                null,
                'admin/requests.html'
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'تم حذف مدير المركز بنجاح' . ($pending > 0 ? " — آل {$pending} طلب معلّق لمدير النظام" : ''),
        ]);
    }
}
