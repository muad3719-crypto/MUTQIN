<?php
require 'vendor/autoload.php';

$xlsx = \PhpOffice\PhpSpreadsheet\IOFactory::load('attendance_test.xlsx');
$rows = $xlsx->getActiveSheet()->toArray();
echo "Total rows: " . count($rows) . "\n";
for ($i = 0; $i < min(10, count($rows)); $i++) {
    print_r($rows[$i]);
}
