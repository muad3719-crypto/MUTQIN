<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CenterSupervisorMiddleware
{
    /**
     * يسمح فقط لمستخدم بدور 'center_supervisor' وبتوكن قدرته 'supervisor'
     * وله مركز مرتبط — الفحص المزدوج على نمط ParentMiddleware:
     * توكن مشرف مسروق (قدرته supervisor فقط) لا يبلغ مسارات المدير/المحفّظ ('*')،
     * وتوكن أي دور آخر يفشل هنا بفحص الدور حتى لو حمل قدرة '*'.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user || !$user->isCenterSupervisor() || !$user->tokenCan('supervisor') || !$user->center_id) {
            return response()->json([
                'success' => false,
                'message' => 'هذه الصفحة لمشرفي المراكز فقط'
            ], 403);
        }

        return $next($request);
    }
}
