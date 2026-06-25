<?php
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Set headers
$sheet->setCellValue('A1', 'رقم الطالب');
$sheet->setCellValue('B1', 'الاسم');
$sheet->setCellValue('C1', 'التاريخ');
$sheet->setCellValue('D1', 'الوقت');
$sheet->setCellValue('E1', 'الحالة');

// Add rows
// Row 2: Valid student 1
$sheet->setCellValue('A2', 1);
$sheet->setCellValue('B2', 'يوسف عبدالله الطاهر');
$sheet->setCellValue('C2', '20/06/2026');
$sheet->setCellValue('D2', '08:30');
$sheet->setCellValue('E2', 'حاضر');

// Row 3: Valid student 2
$sheet->setCellValue('A3', 2);
$sheet->setCellValue('B3', 'إبراهيم سالم الشيباني');
$sheet->setCellValue('C3', '2026-06-20');
$sheet->setCellValue('D3', '08:45');
$sheet->setCellValue('E3', 'غائب');

// Row 4: Non-existent student (should be skipped)
$sheet->setCellValue('A4', 9999);
$sheet->setCellValue('B4', 'طالب وهمي');
$sheet->setCellValue('C4', '20/06/2026');
$sheet->setCellValue('D4', '09:00');
$sheet->setCellValue('E4', 'متأخر');

$writer = new Xlsx($spreadsheet);
$writer->save('attendance_test.xlsx');
echo "Excel file generated successfully!\n";
