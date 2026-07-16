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

        // كلمة المرور الموحّدة لكل الحسابات التجريبية (تُعرض في صفحة الدخول)
        $demoPassword = Hash::make('mutqin2026');

        // ===== 1) مراكز جديدة =====
        $centersData = [
            ['name' => 'مركز التقوى لتحفيظ القرآن', 'city' => 'الخمس', 'address' => 'سوق الخميس', 'phone' => '031777301'],
            ['name' => 'مركز الاستقامة لتحفيظ القرآن', 'city' => 'غريان', 'address' => 'حي الياسمين', 'phone' => '041888302'],
            ['name' => 'مركز البشائر لتحفيظ القرآن', 'city' => 'سرت', 'address' => 'الجيزة البحرية', 'phone' => '054999303'],
            ['name' => 'مركز الرضوان لتحفيظ القرآن', 'city' => 'طبرق', 'address' => 'حي النصر', 'phone' => '087111304'],
            ['name' => 'مركز المتقين لتحفيظ القرآن', 'city' => 'أجدابيا', 'address' => 'وسط المدينة', 'phone' => '064222305'],
        ];
        $centers = collect($centersData)->map(fn ($c) => Center::create($c))->values();

        // ===== 2) معلمون جدد (اسم عربي + نقحرة + نوع) =====
        $teachersData = [
            ['عبدالناصر سعد الزوام', 'abdulnaser.alzuwam', 'محفظ أساسي'],
            ['الصادق عوض المغربي', 'alsadiq.almaghrabi', 'محفظ أساسي'],
            ['عصام فرج بالنور', 'issam.balnour', 'محفظ معاون'],
            ['نادر حسين الأوجلي', 'nader.alawjali', 'محفظ أساسي'],
            ['المهدي عاشور الكوافي', 'almahdi.alkawafi', 'محفظ أساسي'],
            ['رمزي إبراهيم العرفي', 'ramzi.alorfi', 'محفظ معاون'],
            ['وليد عبدالسلام بن حليم', 'walid.binhalim', 'محفظ أساسي'],
            ['عادل منصور الأسطى', 'adel.alosta', 'محفظ أساسي'],
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
                'password'  => $demoPassword,
                'center_id' => $centerId,
                'type'      => $type,
            ], $latin);
        }

        // ===== 3) أُسر وطلاب (أبناء أخوة لنفس الأب) — أسماء ذكور فقط =====
        $firstNames = [
            ['حذيفة', 'hudhayfa'], ['عثمان', 'othman'], ['صهيب', 'suhaib'], ['معاذ', 'muadh'],
            ['سراج', 'siraj'], ['أيمن', 'ayman'], ['وليد', 'walid'], ['منير', 'munir'],
            ['رمزي', 'ramzi'], ['عصام', 'issam'], ['نادر', 'nader'], ['فؤاد', 'fuad'],
            ['سالم', 'salem'], ['عياد', 'ayyad'], ['البراء', 'albaraa'], ['أويس', 'uwais'],
        ];
        $families = [
            ['العجيلي', 'alojaili'], ['الرقيعي', 'alruqaii'], ['بن عاشور', 'binashour'], ['الجضران', 'aljadran'],
            ['الفاخري', 'alfakhri'], ['البرعصي', 'albarassi'], ['المجدوب', 'almajdoub'], ['الحصادي', 'alhasadi'],
            ['بن غزي', 'binghazi'], ['الجبالي', 'aljabali'], ['أبو زقية', 'abuzaqia'], ['الرياني', 'alriani'],
            ['الفلاح', 'alfallah'], ['الكوافي', 'alkawafi'],
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
                'password' => $demoPassword,
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
