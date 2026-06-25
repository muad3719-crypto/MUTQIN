<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;

class AuthController extends Controller
{
    /**
     * تسجيل دخول موحّد للأدوار الثلاثة (admin / teacher / parent)
     * بالبريد الإلكتروني وكلمة المرور، ويعيد توكن Sanctum.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|min:6',
        ], [
            'email.required'    => 'البريد الإلكتروني مطلوب',
            'email.email'       => 'البريد الإلكتروني غير صحيح',
            'password.required' => 'كلمة المرور مطلوبة',
            'password.min'      => 'كلمة المرور يجب أن تكون 6 أحرف على الأقل',
        ]);

        $credentials = $request->only('email', 'password');

        if (Auth::attempt($credentials)) {
            $user = Auth::user();

            // ولي الأمر يُمنح صلاحية 'parent' فقط؛ المدير/المعلم صلاحية كاملة '*'
            $abilities = $user->isParent() ? ['parent'] : ['*'];
            $token = $user->createToken('auth_token', $abilities)->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'تم تسجيل الدخول بنجاح',
                'data' => [
                    'token' => $token,
                    'user'  => $this->userPayload($user),
                ]
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'البريد الإلكتروني أو كلمة المرور غير صحيحة',
            'errors' => [
                'email' => ['البريد الإلكتروني أو كلمة المرور غير صحيحة']
            ]
        ], 422);
    }

    /**
     * تسجيل الخروج (حذف التوكن الحالي).
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل الخروج بنجاح'
        ]);
    }

    /**
     * بيانات المستخدم الحالي.
     */
    public function user(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => [
                'user' => $this->userPayload($request->user()),
            ]
        ]);
    }

    /**
     * تنسيق بيانات المستخدم المُعادة للواجهة.
     */
    protected function userPayload(User $user): array
    {
        return [
            'id'        => $user->id,
            'name'      => $user->name,
            'email'     => $user->email,
            'phone'     => $user->phone,
            'role'      => $user->role,
            'center_id' => $user->center_id,
            'type'      => $user->type,
        ];
    }
}
