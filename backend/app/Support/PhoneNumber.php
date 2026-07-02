<?php

namespace App\Support;

/**
 * تطبيع أرقام الهواتف الليبية — مصدر واحد يُستخدم في كل نقاط الإدخال والمطابقة.
 * 0912345678 و +218912345678 و 00218 91 234-5678 و ٠٩١٢٣٤٥٦٧٨ = نفس الرقم.
 */
class PhoneNumber
{
    /** يُرجع الصيغة المحلية الموحّدة (09xxxxxxxx) أو null إن كان الإدخال فارغاً. */
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        // الأرقام العربية-الهندية → الغربية
        $phone = strtr($phone, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        // أبقِ الأرقام فقط (يُسقط + والمسافات والشرطات والأقواس)
        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === '') {
            return null;
        }

        // 00218xxxxxxxxx أو 218xxxxxxxxx (من +218) → 0xxxxxxxxx
        if (str_starts_with($digits, '00218')) {
            $digits = '0' . substr($digits, 5);
        } elseif (str_starts_with($digits, '218') && strlen($digits) >= 11) {
            $digits = '0' . substr($digits, 3);
        }

        return $digits;
    }
}
