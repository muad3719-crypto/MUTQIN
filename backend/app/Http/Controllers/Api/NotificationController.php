<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * إشعارات المستخدم الحالي (داخل التطبيق) — قناة قاعدة بيانات Laravel.
 * كل مستخدم يرى/يعدّل إشعاراته فقط (التحقّق عبر علاقة notifications الخاصة به).
 */
class NotificationController extends Controller
{
    /** GET /api/notifications — الأحدث أولاً + عدد غير المقروء. */
    public function index(Request $request)
    {
        $user = $request->user();

        $items = $user->notifications()->latest()->limit(30)->get()->map(function ($n) {
            $d = $n->data ?? [];
            return [
                'id'          => $n->id,
                'type'        => $d['type']  ?? null,
                'title'       => $d['title'] ?? '',
                'body'        => $d['body']  ?? '',
                'link'        => $d['link']  ?? null,
                'is_read'     => $n->read_at !== null,
                'created_at'  => $n->created_at,
                'created_ago' => Carbon::parse($n->created_at)->locale('ar')->diffForHumans(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'unread_count' => $user->unreadNotifications()->count(),
                'items'        => $items,
            ],
        ]);
    }

    /** POST /api/notifications/{id}/read — تعليم إشعار واحد كمقروء (ملك المستخدم فقط). */
    public function markRead(Request $request, string $id)
    {
        $user = $request->user();

        // البحث ضمن إشعارات هذا المستخدم فقط → إن لم يكن ملكه: 404
        $notification = $user->notifications()->whereKey($id)->first();
        if (!$notification) {
            return response()->json(['success' => false, 'message' => 'الإشعار غير موجود'], 404);
        }

        $notification->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'تم تعليم الإشعار كمقروء',
            'data' => ['unread_count' => $user->unreadNotifications()->count()],
        ]);
    }

    /** POST /api/notifications/read-all — تعليم كل إشعارات المستخدم كمقروءة. */
    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'تم تعليم كل الإشعارات كمقروءة',
            'data' => ['unread_count' => 0],
        ]);
    }
}
