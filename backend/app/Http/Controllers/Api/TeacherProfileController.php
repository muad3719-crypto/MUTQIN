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

}
