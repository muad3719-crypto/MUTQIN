<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * حسم حساب ولي الأمر — المنطق الموحّد الوحيد (كان مزدوجاً بين
 * StudentController وStudentRequestController بسلوكي فشل مختلفين).
 *
 * الترتيب: رقم الهوية (المعرّف الثابت) → الهاتف المطبَّع → البريد — كلها ضمن
 * دور parent فقط (S2). إن لم يوجد: إنشاء حساب جديد بكلمة مرور عشوائية (S1)،
 * مع التقاط سباق التزامن على القيود الفريدة وإعادة المطابقة.
 *
 * سلوك الفشل موحّد: بيانات ولي أمر موجودة لكن يتعذّر الإنشاء (بريد مفقود/مستخدم،
 * هوية مستخدمة) → ValidationException برسالة عربية (422)، لا فشل صامت.
 */
class ParentResolver
{
    /**
     * @param array $g بيانات الولي: name, email, phone, id_number,
     *                 nationality_type, nationality_name, password (اختياري)
     * @return User|null null فقط إن لم تُقدَّم أي بيانات ولي أمر إطلاقاً.
     */
    public static function resolve(array $g): ?User
    {
        $idNumber = $g['id_number'] ?? null;
        $phone    = PhoneNumber::normalize($g['phone'] ?? null);
        $email    = $g['email'] ?? null;
        $name     = $g['name'] ?? null;
        $natType  = $g['nationality_type'] ?? 'libyan';
        $natName  = $natType === 'foreigner' ? ($g['nationality_name'] ?? null) : null;

        if (!$idNumber && !$phone && !$email && !$name) {
            return null; // لا بيانات ولي أمر — الطالب بلا ولي (مشروع)
        }

        // 1) المعرّف الثابت: رقم الهوية  2) الهاتف المطبَّع  3) البريد — دور parent فقط
        $parent = null;
        if ($idNumber) {
            $parent = User::where('role', 'parent')->where('id_number', $idNumber)->first();
        }
        if (!$parent && $phone) {
            $parent = User::where('role', 'parent')->where('phone', $phone)->first();
        }
        if (!$parent && $email) {
            $parent = User::where('role', 'parent')->where('email', $email)->first();
        }

        if ($parent) {
            $fill = [
                'name'  => $name ?: $parent->name,
                'phone' => $phone ?: $parent->phone,
            ];
            // تعبئة الهوية/الجنسية إن كانت ناقصة (لا نطمس قيمة موجودة)
            if ($idNumber && !$parent->id_number) {
                $fill['id_number']        = $idNumber;
                $fill['nationality_type'] = $natType;
                $fill['nationality_name'] = $natName;
            }
            $parent->fill($fill)->save();

            return $parent;
        }

        // إنشاء حساب جديد — يلزم بريد فريد (سلوك فشل موحّد: 422 عربية واضحة)
        if (!$email) {
            throw ValidationException::withMessages([
                'guardian_email' => ['بريد ولي الأمر مطلوب لإنشاء حساب جديد له'],
            ]);
        }
        if (User::where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'guardian_email' => ['بريد ولي الأمر مستخدم لحساب آخر (غير ولي أمر) — استخدم بريداً آخر'],
            ]);
        }
        if ($idNumber && User::where('id_number', $idNumber)->exists()) {
            throw ValidationException::withMessages([
                'guardian_id_number' => ['رقم هوية ولي الأمر مسجّل لحساب آخر'],
            ]);
        }

        try {
            return User::create([
                'name'             => $name ?: 'ولي أمر',
                'email'            => $email,
                'phone'            => $phone,
                'role'             => 'parent',
                'password'         => Hash::make(($g['password'] ?? null) ?: Str::random(24)), // S1
                'nationality_type' => $natType,
                'nationality_name' => $natName,
                'id_number'        => $idNumber ?: null,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // سباق تزامن: القيد الفريد أوقف الإنشاء الثاني — نعيد المطابقة بالحساب السابق
            $existing = ($idNumber ? User::where('role', 'parent')->where('id_number', $idNumber)->first() : null)
                ?? ($phone ? User::where('role', 'parent')->where('phone', $phone)->first() : null)
                ?? User::where('role', 'parent')->where('email', $email)->first();
            if ($existing) {
                return $existing;
            }
            throw $e;
        }
    }
}
