<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Student;
use App\Models\Attendance;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AttendanceImportController extends Controller
{
    public function import(Request $request)
    {
        // 1. التحقق من صحة الملف المرفوع
        $request->validate([
            'attendance_file' => 'required|file|mimes:xlsx|max:5120', // الحد الأقصى 5 ميجابايت
        ], [
            'attendance_file.required' => 'يرجى اختيار ملف الحضور أولاً.',
            'attendance_file.file'     => 'الرجاء رفع ملف صحيح.',
            'attendance_file.mimes'    => 'يجب أن يكون الملف بصيغة Excel (.xlsx) فقط.',
            'attendance_file.max'      => 'حجم الملف يجب ألا يتجاوز 5 ميجابايت.',
        ]);

        $file = $request->file('attendance_file');

        try {
            // 2. تحميل وقراءة ملف Excel
            $spreadsheet = IOFactory::load($file->getRealPath());
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();
        } catch (\Exception $e) {
            Log::error('Excel load fatal error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'عفواً، فشل تحميل ملف Excel. يرجى التأكد من سلامة وصيغة الملف.',
            ], 422);
        }

        if (empty($rows)) {
            return response()->json([
                'success' => false,
                'message' => 'الملف المرفوع فارغ ولا يحتوي على أي بيانات.',
            ], 422);
        }

        // 3. التحقق من أعمدة الترويسة (Header) باللغة العربية
        $header = array_shift($rows);

        if (!$header || count($header) < 5 ||
            trim($header[0] ?? '') !== 'رقم الطالب' ||
            trim($header[1] ?? '') !== 'الاسم' ||
            trim($header[2] ?? '') !== 'التاريخ' ||
            trim($header[3] ?? '') !== 'الوقت' ||
            trim($header[4] ?? '') !== 'الحالة') {
            return response()->json([
                'success' => false,
                'message' => 'عفواً، ترويسة ملف Excel غير مطابقة للمواصفات المطلوبة. يجب أن تكون الأعمدة بالترتيب: رقم الطالب | الاسم | التاريخ | الوقت | الحالة.',
            ], 422);
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $user = $request->user();

        // عدّادات الملخّص
        $present = 0;
        $late = 0;
        $absentFromFile = 0;
        $absentComputed = 0;
        $ignoredOther = 0;          // طلاب محفّظين آخرين — مُتجاهَلون بصمت

        // تتبّع لاحتساب الغائبين ضمن النطاق
        $datesInFile = [];          // [date => true]
        $seenByDate  = [];          // [date => [student_id => true]]
        $centerIds   = [];          // مراكز الطلاب الظاهرين (لنطاق المدير)

        // 4. معالجة الصفوف
        foreach ($rows as $index => $row) {
            $rowNum = $index + 2; // الصف 1 هو الترويسة، لذا الصفوف تبدأ من 2

            // التحقق من الصف الفارغ تماماً
            $isEmpty = true;
            foreach ($row as $cell) {
                if ($cell !== null && trim($cell) !== '') {
                    $isEmpty = false;
                    break;
                }
            }
            if ($isEmpty) {
                continue;
            }

            if (count($row) < 5) {
                $skipped++;
                $errors[] = [
                    'row' => $rowNum,
                    'reason' => 'بيانات الصف غير مكتملة، يرجى ملء كافة الأعمدة.',
                ];
                continue;
            }

            $studentIdRaw = $row[0];
            $studentName  = trim($row[1] ?? '');
            $dateRaw      = $row[2];
            $timeRaw      = $row[3];
            $statusRaw    = trim($row[4] ?? '');

            // 1. التحقق من وجود رقم الطالب
            if (empty($studentIdRaw)) {
                $skipped++;
                $errors[] = [
                    'row' => $rowNum,
                    'reason' => 'حقل رقم الطالب فارغ.',
                ];
                continue;
            }

            // مطابقة رقم البصمة: بالرقم الوطني أولاً (وهو رقم تسجيل البصمة الرسمي)،
            // ثم الرجوع لمعرّف الطالب id إن لم يوجد رقم وطني مطابق.
            $idRaw = trim((string) $studentIdRaw);
            $student = Student::where('national_id', $idRaw)->first();
            if (!$student && is_numeric($idRaw)) {
                $student = Student::find((int) $idRaw);
            }

            if (!$student) {
                $skipped++;
                $errors[] = [
                    'row' => $rowNum,
                    'reason' => "رقم الطالب غير موجود: {$idRaw}",
                ];
                continue;
            }

            // 2. التصفية الصامتة: المحفّظ قد يرفع ملف المركز كاملاً (جهاز بصمة واحد).
            //    خارج النطاق يُتجاهَل بهدوء — النطاق: المدير=الكل، مشرف المركز=مركزه، المحفّظ=طلابه.
            $inScope = $user->isAdmin()
                || ($user->isCenterSupervisor() && $student->center_id === $user->center_id)
                || $student->teacher_id === $user->id;
            if (!$inScope) {
                $ignoredOther++;
                continue;
            }

            // 3. تحليل ومعالجة التاريخ (يدعم DD/MM/YYYY و YYYY-MM-DD وتواريخ Excel الرقمية)
            $date = null;
            if (is_numeric($dateRaw)) {
                try {
                    $date = ExcelDate::excelToDateTimeObject($dateRaw)->format('Y-m-d');
                } catch (\Exception $e) {
                    $date = null;
                }
            } else {
                $dateStr = trim($dateRaw ?? '');
                // DD/MM/YYYY
                if (preg_match('/^\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4}$/', $dateStr)) {
                    $parts = preg_split('/[\/\-]/', $dateStr);
                    $date = sprintf('%04d-%02d-%02d', $parts[2], $parts[1], $parts[0]);
                }
                // YYYY-MM-DD
                elseif (preg_match('/^\d{4}[\/\-]\d{2}[\/\-]\d{2}$/', $dateStr)) {
                    $parts = preg_split('/[\/\-]/', $dateStr);
                    $date = sprintf('%04d-%02d-%02d', $parts[0], $parts[1], $parts[2]);
                } else {
                    try {
                        $date = Carbon::parse($dateStr)->toDateString();
                    } catch (\Exception $e) {
                        $date = null;
                    }
                }
            }

            if (!$date) {
                $skipped++;
                $errors[] = [
                    'row' => $rowNum,
                    'reason' => "تنسيق التاريخ غير صحيح: " . ($dateRaw ?? 'فارغ'),
                ];
                continue;
            }

            // 4. تحليل ومعالجة الوقت (يدعم القيم الرقمية وتنسيقات النص)
            $time = null;
            if (is_numeric($timeRaw)) {
                try {
                    $time = ExcelDate::excelToDateTimeObject($timeRaw)->format('H:i:s');
                } catch (\Exception $e) {
                    $time = null;
                }
            } else {
                $timeStr = trim($timeRaw ?? '');
                if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $timeStr)) {
                    $time = $timeStr;
                }
            }

            // 5. تحليل ومعالجة الحالة وترجمتها
            $statusMap = [
                'حاضر'   => 'present',
                'غائب'   => 'absent',
                'متأخر'  => 'late',
            ];

            if (!isset($statusMap[$statusRaw])) {
                $skipped++;
                $errors[] = [
                    'row' => $rowNum,
                    'reason' => "الحالة غير معروفة: '{$statusRaw}' (المقبول: حاضر، غائب، متأخر).",
                ];
                continue;
            }

            $status = $statusMap[$statusRaw];

            // 6. الحفظ أو التحديث بقاعدة البيانات (Upsert)
            try {
                Attendance::updateOrCreate(
                    [
                        'student_id' => $student->id,
                        'date'       => $date,
                    ],
                    [
                        'teacher_id'  => $user->isTeacher() ? $user->id : ($student->teacher_id ?? $user->id),
                        'center_id'   => $user->center_id ?? $student->center_id,
                        'time'        => $time,
                        'status'      => $status,
                        'notes'       => 'حضور مستورد من جهاز البصمة' . ($time ? " الساعة {$time}" : ''),
                        'imported_at' => now(),
                    ]
                );
                $imported++;

                // إحصاء حسب الحالة + تتبّع لاحتساب الغائبين
                if ($status === 'present')      $present++;
                elseif ($status === 'late')     $late++;
                elseif ($status === 'absent')   $absentFromFile++;

                $datesInFile[$date] = true;
                $seenByDate[$date][$student->id] = true;
                if ($student->center_id) $centerIds[$student->center_id] = true;
            } catch (\Exception $e) {
                Log::error("Failed to save row {$rowNum}: " . $e->getMessage());
                $skipped++;
                $errors[] = [
                    'row' => $rowNum,
                    'reason' => 'فشل الحفظ في قاعدة البيانات بسبب خطأ تقني.',
                ];
            }
        }

        // ==========================================================
        // 5. احتساب الغائبين تلقائياً — محصور في نطاق المُرفِّع وتواريخ الملف
        //    أي طالب ضمن النطاق لم يظهر في الملف بذلك التاريخ → غائب
        //    (firstOrCreate: لا نكتب فوق سجل موجود مسبقاً)
        // ==========================================================
        foreach (array_keys($datesInFile) as $date) {
            // تحديد نطاق الطلاب
            $scopeQuery = Student::where('is_active', true);
            if ($user->isAdmin()) {
                // المدير: كل طلاب المراكز التي ظهرت في الملف
                $scopeQuery->whereIn('center_id', array_keys($centerIds) ?: [-1]);
            } elseif ($user->isCenterSupervisor()) {
                // مشرف المركز: طلاب مركزه فقط
                $scopeQuery->where('center_id', $user->center_id);
            } else {
                // المحفّظ: طلابه فقط — لا يمسّ طلاب محفّظين آخرين إطلاقاً
                $scopeQuery->where('teacher_id', $user->id);
            }

            foreach ($scopeQuery->get() as $st) {
                if (isset($seenByDate[$date][$st->id])) {
                    continue; // ظهر في الملف — لا يُحتسب غائباً
                }

                $record = Attendance::firstOrCreate(
                    [
                        'student_id' => $st->id,
                        'date'       => $date,
                    ],
                    [
                        'teacher_id'  => $user->isTeacher() ? $user->id : ($st->teacher_id ?? $user->id),
                        'center_id'   => $st->center_id,
                        'status'      => 'absent',
                        'notes'       => 'غياب محتسب تلقائياً (لم يظهر في ملف البصمة)',
                        'imported_at' => now(),
                    ]
                );

                if ($record->wasRecentlyCreated) {
                    $absentComputed++;
                }
            }
        }

        // ==========================================================
        // 6. ملخّص نظيف — أخطاء حقيقية فقط، والتجاهل الصامت كمعلومة هادئة
        // ==========================================================
        return response()->json([
            'success'                => true,
            'imported'               => $imported,                    // صفوف الملف المحفوظة
            'present'                => $present,
            'late'                   => $late,
            'absent'                 => $absentFromFile + $absentComputed, // إجمالي الغائبين ضمن النطاق
            'absent_computed'        => $absentComputed,               // محتسبون تلقائياً
            'ignored_other_teachers' => $ignoredOther,                 // مُتجاهَلون بصمت (ليسوا أخطاء)
            'skipped'                => count($errors),                // أخطاء حقيقية فقط
            'errors'                 => $errors,                       // أخطاء حقيقية فقط
        ]);
    }
}
