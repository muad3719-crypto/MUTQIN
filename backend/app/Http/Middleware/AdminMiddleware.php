<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        // يجب أن يكون مديراً، وأن يكون التوكن كامل الصلاحية '*'
        // (توكن ولي الأمر صلاحيته 'parent' فقط فلا يُسمح له بالمرور)
        if (!$user || !$user->isAdmin() || !$user->tokenCan('*')) {
            return response()->json([
                'success' => false,
                'message' => 'هذه الصفحة للمديرين فقط'
            ], 403);
        }
        return $next($request);
    }
}
