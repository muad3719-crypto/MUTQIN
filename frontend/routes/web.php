
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ParentController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboard;
use App\Http\Controllers\Admin\TeacherController;
use App\Http\Controllers\Admin\CenterController;
use App\Http\Controllers\Admin\StudentController as AdminStudentController;
use App\Http\Controllers\Admin\AttendanceController as AdminAttendanceController;
use App\Http\Controllers\Teacher\DashboardController as TeacherDashboard;
use App\Http\Controllers\Teacher\StudentController;
use App\Http\Controllers\Teacher\AttendanceController;
use App\Http\Controllers\Teacher\MemorizationController;
use App\Http\Controllers\Teacher\WeeklyTestController;
use App\Http\Controllers\Teacher\ReportController;

// =============================================
// الصفحة الرئيسية العامة (بدون مصادقة)
// =============================================
Route::get('/', [HomeController::class, 'index'])->name('home');

// =============================================
// مسارات تسجيل الدخول (بدون مصادقة)
// =============================================
Route::middleware('guest')->group(function () {
    Route::get('/login',  [LoginController::class, 'showLogin'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->name('login.post');
});

// تسجيل الخروج
Route::post('/logout', [LoginController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

// توجيه عام للداشبورد بعد تسجيل الدخول
Route::get('/dashboard', function () {
    if (session('api_user.role') === 'parent') {
        return redirect('/parent/dashboard');
    }
    if (auth()->user()->isAdmin()) {
        return redirect()->route('admin.dashboard');
    }
    return redirect()->route('teacher.dashboard');
})->middleware('auth')->name('dashboard');

// =============================================
// مسارات ولي الأمر
// =============================================
Route::prefix('parent')->middleware('auth.parent')->name('parent.')->group(function () {
    Route::get('/dashboard', [ParentController::class, 'dashboard'])->name('dashboard');
    Route::get('/child/{id}', [ParentController::class, 'showChild'])->name('child');
});

// =============================================
// مسارات المدير
// =============================================
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {

    // لوحة تحكم المدير
    Route::get('/dashboard', [AdminDashboard::class, 'index'])->name('dashboard');

    // إدارة المعلمين
    Route::resource('teachers', TeacherController::class);

    // إدارة المراكز
    Route::resource('centers', CenterController::class);

    // إدارة الطلاب
    Route::resource('students', AdminStudentController::class);

    // استيراد الحضور من البصمة
    Route::get('/attendance/import', [AdminAttendanceController::class, 'showImportForm'])->name('attendance.import');
    Route::post('/attendance/import', [AdminAttendanceController::class, 'import'])->name('attendance.import.post');
});

// =============================================
// مسارات المعلم
// =============================================
Route::middleware(['auth', 'teacher'])->prefix('teacher')->name('teacher.')->group(function () {

    // لوحة تحكم المعلم
    Route::get('/dashboard', [TeacherDashboard::class, 'index'])->name('dashboard');

    // إدارة الطلاب
    Route::resource('students', StudentController::class)->only(['index', 'show']); // حصر صلاحيات المعلم في عرض الطلاب وتفاصيلهم فقط دون إمكانية الإضافة

    // الحضور والغياب
    Route::get('/attendance',          [AttendanceController::class, 'index'])->name('attendance.index');
    Route::post('/attendance',         [AttendanceController::class, 'store'])->name('attendance.store');
    Route::get('/attendance/report',   [AttendanceController::class, 'report'])->name('attendance.report');
    Route::get('/attendance/import',   [AdminAttendanceController::class, 'showImportForm'])->name('attendance.import');
    Route::post('/attendance/import',  [AdminAttendanceController::class, 'import'])->name('attendance.import.post');

    // تتبع الحفظ
    Route::resource('memorizations', MemorizationController::class)->except(['edit', 'update', 'show']);

    // الاختبارات الأسبوعية
    Route::resource('tests', WeeklyTestController::class)->except(['edit', 'update']); // تمكين مسار تفاصيل الاختبار والتدمير تلقائياً من الـ Resource

    // التقارير
    Route::get('/reports/weekly',           [ReportController::class, 'weekly'])->name('reports.weekly');
    Route::get('/reports/student/{student}',[ReportController::class, 'student'])->name('reports.student');
});
