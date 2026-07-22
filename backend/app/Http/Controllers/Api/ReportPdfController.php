<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Center;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Carbon\Carbon;

/**
 * توليد تقارير PDF عربية RTL (محرّك mPDF).
 * يعيد استخدام ReportService للتجميع — لا تكرار للمنطق.
 */
class ReportPdfController extends Controller
{
    public function __construct(protected ReportService $reports) {}

    // ====== أدوات مشتركة ======

    protected function period(Request $request): array
    {
        $month = (int) $request->get('month', now()->month);
        $year  = (int) $request->get('year', now()->year);
        return [$month, $year];
    }

    protected function periodLabel(int $month, int $year): string
    {
        return Carbon::create($year, $month, 1)->locale('ar')->isoFormat('MMMM YYYY');
    }

    /**
     * تحويل قالب Blade إلى PDF وإرجاعه inline للعرض/التنزيل في المتصفح.
     */
    protected function render(string $view, array $data, int $month, int $year, string $filename)
    {
        $tempDir = storage_path('app/mpdf');
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $html = view($view, [
            'd'           => $data,
            'periodLabel' => $this->periodLabel($month, $year),
            'generatedAt' => now()->format('Y/m/d H:i'),
        ])->render();

        $mpdf = new \Mpdf\Mpdf([
            'mode'              => 'utf-8',
            'format'            => 'A4',
            'default_font_size' => 11,
            'tempDir'           => $tempDir,
            'margin_top'        => 14,
            'margin_bottom'     => 14,
            'margin_left'       => 12,
            'margin_right'      => 12,
        ]);
        $mpdf->SetDirectionality('rtl');
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont   = true;
        $mpdf->WriteHTML($html);

        return response(
            $mpdf->Output($filename, \Mpdf\Output\Destination::STRING_RETURN),
            200,
            [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $filename . '"',
            ]
        );
    }

    // ====== تقارير المعلم ======

    /** تقرير طالب واحد — يحترم حارس «طلابه فقط». */
    public function student(Request $request, $id)
    {
        $user = $request->user();
        $student = Student::findOrFail($id);

        if (!$user->isAdmin() && $student->teacher_id !== $user->id) {
            abort(403, 'غير مصرح لك بتقرير هذا الطالب');
        }

        [$month, $year] = $this->period($request);
        $data = $this->reports->studentData($student, $month, $year);

        return $this->render('pdf.student', $data, $month, $year, "student-report-{$id}.pdf");
    }

    /** تقرير كل طلاب المعلّم. */
    public function teacherGroup(Request $request)
    {
        $user = $request->user();
        [$month, $year] = $this->period($request);
        $data = $this->reports->teacherGroupData($user, $month, $year);

        return $this->render('pdf.teacher-group', $data, $month, $year, 'my-students-report.pdf');
    }

    // ====== تقارير المدير ======

    /** تقرير مركز شامل — {id} رقمي لمركز واحد، أو "all" لكل المراكز. */
    public function center(Request $request, $id)
    {
        [$month, $year] = $this->period($request);

        if ($id === 'all') {
            $data = $this->reports->allCentersData($month, $year);
            return $this->render('pdf.admin.center', $data, $month, $year, 'centers-report.pdf');
        }

        $center = Center::findOrFail($id);
        $data = $this->reports->centerData($center, $month, $year);
        return $this->render('pdf.admin.center', $data, $month, $year, "center-report-{$id}.pdf");
    }

    /** تقرير أداء المعلمين. */
    public function teachers(Request $request)
    {
        [$month, $year] = $this->period($request);
        $data = $this->reports->teachersPerformance($month, $year);
        return $this->render('pdf.admin.teachers', $data, $month, $year, 'teachers-performance.pdf');
    }

    /** تقرير الطلاب المتعثّرين. */
    public function atRisk(Request $request)
    {
        [$month, $year] = $this->period($request);
        $data = $this->reports->atRiskStudents($month, $year);
        return $this->render('pdf.admin.at-risk', $data, $month, $year, 'at-risk-students.pdf');
    }

    /** تقرير الإحصائيات العامة. */
    public function overview(Request $request)
    {
        [$month, $year] = $this->period($request);
        $data = $this->reports->overview($month, $year);
        return $this->render('pdf.admin.overview', $data, $month, $year, 'overview-report.pdf');
    }

    // ====== تقارير مدير المركز (نفس القوالب، النطاق مفروض من الحساب) ======

    /** تقرير مركزه الشامل — center_id من الحساب، لا من الطلب. */
    public function managerCenter(Request $request)
    {
        [$month, $year] = $this->period($request);
        $center = Center::findOrFail($request->user()->center_id);
        $data = $this->reports->centerData($center, $month, $year);
        return $this->render('pdf.admin.center', $data, $month, $year, 'my-center-report.pdf');
    }

    /** متعثّرو مركزه فقط. */
    public function managerAtRisk(Request $request)
    {
        [$month, $year] = $this->period($request);
        $data = $this->reports->atRiskStudents($month, $year, $request->user()->center_id);
        return $this->render('pdf.admin.at-risk', $data, $month, $year, 'my-center-at-risk.pdf');
    }

    /** أداء محفّظي مركزه فقط. */
    public function managerTeachers(Request $request)
    {
        [$month, $year] = $this->period($request);
        $data = $this->reports->teachersPerformance($month, $year, $request->user()->center_id);
        return $this->render('pdf.admin.teachers', $data, $month, $year, 'my-center-teachers.pdf');
    }
}
