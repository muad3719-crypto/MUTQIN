<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Student;
use App\Models\Center;
use App\Models\Attendance;
use App\Models\Memorization;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            // إحصائيات عامة للمدير
            $stats = [
                'total_teachers'  => User::where('role', 'teacher')->count(),
                'total_students'  => Student::where('is_active', true)->count(),
                'total_centers'   => Center::where('is_active', true)->count(),
                'today_present'   => Attendance::where('date', today())
                                        ->where('status', 'present')->count(),
                'today_absent'    => Attendance::where('date', today())
                                        ->where('status', 'absent')->count(),
                'today_late'      => Attendance::where('date', today())
                                        ->where('status', 'late')->count(),
            ];

            // آخر الطلاب المضافين
            $recentStudents = Student::with(['teacher', 'center'])
                ->latest()
                ->take(5)
                ->get();

            // قائمة المعلمين مع عدد طلابهم
            $teachers = User::where('role', 'teacher')
                ->withCount('students')
                ->get();

            return response()->json([
                'success' => true,
                'role' => 'admin',
                'data' => [
                    'stats' => $stats,
                    'recentStudents' => $recentStudents,
                    'teachers' => $teachers,
                ]
            ]);
        } else {
            // طلاب هذا المعلم
            $students = $user->students()->where('is_active', true)->get();

            $stats = [
                'total_students' => $students->count(),
                'today_present'  => Attendance::whereIn('student_id', $students->pluck('id'))
                                        ->where('date', today())
                                        ->where('status', 'present')->count(),
                'today_absent'   => Attendance::whereIn('student_id', $students->pluck('id'))
                                        ->where('date', today())
                                        ->where('status', 'absent')->count(),
                'today_late'     => Attendance::whereIn('student_id', $students->pluck('id'))
                                        ->where('date', today())
                                        ->where('status', 'late')->count(),
                'this_week_memorizations' => Memorization::whereIn('student_id', $students->pluck('id'))
                                        ->whereBetween('date', [now()->startOfWeek(), now()->endOfWeek()])
                                        ->count(),
            ];

            // هل سُجّل الحضور اليوم؟
            $attendanceToday = Attendance::whereIn('student_id', $students->pluck('id'))
                ->where('date', today())
                ->exists();

            // آخر سجلات الحفظ
            $recentMemorizations = Memorization::whereIn('student_id', $students->pluck('id'))
                ->with('student')
                ->latest()
                ->take(5)
                ->get();

            return response()->json([
                'success' => true,
                'role' => 'teacher',
                'data' => [
                    'stats' => $stats,
                    'students' => $students,
                    'attendanceToday' => $attendanceToday,
                    'recentMemorizations' => $recentMemorizations,
                ]
            ]);
        }
    }

    // حسابات تجريبية (بدون مصادقة) لعرضها في صفحة الدخول — كلمة المرور للجميع: password
    public function demoAccounts()
    {
        // تُعرض الحسابات التجريبية في بيئة التطوير فقط (local أو APP_DEBUG)؛
        // في الإنتاج تُرجع قائمة فارغة حتى لا تتسرّب أسماء/إيميلات المستخدمين.
        if (! app()->environment('local') && ! config('app.debug')) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $users = User::orderByRaw("FIELD(role,'admin','teacher','parent')")
            ->orderBy('id')
            ->get(['name', 'email', 'role']);

        return response()->json([
            'success' => true,
            'data' => $users->map(fn ($u) => [
                'name'  => $u->name,
                'email' => $u->email,
                'role'  => $u->role,
            ]),
        ]);
    }

    // إحصائيات عامة بدون مصادقة للصفحة الرئيسية العامة
    public function publicStats()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'centers' => Center::where('is_active', true)->count(),
                'users' => User::count(),
                'students' => Student::where('is_active', true)->count(),
            ]
        ]);
    }
}
