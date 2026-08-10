<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Student;
use App\Models\Attendance;
use App\Support\ArabicText;
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
        $importedNew = 0;     // سجلات حضور أُنشئت لأول مرة
        $updated = 0;         // حضور كان مسجّلاً فحُدّث (القاعدة المعتمدة: تحديث لا تضعيف)
        $skipped = 0;
        $errors = [];
        $nameWarnings = [];   // اختلاف الاسم: يُستورد ويُحذَّر (قرار معتمد — لا إيقاف)
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

        // 4+5. المعالجة كلها (صفوف الملف + الغياب المحتسب) داخل transaction
        // واحدة: فشل جزئي في المنتصف لا يترك حضور يومٍ نصف مكتوب.
        \Illuminate\Support\Facades\DB::transaction(function () use (
            $rows, $user,
            &$imported, &$importedNew, &$updated, &$skipped, &$errors, &$nameWarnings,
            &$present, &$late, &$absentFromFile, &$absentComputed, &$ignoredOther,
            &$datesInFile, &$seenByDate, &$centerIds
        ) {

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
                    'number' => null, 'name' => null,
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
                    'number' => null, 'name' => $studentName,
                    'reason' => 'حقل رقم الطالب فارغ.',
                ];
                continue;
            }

            // القرار المعتمد: رقم المستخدم في جهاز البصمة = الجزء الرقمي من كود
            // العرض (الطالب S121 يُسجَّل في الجهاز بالرقم 121). المطابقة بكود
            // العرض «حصراً» — أُلغي مسارا الرقم الوطني وmعرّف قاعدة البيانات
            // (كان الثاني ينسب الحضور لطالب خاطئ بصمت عند افتراق الترقيمين).
            // تُقبل الصيغ: 121 وS121/s121 وبالأرقام العربية أيضاً.
            $idRaw  = trim((string) $studentIdRaw);
            $digits = strtr($idRaw, ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
            if (!preg_match('/^\s*[sS]?\s*(\d+)\s*$/u', $digits, $m)) {
                $skipped++;
                $errors[] = [
                    'row' => $rowNum,
                    'number' => $idRaw, 'name' => $studentName,
                    'reason' => "صيغة رقم الطالب غير مفهومة: {$idRaw} (المقبول: 121 أو S121)",
                ];
                continue;
            }
            $deviceNum = (int) $m[1];
            $student = Student::where('display_code', 'S' . $deviceNum)->first();

            if (!$student) {
                $skipped++;
                $errors[] = [
                    'row' => $rowNum,
                    'number' => $deviceNum, 'name' => $studentName,
                    'reason' => "الرقم {$deviceNum} غير مسجّل في النظام",
                ];
                continue;
            }

            // الطالب الموقوف خارج المنظومة اليومية: بصمته لا تُسجَّل حضوراً
            // (احتساب الغياب يستثنيه أصلاً، فقبولُ بصمته كان التناقض الوحيد).
            // يظهر كخطأ صريح ليعرف المستورِد لماذا نقص صفٌّ من ملفه.
            if (!$student->is_active) {
                $skipped++;
                $errors[] = [
                    'row'    => $rowNum,
                    'number' => $deviceNum, 'name' => $studentName,
                    'reason' => "الطالب {$student->display_code} ({$student->name}) موقوف — لم يُسجَّل حضوره",
                ];
                continue;
            }

            // 2. النطاق (فحص في الباك لا الواجهة): طالب خارج «مركز» المستورِد
            //    = رفض صريح برسالة عربية (مدير المركز والمحفّظ كلاهما). أما طالب
            //    من نفس المركز لكن عند محفّظ آخر فيُتجاهل بصمت — جهاز البصمة
            //    واحد للمركز كله وهذا السيناريو طبيعي لا خطأ.
            if (!$user->isAdmin() && $student->center_id !== $user->center_id) {
                $skipped++;
                $errors[] = [
                    'row'    => $rowNum,
                    'number' => $deviceNum, 'name' => $studentName,
                    'reason' => "الطالب {$student->display_code} ({$student->name}) من مركز آخر — خارج نطاق صلاحيتك",
                ];
                continue;
            }
            if ($user->isTeacher() && $student->teacher_id !== $user->id) {
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
                    'number' => $deviceNum, 'name' => $studentName,
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
                    'number' => $deviceNum, 'name' => $studentName,
                    'reason' => "الحالة غير معروفة: '{$statusRaw}' (المقبول: حاضر، غائب، متأخر).",
                ];
                continue;
            }

            $status = $statusMap[$statusRaw];

            // 5.ب تحقق الاسم — «أداة كشف خطأ، لا أداة مطابقة»: الرقم هو أساس
            // المطابقة الوحيد، والصف يُستورد على كل حال. اختلاف الاسم (بعد
            // التطبيع، مع تسامح بادئة ≥ كلمتين لأن أجهزة البصمة تبتر الأسماء
            // الطويلة) يُدرَج في قسم تحذيرات بارز — لأنه غالباً خطأ إدخال في
            // الجهاز يتكرر في كل رفعة، لا مجرد صف تالف.
            if (!$this->namesMatch($studentName, $student->name)) {
                $nameWarnings[] = [
                    'row'         => $rowNum,
                    'number'      => $deviceNum,
                    'file_name'   => $studentName,
                    'system_name' => $student->name,
                ];
            }

            // 6. الحفظ أو التحديث بقاعدة البيانات (Upsert)
            try {
                $rec = Attendance::updateOrCreate(
                    [
                        'student_id' => $student->id,
                        'date'       => $date,
                    ],
                    [
                        'teacher_id'  => $user->isTeacher() ? $user->id : ($student->teacher_id ?? $user->id),
                        'center_id'   => $student->center_id, // مركز الطالب دائماً — الأدق عند النقل بين المراكز
                        'time'        => $time,
                        'status'      => $status,
                        'notes'       => 'حضور مستورد من جهاز البصمة' . ($time ? " الساعة {$time}" : ''),
                        'imported_at' => now(),
                    ]
                );
                $imported++;
                if ($rec->wasRecentlyCreated) { $importedNew++; } else { $updated++; }

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
                    'number' => $deviceNum, 'name' => $studentName,
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
            } elseif ($user->isCenterManager()) {
                // مدير المركز: طلاب مركزه فقط
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

        }); // نهاية الـ transaction

        // ==========================================================
        // 6. ملخّص نظيف — أخطاء حقيقية فقط، والتجاهل الصامت كمعلومة هادئة
        // ==========================================================
        return response()->json([
            'success'                => true,
            'imported'               => $imported,                    // صفوف الملف المحفوظة (جديد + محدَّث)
            'imported_new'           => $importedNew,                 // سجلات جديدة
            'updated'                => $updated,                     // حضور كان مسجّلاً فحُدّث
            'present'                => $present,
            'late'                   => $late,
            'absent'                 => $absentFromFile + $absentComputed, // إجمالي الغائبين ضمن النطاق
            'absent_computed'        => $absentComputed,               // محتسبون تلقائياً
            'ignored_other_teachers' => $ignoredOther,                 // مُتجاهَلون بصمت (ليسوا أخطاء)
            'skipped'                => count($errors),                // أخطاء حقيقية فقط
            'errors'                 => $errors,                       // أخطاء حقيقية فقط
            'name_warnings'          => $nameWarnings,                 // اختلاف الاسم — استُورد مع تحذير
        ]);
    }

    /**
     * مقارنة اسم الملف باسم النظام بعد تطبيع ArabicText (همزات/تشكيل/تاء
     * مربوطة/مسافات)، مع تسامح البادئة: أجهزة البصمة تبتر الأسماء الطويلة،
     * فالأقصر يُعد مطابقاً إن كان بادئةً للأطول عند حدود الكلمات وبطول
     * كلمتين على الأقل («حذيفة سالم» تطابق «حذيفة سالم الرقيعي»).
     * اسم فارغ في الملف = لا يمكن التحقق → يمرّ بلا تحذير.
     */
    private function namesMatch(string $fileName, string $systemName): bool
    {
        $a = ArabicText::normalize($fileName);
        $b = ArabicText::normalize($systemName);

        if ($a === '' || $a === $b) {
            return true;
        }

        [$short, $long] = mb_strlen($a) <= mb_strlen($b) ? [$a, $b] : [$b, $a];
        if (count(array_filter(explode(' ', $short))) < 2) {
            return false; // كلمة واحدة لا تكفي للتسامح — تحذير
        }

        return str_starts_with($long, $short . ' ');
    }
}
