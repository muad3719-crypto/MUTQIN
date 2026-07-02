<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\OtpReset;

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

        // تحقّق صريح بلا حارس جلسات: هذا API عديم الحالة (توكنات)، وAuth::attempt
        // يعتمد على الحارس الافتراضي القابل للتبدّل — مصدر هشاشة خفيّة.
        $user = User::where('email', $request->email)->first();

        if ($user && Hash::check($request->password, $user->password)) {
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

    // =====================================================================
    // استعادة كلمة المرور عبر الهاتف (OTP) — لولي الأمر والمحفّظ فقط
    // (لا تغيير على آلية الدخول؛ الدخول يبقى بالبريد + كلمة المرور)
    // =====================================================================

    /**
     * الخطوة 1: طلب شفرة OTP برقم الهاتف.
     * رسالة محايدة دائماً (لا تكشف إن كان الرقم مسجّلاً) لمنع تعداد الأرقام.
     * في dev: تُرجَع الشفرة ليُسلّمها الأدمن (لا توجد بوابة SMS فعلية).
     */
    public function forgotPasswordRequest(Request $request)
    {
        $request->validate(['phone' => 'required|string|max:20'], ['phone.required' => 'رقم الهاتف مطلوب']);

        $neutral = [
            'success' => true,
            'message' => 'إن كان هذا الرقم مسجّلاً، فستُسلَّم شفرة التحقّق (6 أرقام) إلى صاحبه.',
        ];

        // الأدمن لا يُعاد ضبطه بهذه الطريقة (يبقى يدوياً)
        $user = User::whereIn('role', ['parent', 'teacher'])->where('phone', $request->phone)->first();
        if (!$user) {
            return response()->json($neutral);
        }

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        OtpReset::where('user_id', $user->id)->delete(); // شفرة واحدة فعّالة لكل مستخدم
        OtpReset::create([
            'user_id'   => $user->id,
            'otp_hash'  => Hash::make($otp),
            'expires_at' => now()->addMinutes(10),
            'attempts'  => 0,
        ]);

        $this->sendOtp($user, $otp);

        // بيئة التطوير المحلية (local) فقط: تُعرض الشفرة للأدمن ليُسلّمها هاتفياً.
        // ⚠️ خطير: لا تعتمد على APP_DEBUG — قد يبقى مفعّلاً بالخطأ في الإنتاج/staging،
        // فيصبح تصفير كلمة مرور أي محفّظ/ولي أمر ممكناً لمن يعرف رقم هاتفه. الشرط
        // environment('local') يضمن ألّا تخرج الشفرة في أي استجابة خارج جهاز التطوير.
        if (app()->environment('local')) {
            $neutral['dev_otp']  = $otp;
            $neutral['dev_note'] = 'وضع التطوير: سلّم هذه الشفرة إلى «' . $user->name . '» (تنتهي خلال 10 دقائق).';
        }

        return response()->json($neutral);
    }

    /**
     * الخطوة 2: التحقّق من الشفرة وتعيين كلمة مرور جديدة.
     * رسالة خطأ عامة موحّدة، وعدّاد محاولات، وإبطال الشفرة بعد النجاح.
     */
    public function forgotPasswordVerify(Request $request)
    {
        $request->validate([
            'phone'    => 'required|string|max:20',
            'otp'      => 'required|digits:6',
            'password' => 'required|min:6',
        ], [
            'phone.required'    => 'رقم الهاتف مطلوب',
            'otp.required'      => 'الشفرة مطلوبة',
            'otp.digits'        => 'الشفرة 6 أرقام',
            'password.required' => 'كلمة المرور الجديدة مطلوبة',
            'password.min'      => 'كلمة المرور 6 أحرف على الأقل',
        ]);

        $generic = response()->json([
            'success' => false,
            'message' => 'الشفرة غير صحيحة أو منتهية الصلاحية',
        ], 422);

        $user = User::whereIn('role', ['parent', 'teacher'])->where('phone', $request->phone)->first();
        if (!$user) {
            return $generic;
        }

        $reset = OtpReset::where('user_id', $user->id)->latest()->first();
        if (!$reset || $reset->expires_at->isPast() || $reset->attempts >= 5) {
            $reset?->delete();
            return $generic;
        }

        if (!Hash::check($request->otp, $reset->otp_hash)) {
            $reset->increment('attempts');
            return $generic;
        }

        // نجاح: تحديث كلمة المرور وإبطال كل شفرات هذا المستخدم
        $user->password = Hash::make($request->password);
        $user->save();
        $user->recordPasswordChange('otp'); // تتبّع التغيير
        OtpReset::where('user_id', $user->id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث كلمة المرور بنجاح، يمكنك الدخول الآن.',
        ]);
    }

    /**
     * نقطة الإرسال الوحيدة — استبدلها ببوابة SMS حقيقية (ليبيانا/مدار) لاحقاً.
     * حالياً: تسجيل فقط، بلا إرسال فعلي.
     */
    protected function sendOtp(User $user, string $otp): void
    {
        Log::info("OTP password-reset for user #{$user->id} (phone {$user->phone}): {$otp}");
        // TODO(SMS): SmsGateway::send($user->phone, "شفرة استعادة كلمة المرور: {$otp} (تنتهي خلال 10 دقائق)");
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
