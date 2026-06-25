<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Center;
use App\Models\Student;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * بيانات إضافية كثيرة (تُضاف فوق البذر الأساسي، لا تمسحه):
 *  - مراكز ومعلمون جدد.
 *  - أُسر بأبناء أخوة (نفس الأب/الهاتف → حساب ولي أمر واحد).
 *  - أرقام وطنية للذكور فقط (تبدأ بـ 1)، فريدة.
 *  - بريد ذو معنى لكل معلم/ولي أمر: {latin}.{id}@mutqin.ly
 *
 * التشغيل:  php artisan db:seed --class=ExtraDataSeeder
 */
class ExtraDataSeeder extends Seeder
{
    public function run(): void
    {
        // إنشاء مستخدم ببريد مؤقت ثم ضبط البريد النهائي بالمعرّف (id لا يوجد إلا بعد الإدراج).
        // أولياء الأمور على نطاق فرعي: @parent.mutqin.ly ، وغيرهم @mutqin.ly.
        $createUser = function (array $attrs, string $latin, string $domain = 'mutqin.ly') {
            $u = User::create(array_merge($attrs, ['email' => 'tmp-' . Str::random(16) . '@mutqin.ly']));
            $u->email = $latin . '.' . $u->id . '@' . $domain;
            $u->save();
            return $u;
        };

        // ===== 1) مراكز جديدة =====
        $centersData = [
            ['name' => 'مركز التوحيد لتحفيظ القرآن', 'city' => 'الزاوية', 'address' => 'حي الأندلس', 'phone' => '023111201'],
            ['name' => 'مركز النور لتحفيظ القرآن', 'city' => 'سبها', 'address' => 'وسط المدينة', 'phone' => '071222202'],
            ['name' => 'مركز الهدى لتحفيظ القرآن', 'city' => 'درنة', 'address' => 'حي الفنار', 'phone' => '081333203'],
            ['name' => 'مركز السلام لتحفيظ القرآن', 'city' => 'البيضاء', 'address' => 'حي الزهور', 'phone' => '084444204'],
            ['name' => 'مركز الإيمان لتحفيظ القرآن', 'city' => 'زليتن', 'address' => 'قرب الجامع الكبير', 'phone' => '052555205'],
        ];
        $centers = collect($centersData)->map(fn ($c) => Center::create($c))->values();

        // ===== 2) معلمون جدد (اسم عربي + نقحرة + نوع) =====
        $teachersData = [
            ['أحمد عمر القماطي', 'ahmed.alqamati', 'محفظ أساسي'],
            ['عبدالرحمن أبوبكر الزنتاني', 'abdulrahman.alzentani', 'محفظ أساسي'],
            ['يوسف خليفة المسماري', 'youssef.almismari', 'محفظ معاون'],
            ['إدريس علي الشريف', 'idris.alsharif', 'محفظ أساسي'],
            ['بشير حسن العماري', 'bashir.alammari', 'محفظ أساسي'],
            ['منصور سالم القبائلي', 'mansour.alqabaili', 'محفظ معاون'],
            ['خالد مفتاح الدرسي', 'khaled.aldarsi', 'محفظ أساسي'],
            ['الصديق محمد الزوي', 'alsiddiq.alzawi', 'محفظ أساسي'],
        ];
        $teachers = [];
        $primaryCenters = []; // المراكز التي حصلت على محفّظ أساسي (قاعدة: واحد فقط لكل مركز)
        foreach ($teachersData as $k => [$name, $latin, $type]) {
            $centerId = $centers[$k % $centers->count()]->id;

            // فرض القاعدة عند البذر: أول محفّظ بالمركز = أساسي، والبقية معاونون
            if ($type === 'محفظ أساسي' && isset($primaryCenters[$centerId])) {
                $type = 'محفظ معاون';
            }
            if ($type === 'محفظ أساسي') {
                $primaryCenters[$centerId] = true;
            }

            $teachers[] = $createUser([
                'name'      => $name,
                'phone'     => '09255' . str_pad((string) (300 + $k), 5, '0', STR_PAD_LEFT),
                'role'      => 'teacher',
                'password'  => Hash::make('password'),
                'center_id' => $centerId,
                'type'      => $type,
            ], $latin);
        }

        // ===== 3) أُسر وطلاب (أبناء أخوة لنفس الأب) =====
        $firstNames = [
            ['محمد', 'mohammed'], ['عمر', 'omar'], ['يوسف', 'youssef'], ['خالد', 'khaled'],
            ['إبراهيم', 'ibrahim'], ['عبدالله', 'abdullah'], ['مصطفى', 'mustafa'], ['علي', 'ali'],
            ['حمزة', 'hamza'], ['أنس', 'anas'], ['بلال', 'bilal'], ['طارق', 'tariq'],
            ['سيف', 'saif'], ['ياسين', 'yassin'], ['زكريا', 'zakaria'], ['آدم', 'adam'],
        ];
        $families = [
            ['الفيتوري', 'alfituri'], ['المبروك', 'almabrouk'], ['الدرناوي', 'aldarnawi'], ['الزوي', 'alzwai'],
            ['القاضي', 'alqadi'], ['بن طاهر', 'bintaher'], ['المقري', 'almaqari'], ['الهنشيري', 'alhanshiri'],
            ['العبيدي', 'alobaidi'], ['المسلاتي', 'almisllati'], ['الترهوني', 'altarhouni2'], ['الزليتني', 'alzliteni'],
            ['الشلوي', 'alshalwi'], ['القبلاوي', 'alqablawi'],
        ];

        // قاعدة أرقام وطنية جديدة للذكور فقط (تبدأ بـ 1) — لا تتعارض مع الموجود
        $natCounter   = 70000000001;
        $phoneCounter = 0;
        $studentCount = 0;
        $parentCount  = 0;
        $FAMILIES_TO_CREATE = 20; // عدد الأُسر (≈ 40 طالباً)

        for ($f = 0; $f < $FAMILIES_TO_CREATE; $f++) {
            [$famAr, $famLat]     = $families[$f % count($families)];
            [$fFirstAr, $fFirstLat] = $firstNames[($f * 3) % count($firstNames)]; // اسم الأب

            $guardianName  = $fFirstAr . ' ' . $famAr;            // مثل: محمد الفيتوري
            $guardianPhone = '09266' . str_pad((string) $phoneCounter++, 5, '0', STR_PAD_LEFT);

            $parent = $createUser([
                'name'     => $guardianName,
                'phone'    => $guardianPhone,
                'role'     => 'parent',
                'password' => Hash::make('password'),
            ], $fFirstLat . '.' . $famLat, 'parent.mutqin.ly'); // نطاق أولياء الأمور
            $parentCount++;

            $teacher = $teachers[$f % count($teachers)];

            // 1..3 أبناء لنفس الأب
            $childrenCount = rand(1, 3);
            for ($c = 0; $c < $childrenCount; $c++) {
                [$cFirstAr] = $firstNames[($f * 5 + $c * 2 + 1) % count($firstNames)];
                $studentName = $cFirstAr . ' ' . $fFirstAr . ' ' . $famAr; // الابن + اسم الأب + العائلة

                Student::create([
                    'name'            => $studentName,
                    'national_id'     => '1' . str_pad((string) ($natCounter++), 11, '0', STR_PAD_LEFT), // ذكر فقط
                    'guardian_name'   => $guardianName,
                    'guardian_phone'  => $guardianPhone,
                    'teacher_id'      => $teacher->id,
                    'center_id'       => $teacher->center_id,
                    'parent_id'       => $parent->id,
                    'age'             => rand(7, 16),
                    'phone'           => '0918' . str_pad((string) $studentCount, 6, '0', STR_PAD_LEFT),
                    'enrollment_date' => now()->subMonths(rand(1, 10)),
                    'is_active'       => true,
                ]);
                $studentCount++;
            }
        }

        $this->command->info("تمت إضافة: {$centers->count()} مراكز، " . count($teachers) . " معلمين، {$parentCount} أولياء أمور، {$studentCount} طالباً.");
    }
}
