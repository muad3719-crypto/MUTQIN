<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Center;
use App\Models\Student;
use App\Models\Attendance;
use App\Models\Memorization;
use App\Models\Revision;
use App\Models\WeeklyTest;
use App\Models\TajweedEvaluation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 0. فهرس الأثمان المرجعي (477 ثمناً) — مستقل وidempotent
        $this->call(AthmanSeeder::class);

        // خريطة نقحرة الأسماء العربية → لاتيني (الاسم الأول + العائلة)
        $translit = [
            'أحمد محمد الصويعي'          => 'ahmed.alsuwaii',
            'محمود علي الورفلي'          => 'mahmoud.alwarfalli',
            'عبد السلام مفتاح التاورغي'  => 'abdulsalam.altawergi',
            'سليم عبد الله الفرجاني'     => 'salim.alferjani',
            'عبدالله الطاهر'            => 'abdullah.altaher',
            'خالد البوسيفي'             => 'khaled.albusaifi',
            'محمد الترهوني'             => 'mohammed.altarhouni',
            'ناجي العدل'                => 'naji.aladel',
            'أحمد الزروق'               => 'ahmed.alzarrouk',
            'محمود الكيلاني'            => 'mahmoud.alkilani',
            'حسن المهدوي'               => 'hassan.almahdawi',
            'جمال القطراني'             => 'jamal.alqatrani',
            'مسعود غيث'                 => 'masoud.gheith',
            'مفتاح السويحلي'            => 'muftah.alsuwaihli',
            'خالد بن دردف'             => 'khaled.bindardaf',
            'طارق الجهمي'               => 'tariq.aljahmi',
            'طارق بن علي'              => 'tariq.binali',
        ];

        // يبني بريداً ذا معنى من الاسم + المعرّف ضمن النطاق المطلوب.
        // أولياء الأمور على نطاق فرعي خاص: @parent.mutqin.ly ، وغيرهم @mutqin.ly.
        $makeEmail = function (string $name, int $id, string $domain = 'mutqin.ly') use ($translit) {
            if (isset($translit[$name])) {
                $base = $translit[$name];
            } else {
                $base = \Illuminate\Support\Str::slug($name, '.') ?: 'user';
                $this->command->warn("اسم غير مُنقحَر: {$name} — استُخدم slug: {$base}");
            }
            return "{$base}.{$id}@{$domain}";
        };

        // ينشئ مستخدماً ببريد مؤقت ثم يضبط البريد النهائي (الاسم + id) — لأن id لا يوجد إلا بعد الإدراج
        $createUserWithEmail = function (array $attrs, string $name, string $domain = 'mutqin.ly') use ($makeEmail) {
            $user = User::create(array_merge($attrs, [
                'name'  => $name,
                'email' => 'tmp-' . \Illuminate\Support\Str::random(14) . '@mutqin.ly',
            ]));
            $user->email = $makeEmail($name, $user->id, $domain);
            $user->save();
            return $user;
        };

        // 1. إنشاء المدير
        $admin = User::create([
            'name'     => 'مدير النظام',
            'email'    => 'admin@mutqin.ly',
            'phone'    => '0913000001',
            'role'     => 'admin',
            'password' => Hash::make('password'),
        ]);

        // 2. إنشاء مراكز حفظ ليبية
        $center1 = Center::create([
            'name'    => 'مركز بلال بن رباح لتحفيظ القرآن',
            'city'    => 'طرابلس',
            'address' => 'الفرناج',
            'phone'   => '021333001',
        ]);

        $center2 = Center::create([
            'name'    => 'مركز الإمام مالك لتحفيظ القرآن',
            'city'    => 'بنغازي',
            'address' => 'الحدائق',
            'phone'   => '061222002',
        ]);

        $center3 = Center::create([
            'name'    => 'مركز الفتح المبين لتحفيظ القرآن',
            'city'    => 'مصراتة',
            'address' => 'وسط المدينة',
            'phone'   => '051444003',
        ]);

        // 3. إنشاء معلمين ليبيين وربطهم بالمراكز — ببريد ذي معنى (الاسم + id)
        $teacher1 = $createUserWithEmail([
            'phone' => '0913000002', 'role' => 'teacher', 'password' => Hash::make('password'),
            'center_id' => $center1->id, 'type' => 'محفظ أساسي',
        ], 'أحمد محمد الصويعي');

        $teacher2 = $createUserWithEmail([
            'phone' => '0913000003', 'role' => 'teacher', 'password' => Hash::make('password'),
            'center_id' => $center1->id, 'type' => 'محفظ معاون',
        ], 'محمود علي الورفلي');

        $teacher3 = $createUserWithEmail([
            'phone' => '0913000004', 'role' => 'teacher', 'password' => Hash::make('password'),
            'center_id' => $center2->id, 'type' => 'محفظ أساسي',
        ], 'عبد السلام مفتاح التاورغي');

        $teacher4 = $createUserWithEmail([
            'phone' => '0913000005', 'role' => 'teacher', 'password' => Hash::make('password'),
            'center_id' => $center3->id, 'type' => 'محفظ أساسي',
        ], 'سليم عبد الله الفرجاني');

        // 4. إنشاء طلاب ليبيين
        $studentsData = [
            // طلاب الأستاذ أحمد (المركز الأول)
            ['name' => 'يوسف عبدالله الطاهر', 'guardian_name' => 'عبدالله الطاهر', 'guardian_phone' => '0913100001', 'teacher_id' => $teacher1->id, 'center_id' => $center1->id, 'age' => 12, 'phone' => '0914000010'],
            // أخٌ ليوسف: نفس ولي الأمر (عبدالله الطاهر، نفس الهاتف) → حساب ولي أمر واحد لطفلين
            ['name' => 'إبراهيم عبدالله الطاهر', 'guardian_name' => 'عبدالله الطاهر', 'guardian_phone' => '0913100001', 'teacher_id' => $teacher1->id, 'center_id' => $center1->id, 'age' => 10, 'phone' => '0914000011'],
            ['name' => 'عمر خالد البوسيفي', 'guardian_name' => 'خالد البوسيفي', 'guardian_phone' => '0913100003', 'teacher_id' => $teacher1->id, 'center_id' => $center1->id, 'age' => 14, 'phone' => '0914000012'],
            ['name' => 'عبد الرحمن محمد الترهوني', 'guardian_name' => 'محمد الترهوني', 'guardian_phone' => '0913100004', 'teacher_id' => $teacher1->id, 'center_id' => $center1->id, 'age' => 9, 'phone' => '0914000013'],
            ['name' => 'معاذ ناجي العدل', 'guardian_name' => 'ناجي العدل', 'guardian_phone' => '0913100005', 'teacher_id' => $teacher1->id, 'center_id' => $center1->id, 'age' => 15, 'phone' => '0914000014'],

            // طلاب الأستاذ محمود (المركز الأول)
            ['name' => 'محمد أحمد الزروق', 'guardian_name' => 'أحمد الزروق', 'guardian_phone' => '0913100006', 'teacher_id' => $teacher2->id, 'center_id' => $center1->id, 'age' => 11, 'phone' => '0914000015'],
            ['name' => 'عيسى محمود الكيلاني', 'guardian_name' => 'محمود الكيلاني', 'guardian_phone' => '0913100007', 'teacher_id' => $teacher2->id, 'center_id' => $center1->id, 'age' => 13, 'phone' => '0914000016'],
            ['name' => 'علي حسن المهدوي', 'guardian_name' => 'حسن المهدوي', 'guardian_phone' => '0913100008', 'teacher_id' => $teacher2->id, 'center_id' => $center1->id, 'age' => 16, 'phone' => '0914000017'],
            ['name' => 'مصطفى جمال القطراني', 'guardian_name' => 'جمال القطراني', 'guardian_phone' => '0913100009', 'teacher_id' => $teacher2->id, 'center_id' => $center1->id, 'age' => 8, 'phone' => '0914000018'],
            ['name' => 'أنس مسعود غيث', 'guardian_name' => 'مسعود غيث', 'guardian_phone' => '0913100010', 'teacher_id' => $teacher2->id, 'center_id' => $center1->id, 'age' => 17, 'phone' => '0914000019'],

            // طلاب الأستاذ عبد السلام (المركز الثاني)
            ['name' => 'عبد المهيمن مفتاح السويحلي', 'guardian_name' => 'مفتاح السويحلي', 'guardian_phone' => '0913100011', 'teacher_id' => $teacher3->id, 'center_id' => $center2->id, 'age' => 12, 'phone' => '0914000020'],
            ['name' => 'أسامة خالد بن دردف', 'guardian_name' => 'خالد بن دردف', 'guardian_phone' => '0913100012', 'teacher_id' => $teacher3->id, 'center_id' => $center2->id, 'age' => 14, 'phone' => '0914000021'],
            ['name' => 'مالك طارق الجهمي', 'guardian_name' => 'طارق الجهمي', 'guardian_phone' => '0913100013', 'teacher_id' => $teacher3->id, 'center_id' => $center2->id, 'age' => 15, 'phone' => '0914000022'],

            // طلاب الأستاذ سليم (المركز الثالث)
            ['name' => 'منذر طارق بن علي', 'guardian_name' => 'طارق بن علي', 'guardian_phone' => '0913100014', 'teacher_id' => $teacher4->id, 'center_id' => $center3->id, 'age' => 13, 'phone' => '0914000023'],
            // طالب بالغ بلا ولي أمر — لإثبات المسار الاختياري (parent_id = null، بلا حساب ولي)
            ['name' => 'معتز عادل المقرحي', 'teacher_id' => $teacher4->id, 'center_id' => $center3->id, 'age' => 19, 'phone' => '0914000024'],
        ];

        // إنشاء الطلاب:
        //  - ولي الأمر اختياري: إن لم تُذكر بياناته → parent_id = null بلا حساب.
        //  - المطابقة بالهاتف: ولي أمر واحد (نفس الهاتف) ↔ عدة أبناء = حساب واحد ببريد واحد.
        //  - رقم وطني ليبي صالح فريد لكل طالب، مع ترك «منذر طارق بن علي» بلا رقم وطني (null).
        $createdStudents = [];
        $parentsByPhone = [];                      // كاش: الهاتف => مستخدم ولي الأمر
        $natIdNullName = 'منذر طارق بن علي';        // الطالب بلا رقم وطني
        foreach ($studentsData as $i => $s) {
            $parentId = null;
            if (!empty($s['guardian_phone']) || !empty($s['guardian_name'])) {
                $phone = $s['guardian_phone'] ?? null;

                // مطابقة بالهاتف أولاً (إعادة استخدام حساب ولي الأمر إن وُجد)
                $parent = $phone && isset($parentsByPhone[$phone]) ? $parentsByPhone[$phone] : null;
                if (!$parent && $phone) {
                    $parent = User::where('role', 'parent')->where('phone', $phone)->first();
                }
                if (!$parent) {
                    $parent = $createUserWithEmail([
                        'phone' => $phone, 'role' => 'parent', 'password' => Hash::make('password'),
                    ], $s['guardian_name'], 'parent.mutqin.ly'); // نطاق أولياء الأمور
                }
                if ($phone) $parentsByPhone[$phone] = $parent;
                $parentId = $parent->id;
            }

            // رقم وطني: '1'(ذكر)/'2'(أنثى) + 11 رقماً فريداً — إلا الطالب المحدّد فـ null
            $nationalId = ($s['name'] === $natIdNullName)
                ? null
                : (($i % 2 === 0 ? '1' : '2') . str_pad((string) (99000000001 + $i), 11, '0', STR_PAD_LEFT));

            $createdStudents[] = Student::create(array_merge($s, [
                'national_id'     => $nationalId,
                'parent_id'       => $parentId,
                'enrollment_date' => now()->subMonths(rand(3, 12)),
                'is_active'       => true,
            ]));
        }

        // 5. توليد بيانات الحضور والغياب (لآخر 30 يوم ما عدا الجمعة)
        $start = now()->subDays(30);
        for ($i = 0; $i <= 30; $i++) {
            $date = $start->copy()->addDays($i);
            // استبعاد يوم الجمعة (عطلة نهاية الأسبوع)
            if ($date->isFriday()) {
                continue;
            }
            foreach ($createdStudents as $student) {
                $status = 'present';
                $rand = rand(1, 100);
                if ($rand > 92) {
                    $status = 'absent';
                } elseif ($rand > 82) {
                    $status = 'late';
                }

                Attendance::create([
                    'student_id' => $student->id,
                    'teacher_id' => $student->teacher_id,
                    'date'       => $date->toDateString(),
                    'status'     => $status,
                    'notes'      => $status === 'late' ? 'تأخر عن موعد الحلقة بـ 15 دقيقة' : ($status === 'absent' ? 'غياب غير مبرر' : null),
                ]);
            }
        }

        // 6. سور من جزء عم وتبارك وبعض السور الأخرى
        $surahs = [
            'النبأ', 'النازعات', 'عبس', 'التكوير', 'الانفطار', 'المطففين', 'الانشقاق', 'البروج',
            'الطارق', 'الأعلى', 'الغاشية', 'الفجر', 'البلد', 'الشمس', 'الليل', 'الضحى', 'الشرح',
            'التين', 'العلق', 'القدر', 'البينة', 'الزلزلة', 'العاديات', 'القارعة', 'التكاثر',
            'العصر', 'الهمزة', 'الفيل', 'قريش', 'الماعون', 'الكوثر', 'الكافرون', 'النصر', 'المسد',
            'الإخلاص', 'الفلق', 'الناس', 'الملك', 'القلم', 'الحاقة', 'المعارج', 'نوح', 'الجن', 
            'المزمل', 'المدثر', 'القيامة'
        ];

        // 7. توليد سجلات الحفظ اليومية
        foreach ($createdStudents as $student) {
            $currentDate = now()->subDays(28);
            $sessionCount = 0;
            
            while ($currentDate->lte(now())) {
                if (!$currentDate->isFriday()) {
                    $surah = $surahs[$sessionCount % count($surahs)];
                    $qualities = ['excellent', 'good', 'average', 'weak'];
                    $quality = $qualities[rand(0, 2)]; // في الغالب ممتاز أو جيد

                    Memorization::create([
                        'student_id' => $student->id,
                        'teacher_id' => $student->teacher_id,
                        'date'       => $currentDate->toDateString(),
                        'surah_name' => $surah,
                        'juz'        => rand(29, 30),
                        'hizb'       => rand(57, 60),
                        'page_from'  => rand(562, 595),
                        'page_to'    => rand(596, 604),
                        'eighth'     => 'ثمن حزب',
                        'quality'    => $quality,
                        'notes'      => $quality === 'excellent' ? 'حفظ متقن ومخارج حروف واضحة جداً.' : ($quality === 'weak' ? 'يحتاج إلى مراجعة زمن المد الطبيعي وحكم الإظهار.' : 'تسميع جيد مع وجود بعض التردد البسيط في أواخر السورة.'),
                    ]);
                    $sessionCount++;
                }
                $currentDate->addDays(rand(2, 4));
            }
        }

        // 8. توليد سجلات المراجعة اليومية
        foreach ($createdStudents as $student) {
            $currentDate = now()->subDays(28);
            $sessionCount = 0;
            
            while ($currentDate->lte(now())) {
                if (!$currentDate->isFriday()) {
                    $surah = $surahs[($sessionCount + 3) % count($surahs)];
                    $qualities = ['excellent', 'good', 'average', 'weak'];
                    $quality = $qualities[rand(0, 2)];

                    Revision::create([
                        'student_id' => $student->id,
                        'teacher_id' => $student->teacher_id,
                        'date'       => $currentDate->toDateString(),
                        'surah_name' => $surah,
                        'page_from'  => rand(562, 595),
                        'page_to'    => rand(596, 604),
                        'quality'    => $quality,
                        'notes'      => $quality === 'excellent' ? 'تمت المراجعة بامتياز تام.' : 'مراجعة جيدة، يُنصح بتثبيت نهايات الآيات المتشابهة.',
                    ]);
                    $sessionCount++;
                }
                $currentDate->addDays(rand(3, 5));
            }
        }

        // 9. توليد الاختبارات الأسبوعية (4 اختبارات لكل طالب)
        foreach ($createdStudents as $student) {
            for ($w = 1; $w <= 4; $w++) {
                $examDate = now()->subWeeks($w)->startOfWeek()->addDays(3); // يوم الأربعاء كموعد دوري للاختبار
                $result = rand(1, 10) > 1 ? 'ناجح' : 'راسب';
                
                WeeklyTest::create([
                    'student_id' => $student->id,
                    'teacher_id' => $student->teacher_id,
                    'exam_date'  => $examDate->toDateString(),
                    'result'     => $result,
                    'notes'      => $result === 'ناجح' ? 'أداء متميز في التسميع واجتاز الاختبار بتقدير ممتاز.' : 'لم يحضر جيداً للمراجعة المقررة هذا الأسبوع، يُعاد الاختبار لاحقاً.',
                ]);
            }
        }

        // 10. توليد تقييمات التجويد (2 تقييم لكل طالب)
        foreach ($createdStudents as $student) {
            for ($t = 1; $t <= 2; $t++) {
                $evalDate = now()->subWeeks($t * 2);
                $makharij = rand(7, 10);
                $sifat = rand(7, 10);
                $madd = rand(7, 10);
                $waqf = rand(7, 10);
                
                TajweedEvaluation::create([
                    'student_id'     => $student->id,
                    'teacher_id'     => $student->teacher_id,
                    'date'           => $evalDate->toDateString(),
                    'makharij_score' => $makharij,
                    'sifat_score'    => $sifat,
                    'madd_score'     => $madd,
                    'waqf_score'     => $waqf,
                    'total_score'    => $makharij + $sifat + $madd + $waqf,
                    'notes'          => 'مخارج الحروف والصفات ممتازة، نوصي بمراجعة أحكام النون الساكنة والتنوين (الإخفاء بغنة).',
                ]);
            }
        }
    }
}
