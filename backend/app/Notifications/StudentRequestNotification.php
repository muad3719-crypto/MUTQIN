<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * إشعار عام لأحداث طلبات الطلاب (وقابل للتوسّع لأنواع أخرى لاحقاً).
 * يستقبل النوع والبيانات جاهزةً بدل صنف لكل حالة.
 *
 * الأنواع الحالية: request_created / request_approved / request_rejected
 * الشكل المخزّن (JSON ثابت): { type, title, body, request_id, link }
 */
class StudentRequestNotification extends Notification
{
    public function __construct(
        protected string $type,
        protected string $title,
        protected string $body,
        protected ?int $requestId = null,
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
            'type'       => $this->type,
            'title'      => $this->title,
            'body'       => $this->body,
            'request_id' => $this->requestId,
            'link'       => $this->link,
        ];
    }
}
