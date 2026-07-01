<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * إشعار عام داخل التطبيق (قناة قاعدة البيانات) — صنف واحد لكل الأنواع.
 * يستقبل النوع والبيانات جاهزةً، فتُضاف أنواع جديدة بلا صنف جديد ولا إعادة هيكلة.
 *
 * الأنواع الحالية:
 *   request_created / request_approved / request_rejected  (طلبات الطلاب → الأدمن/المحفّظ)
 *   memorization_added / test_added                         (حفظ/اختبار → ولي الأمر)
 *
 * الشكل المخزّن (JSON ثابت): { type, title, body, ref_id, link }
 */
class InAppNotification extends Notification
{
    public function __construct(
        protected string $type,
        protected string $title,
        protected string $body,
        protected ?int $refId = null,
        protected ?string $link = null,
    ) {}

    /** قناة قاعدة البيانات فقط (إشعارات داخل التطبيق). */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** المحتوى المخزّن في عمود data (JSON). */
    public function toArray(object $notifiable): array
    {
        return [
            'type'   => $this->type,
            'title'  => $this->title,
            'body'   => $this->body,
            'ref_id' => $this->refId,   // مرجع عام (id الطلب/الحفظ/الاختبار حسب النوع)
            'link'   => $this->link,
        ];
    }

    /**
     * إرسال آمن — الإشعار ثانوي: أي فشل (مستلم محذوف/غير موجود، خطأ قناة) لا يُسقط
     * العملية الأساسية. $recipients: مستخدم واحد أو مجموعة مستخدمين.
     */
    public static function sendSafe($recipients, string $type, string $title, string $body, ?int $refId = null, ?string $link = null): void
    {
        try {
            if ($recipients === null || (is_countable($recipients) && count($recipients) === 0)) {
                return;
            }
            NotificationFacade::send($recipients, new self($type, $title, $body, $refId, $link));
        } catch (\Throwable $e) {
            Log::warning('in-app notification failed: ' . $e->getMessage());
        }
    }
}
