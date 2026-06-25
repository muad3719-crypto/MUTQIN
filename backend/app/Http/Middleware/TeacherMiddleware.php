<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TeacherMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        // يجب أن يكون معلماً أو مديراً، وأن يكون التوكن كامل الصلاحية '*'
        // (توكن ولي الأمر صلاحيته 'parent' فقط فلا يُسمح له بالمرور)
        if (!$user || !in_array($user->role, ['teacher', 'admin']) || !$user->tokenCan('*')) {
            return response()->json([
                'success' => false,
                'message' => 'هذه الصفحة للمعلمين فقط'
            ], 403);
        }
        return $next($request);
    }
}
