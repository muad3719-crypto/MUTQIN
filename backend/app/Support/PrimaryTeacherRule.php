<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * قاعدة «محفّظ أساسي واحد فقط لكل مركز» — مستخرجة لمصدر واحد
 * تستعملها شاشتا تعديل المحفّظ: المدير الرئيسي ومشرف المركز.
 */
class PrimaryTeacherRule
{
    /**
     * يرمي خطأ تحقّق (422) إن كان النوع «أساسي» ووُجد أساسي آخر في نفس المركز.
     * $ignoreId: يُستثنى من الفحص (المحفّظ نفسه عند التعديل).
     */
    public static function assert($centerId, ?string $type, $ignoreId = null): void
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
            throw ValidationException::withMessages([
                'type' => ['هذا المركز له محفّظ أساسي بالفعل، لا يمكن إضافة محفّظ أساسي آخر'],
            ]);
        }
    }
}
