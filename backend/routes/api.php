<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\TeacherController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\CenterController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\MemorizationController;
use App\Http\Controllers\Api\WeeklyTestController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\StudentRequestController;

// مسارات غير محمية (Public Routes)
Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,1'); // دخول موحّد للأدوار الثلاثة + حدّ 10 محاولات/دقيقة لكل IP (منع التخمين)

// استعادة كلمة المرور عبر الهاتف (OTP) — محدودة المحاولات
Route::post('/auth/forgot-password/request', [AuthController::class, 'forgotPasswordRequest'])->middleware('throttle:5,1');
Route::post('/auth/forgot-password/verify', [AuthController::class, 'forgotPasswordVerify'])->middleware('throttle:10,1');
Route::get('/public/stats', [DashboardController::class, 'publicStats']); // إحصائيات عامة للصفحة الرئيسية
Route::get('/public/demo-accounts', [DashboardController::class, 'demoAccounts']); // حسابات تجريبية لصفحة الدخول

// مسارات محمية بـ Sanctum (Protected Routes)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/user', [AuthController::class, 'user']);

    Route::get('/dashboard', [DashboardController::class, 'index']);

    // الإشعارات داخل التطبيق (كل مستخدم يرى/يعدّل إشعاراته فقط)
    Route::get('/notifications', [\App\Http\Controllers\Api\NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [\App\Http\Controllers\Api\NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{id}/read', [\App\Http\Controllers\Api\NotificationController::class, 'markRead']);

    // فهرس الأثمان — بحث/إكمال تلقائي (قراءة فقط) لمساعدة الحفظ والاختبارات
    Route::get('/athman/search', [\App\Http\Controllers\Api\AthmanController::class, 'search']);
    Route::get('/athman/hizb/{n}', [\App\Http\Controllers\Api\AthmanController::class, 'hizb']);
    Route::get('/athman/{id}', [\App\Http\Controllers\Api\AthmanController::class, 'show']);

    // مسارات ولي الأمر (محميّة بـ parent middleware — دور parent + صلاحية parent)
    Route::middleware('parent')->group(function () {
        Route::get('/parent/children', [StudentController::class, 'parentChildren']);
        Route::get('/parent/students/{id}', [StudentController::class, 'parentStudentDetails']);
    });

    // مسارات مدير المركز (center_manager) — كلها مضيَّقة بمركزه حصراً
    Route::middleware('manager')->group(function () {
        Route::get('/manager/dashboard', [\App\Http\Controllers\Api\CenterManagerController::class, 'dashboard']);
        Route::get('/manager/students', [StudentController::class, 'index']); // مضيَّق بمركزه داخل المتحكم (ترقيم+بحث قائمان)
        Route::get('/manager/teachers', [\App\Http\Controllers\Api\CenterManagerController::class, 'teachers']);
        Route::get('/manager/teachers/{id}', [\App\Http\Controllers\Api\CenterManagerController::class, 'showTeacher']);
        Route::put('/manager/teachers/{id}', [\App\Http\Controllers\Api\CenterManagerController::class, 'updateTeacher']); // لا حذف — لمدير النظام
        Route::post('/manager/attendance/import', [\App\Http\Controllers\Api\AttendanceImportController::class, 'import']); // مضيَّق بمركزه داخل المتحكم
        // طلبات النقل الداخلية لمركزه (from=target=مركزه) — العابرة تبقى لمدير النظام
        Route::get('/manager/student-requests', [StudentRequestController::class, 'managerIndex']);
        Route::post('/manager/student-requests/{id}/approve', [StudentRequestController::class, 'approve']);
        Route::post('/manager/student-requests/{id}/reject', [StudentRequestController::class, 'reject']);
    });

    // مسارات المدير فقط (Admin Only)
    Route::middleware('admin')->group(function () {
        Route::apiResource('teachers', TeacherController::class);
        Route::get('/centers/{id}/has-primary', [TeacherController::class, 'hasPrimary']); // هل للمركز محفّظ أساسي؟
        Route::apiResource('centers', CenterController::class);
        Route::post('/students', [StudentController::class, 'store']); // نقل مسار إضافة طالب ليكون للمدير فقط
        Route::get('/parents/search', [StudentController::class, 'searchParents']); // بحث أولياء الأمور (لاختيار ولي موجود)

        // إدارة مدراء المراكز (مدير واحد لكل مركز كحدّ أقصى)
        Route::get('/admin/managers', [\App\Http\Controllers\Api\ManagerManagementController::class, 'index']);
        Route::post('/admin/managers', [\App\Http\Controllers\Api\ManagerManagementController::class, 'store']);
        Route::put('/admin/managers/{id}', [\App\Http\Controllers\Api\ManagerManagementController::class, 'update']);
        Route::delete('/admin/managers/{id}', [\App\Http\Controllers\Api\ManagerManagementController::class, 'destroy']);

        // جاهزية الأوقاف: الطلاب بلا رقم وطني
        Route::get('/reports/admin/missing-national-id', [ReportController::class, 'missingNationalId']);

        // تقارير المدير (PDF)
        Route::get('/reports/admin/center/{id}/pdf', [\App\Http\Controllers\Api\ReportPdfController::class, 'center']);
        Route::get('/reports/admin/teachers/pdf', [\App\Http\Controllers\Api\ReportPdfController::class, 'teachers']);
        Route::get('/reports/admin/at-risk/pdf', [\App\Http\Controllers\Api\ReportPdfController::class, 'atRisk']);
        Route::get('/reports/admin/overview/pdf', [\App\Http\Controllers\Api\ReportPdfController::class, 'overview']);

        // طلبات الطلاب — مراجعة الأدمن (موافقة/رفض تُنفّذ التغيير الفعلي على students)
        Route::get('/admin/student-requests', [StudentRequestController::class, 'adminIndex']);
        Route::post('/admin/student-requests/{id}/approve', [StudentRequestController::class, 'approve']);
        Route::post('/admin/student-requests/{id}/reject', [StudentRequestController::class, 'reject']);
    });

    // مسارات المعلم والمدير (Teacher & Admin)
    Route::middleware('teacher')->group(function () {
        // «ملفّي الشخصي» — بيانات صاحب التوكن حصراً (لا معرّف من الطلب)
        Route::get('/profile', [\App\Http\Controllers\Api\TeacherProfileController::class, 'show']);

        Route::apiResource('students', StudentController::class)->except(['store']); // استثناء إضافة طالب للمعلم

        // طلبات الطلاب — إنشاء/متابعة من المحفّظ (لا تغيير فعلي على students؛ ينتظر موافقة الأدمن)
        Route::get('/student-requests', [StudentRequestController::class, 'index']);          // طلباتي
        Route::get('/student-requests/search-students', [StudentRequestController::class, 'searchStudents']); // بحث لإنشاء طلب نقل
        Route::post('/student-requests', [StudentRequestController::class, 'store']);         // إنشاء طلب add/transfer

        Route::get('/attendance', [AttendanceController::class, 'index']);
        Route::post('/attendance', [AttendanceController::class, 'store']);
        Route::get('/attendance/report', [AttendanceController::class, 'report']);
        Route::post('/attendance/import', [\App\Http\Controllers\Api\AttendanceImportController::class, 'import']);

        Route::get('/memorizations/surahs', [MemorizationController::class, 'surahs']);
        Route::get('/memorizations/students-progress', [MemorizationController::class, 'studentsProgress']); // تقدّم الطلاب حسب الجزء
        Route::apiResource('memorizations', MemorizationController::class)->only(['index', 'store', 'destroy']);

        Route::apiResource('weekly-tests', WeeklyTestController::class)->only(['index', 'store', 'destroy', 'show']); // تفعيل مسار عرض تفاصيل الاختبار الفردي للأثمان

        Route::get('/reports/weekly', [ReportController::class, 'weekly']);
        Route::get('/reports/student/{id}', [ReportController::class, 'student']);

        // تقارير المعلم (PDF) — الطالب الواحد يحترم حارس «طلابه فقط»
        Route::get('/reports/student/{id}/pdf', [\App\Http\Controllers\Api\ReportPdfController::class, 'student']);
        Route::get('/reports/teacher/pdf', [\App\Http\Controllers\Api\ReportPdfController::class, 'teacherGroup']);
    });
});
