<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CenterManagerMiddleware
{
    /**
     * يسمح فقط لمستخدم بدور 'center_manager' وبتوكن قدرته 'manager'
     * وله مركز مرتبط — الفحص المزدوج على نمط ParentMiddleware:
     * توكن مدير مركز مسروق (قدرته manager فقط) لا يبلغ مسارات المدير/المحفّظ ('*')،
     * وتوكن أي دور آخر يفشل هنا بفحص الدور حتى لو حمل قدرة '*'.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user || !$user->isCenterManager() || !$user->tokenCan('manager') || !$user->center_id) {
            return response()->json([
                'success' => false,
                'message' => 'هذه الصفحة لمدراء المراكز فقط'
            ], 403);
        }

        return $next($request);
    }
}
