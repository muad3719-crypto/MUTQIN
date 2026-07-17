<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * «ملفّي الشخصي» للمحفّظ — كل الدوال تعمل على $request->user() (صاحب التوكن)
 * حصراً: لا يُقبل أي معرّف من الطلب، فلا سبيل لقراءة/تعديل بيانات محفّظ آخر.
 *
 * حدود التعديل الذاتي (أمان في الطرفين): الهاتف وكلمة المرور فقط.
 * الاسم/المركز/النوع/الكود حقول عرضية — أي قيمة تُرسل لها تُتجاهل كلياً
 * (الكتابة أدناه صريحة لعمود واحد، لا mass-assignment من جسم الطلب).
 */
class TeacherProfileController extends Controller
{
    /** بيانات البروفايل مجمّعة: هوية + مركز + نوع + عدد الطلاب (رقم فقط). */
    public function show(Request $request)
    {
        $user = $request->user()->loadCount('students')->load('center:id,name');

        return response()->json([
            'success' => true,
            'data' => [
                'name'           => $user->name,
                'display_code'   => $user->display_code,
                'email'          => $user->email,
                'phone'          => $user->phone,
                'type'           => $user->type,
                'center_name'    => $user->center?->name,
                'students_count' => $user->students_count,
            ],
        ]);
    }

    /** تحديث رقم الهاتف فقط — بالتطبيع الموحّد (0912 = +218912 = 00218912). */
    public function updatePhone(Request $request)
    {
        $request->validate([
            'phone' => 'required|string|max:20',
        ], [
            'phone.required' => 'رقم الهاتف مطلوب',
            'phone.max'      => 'رقم الهاتف طويل جداً',
        ]);

        $normalized = PhoneNumber::normalize($request->phone);
        if (!$normalized) {
            return response()->json([
                'success' => false,
                'message' => 'رقم الهاتف غير صالح',
                'errors'  => ['phone' => ['أدخل رقم هاتف ليبي صحيح (مثل 0912345678 أو ‎+218912345678)']],
            ], 422);
        }

        $user = $request->user();
        $user->phone = $normalized; // عمود واحد صراحةً — كل ما عداه في الطلب مُتجاهَل
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث رقم الهاتف بنجاح',
            'data'    => ['phone' => $user->phone],
        ]);
    }

    /**
     * تغيير كلمة المرور ذاتياً — يتطلب الكلمة الحالية، ثم يعيد استخدام
     * المنطق الموحّد recordPasswordChange('self'): عدّاد + سجل + إبطال كل
     * التوكنات (S1) — فيُعاد توجيه المحفّظ لتسجيل الدخول بالكلمة الجديدة.
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:6|confirmed',
        ], [
            'current_password.required' => 'كلمة المرور الحالية مطلوبة',
            'password.required'  => 'كلمة المرور الجديدة مطلوبة',
            'password.min'       => 'كلمة المرور الجديدة 6 أحرف على الأقل',
            'password.confirmed' => 'كلمتا المرور غير متطابقتين',
        ]);

        $user = $request->user();
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'كلمة المرور الحالية غير صحيحة',
                'errors'  => ['current_password' => ['كلمة المرور الحالية غير صحيحة']],
            ], 422);
        }

        $user->password = Hash::make($request->password);
        $user->save();
        $user->recordPasswordChange('self'); // يسجّل ويبطل كل التوكنات

        return response()->json([
            'success' => true,
            'message' => 'تم تغيير كلمة المرور — سجّل الدخول من جديد بالكلمة الجديدة',
        ]);
    }
}
