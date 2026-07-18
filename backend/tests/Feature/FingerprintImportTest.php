<?php

namespace Tests\Feature;

use App\Models\Attendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * استيراد حضور البصمة بكود الطالب:
 * المطابقة بـdisplay_code حصراً (121/S121)، الاسم كاشف خطأ غير مُعطِّل،
 * التكرار يُحدِّث، وطالب خارج مركز المستورِد يُرفض برسالة.
 */
class FingerprintImportTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    /** يبني ملف xlsx بصمة مؤقتاً بالترويسة المعتمدة ويعيده كملف مرفوع. */
    private function xlsx(array $dataRows): UploadedFile
    {
        $rows = array_merge([['رقم الطالب', 'الاسم', 'التاريخ', 'الوقت', 'الحالة']], $dataRows);
        $ss = new Spreadsheet();
        $ws = $ss->getActiveSheet();
        foreach ($rows as $i => $r) {
            $ws->fromArray($r, null, 'A' . ($i + 1));
        }
        $path = tempnam(sys_get_temp_dir(), 'fp_') . '.xlsx';
        (new Xlsx($ss))->save($path);

        return new UploadedFile($path, 'attendance.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function import(string $token, UploadedFile $file, string $path = '/api/attendance/import')
    {
        return $this->authed($token)->post($path, ['attendance_file' => $file]);
    }

    public function test_both_plain_and_prefixed_numbers_match_same_student(): void
    {
        $teacher = $this->makeTeacher();
        $s1 = $this->makeStudent($teacher); // S1
        $s2 = $this->makeStudent($teacher); // S2
        $token = $this->loginToken($teacher);

        $r = $this->import($token, $this->xlsx([
            ['1',  $s1->name, '2026-07-15', '07:00', 'حاضر'],   // رقم مجرد
            ['S2', $s2->name, '2026-07-15', '07:05', 'متأخر'],  // بادئة S
        ]))->assertOk();

        $this->assertSame(2, $r->json('imported'));
        $this->assertSame('present', Attendance::where('student_id', $s1->id)->where('date', '2026-07-15')->value('status'));
        $this->assertSame('late',    Attendance::where('student_id', $s2->id)->where('date', '2026-07-15')->value('status'));
    }

    public function test_unknown_number_is_rejected_with_arabic_reason(): void
    {
        $teacher = $this->makeTeacher();
        $this->makeStudent($teacher);
        $token = $this->loginToken($teacher);

        $r = $this->import($token, $this->xlsx([
            ['9999', 'مجهول', '2026-07-15', '07:00', 'حاضر'],
        ]))->assertOk();

        $this->assertSame(0, $r->json('imported'));
        $this->assertSame(1, $r->json('skipped'));
        $this->assertSame(9999, $r->json('errors.0.number'));
        $this->assertStringContainsString('غير مسجّل في النظام', $r->json('errors.0.reason'));
    }

    public function test_name_mismatch_imports_and_appears_in_warnings(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent($teacher, null, ['name' => 'حذيفة سالم الرقيعي']); // S1
        $token = $this->loginToken($teacher);

        $r = $this->import($token, $this->xlsx([
            ['1', 'أحمد محمد الغرياني', '2026-07-15', '07:00', 'حاضر'], // اسم مختلف جوهرياً
        ]))->assertOk();

        // القرار المعتمد: يُستورد على كل حال (الرقم أساس المطابقة) ويُحذَّر
        $this->assertSame(1, $r->json('imported'));
        $this->assertCount(1, $r->json('name_warnings'));
        $this->assertSame(1, $r->json('name_warnings.0.number'));
        $this->assertSame('أحمد محمد الغرياني', $r->json('name_warnings.0.file_name'));
        $this->assertSame('حذيفة سالم الرقيعي', $r->json('name_warnings.0.system_name'));
        $this->assertSame(1, Attendance::where('student_id', $student->id)->count());
    }

    public function test_truncated_prefix_name_passes_without_warning(): void
    {
        $teacher = $this->makeTeacher();
        $this->makeStudent($teacher, null, ['name' => 'حذيفة سالم الرقيعي']); // S1
        $token = $this->loginToken($teacher);

        $r = $this->import($token, $this->xlsx([
            ['1', 'حذيفه سالم', '2026-07-15', '07:00', 'حاضر'], // مبتور + تاء مربوطة→هاء
        ]))->assertOk();

        $this->assertSame(1, $r->json('imported'));
        $this->assertCount(0, $r->json('name_warnings'));
    }

    public function test_duplicate_same_day_updates_instead_of_duplicating(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent($teacher); // S1
        $token = $this->loginToken($teacher);

        $this->import($token, $this->xlsx([
            ['1', $student->name, '2026-07-15', '07:00', 'حاضر'],
        ]))->assertOk();

        $this->app['auth']->forgetGuards();
        $r2 = $this->import($token, $this->xlsx([
            ['1', $student->name, '2026-07-15', '07:40', 'متأخر'],
        ]))->assertOk();

        $rows = Attendance::where('student_id', $student->id)->where('date', '2026-07-15')->get();
        $this->assertCount(1, $rows);                       // صف واحد — لا تضعيف
        $this->assertSame('late', $rows->first()->status);  // حُدّث
        $this->assertSame(1, $r2->json('updated'));
        $this->assertSame(0, $r2->json('imported_new'));
    }

    public function test_out_of_center_student_is_rejected_for_manager(): void
    {
        $centerA = $this->makeCenter(['name' => 'مركز أ']);
        $centerB = $this->makeCenter(['name' => 'مركز ب']);
        $teacherA = $this->makeTeacher($centerA);
        $studentA = $this->makeStudent($teacherA); // S1 في المركز أ
        $managerB = $this->makeManager($centerB);
        $token = $this->loginToken($managerB);

        $r = $this->import($token, $this->xlsx([
            ['1', $studentA->name, '2026-07-15', '07:00', 'حاضر'],
        ]), '/api/manager/attendance/import')->assertOk();

        $this->assertSame(0, $r->json('imported'));
        $this->assertSame(1, $r->json('skipped'));
        $this->assertStringContainsString('خارج نطاق صلاحيتك', $r->json('errors.0.reason'));
        $this->assertSame(0, Attendance::count()); // لا سجل ولا غياب محتسب لطالب المركز الآخر
    }
}
