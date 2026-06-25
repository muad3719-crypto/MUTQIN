<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ParentMiddleware
{
    /**
     * يسمح فقط لمستخدم بدور 'parent' وبتوكن صلاحيته 'parent'.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user || !$user->isParent() || !$user->tokenCan('parent')) {
            return response()->json([
                'success' => false,
                'message' => 'هذه الصفحة لأولياء الأمور فقط'
            ], 403);
        }

        return $next($request);
    }
}
