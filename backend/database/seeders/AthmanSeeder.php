<?php

namespace Database\Seeders;

use App\Models\Athman;
use Illuminate\Database\Seeder;
use PhpOffice\PhpSpreadsheet\IOFactory;

class AthmanSeeder extends Seeder
{
    /**
     * بذر فهرس الأثمان (477 ثمناً) من ملف الإكسل.
     * idempotent: updateOrCreate على (hizb, thumn_in_hizb) — لا تكرار عند إعادة التشغيل.
     */
    public function run(): void
    {
        $path = database_path('data/فهرس_الأثمان_الكامل.xlsx');

        if (!file_exists($path)) {
            $this->command->error("ملف الأثمان غير موجود: {$path}");
            return;
        }

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('كل الأثمان') ?? $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();

        // إزالة صف الترويسة
        array_shift($rows);

        // ترتيب حسب (الحزب، الثمن) لضمان global_order صحيح
        $clean = [];
        foreach ($rows as $r) {
            if (!isset($r[0]) || trim((string) $r[0]) === '') continue; // صف فارغ
            $clean[] = [
                'hizb'          => (int) $r[0],
                'thumn_in_hizb' => (int) $r[1],
                'surah_name'    => trim((string) $r[2]),
                'start_text'    => trim((string) $r[3]),
                'page'          => is_numeric($r[4] ?? null) ? (int) $r[4] : null,
            ];
        }

        usort($clean, function ($a, $b) {
            return [$a['hizb'], $a['thumn_in_hizb']] <=> [$b['hizb'], $b['thumn_in_hizb']];
        });

        $order = 0;
        foreach ($clean as $row) {
            $order++;
            Athman::updateOrCreate(
                ['hizb' => $row['hizb'], 'thumn_in_hizb' => $row['thumn_in_hizb']],
                [
                    'global_order'    => $order,
                    'surah_name'      => $row['surah_name'],
                    'start_text'      => $row['start_text'],
                    'start_text_norm' => Athman::normalize($row['start_text']),
                    'page'            => $row['page'],
                ]
            );
        }

        $count = Athman::count();
        $this->command->info("تم بذر فهرس الأثمان: {$count} ثمناً.");
    }
}
