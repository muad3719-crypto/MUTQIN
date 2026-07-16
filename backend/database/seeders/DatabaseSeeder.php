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
            // المحفّظون
            'عبدالباسط عمران الككلي'   => 'abdulbaset.alkakli',
            'الهادي رمضان الغرياني'    => 'alhadi.algharyani',
            'نوري الصادق العريبي'      => 'nuri.alaribi',
            'فرج امحمد الدرقاش'        => 'faraj.aldarqash',
            // أولياء الأمور
            'عمر بن عاشور'             => 'omar.binashour',
            'سالم الرقيعي'             => 'salem.alruqaii',
            'فتحي الجضران'             => 'fathi.aljadran',
            'عادل الفاخري'             => 'adel.alfakhri',
            'نبيل البرعصي'             => 'nabil.albarassi',
            'رجب المجدوب'              => 'rajab.almajdoub',
            'صلاح الحصادي'             => 'salah.alhasadi',
            'جلال بن غزي'              => 'jalal.binghazi',
            'فوزي الجبالي'             => 'fawzi.aljabali',
            'رافع أبو زقية'            => 'rafa.abuzaqia',
            'منير الرياني'             => 'munir.alriani',
            'بشير الفلاح'              => 'bashir.alfallah',
            'عياد القرقني'             => 'ayad.alqarqani',
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

        // كلمة المرور الموحّدة لكل الحسابات التجريبية (تُعرض في صفحة الدخول)
        $demoPassword = Hash::make('mutqin2026');

        // 1. إنشاء المدير
        $admin = User::create([
            'name'     => 'مدير النظام',
            'email'    => 'admin@mutqin.ly',
            'phone'    => '0913000001',
            'role'     => 'admin',
            'password' => $demoPassword,
        ]);

        // 2. إنشاء مراكز حفظ ليبية
        $center1 = Center::create([
            'name'    => 'مركز عقبة بن نافع لتحفيظ القرآن',
            'city'    => 'طرابلس',
            'address' => 'تاجوراء',
            'phone'   => '021444101',
        ]);

        $center2 = Center::create([
            'name'    => 'مركز معاذ بن جبل لتحفيظ القرآن',
            'city'    => 'بنغازي',
            'address' => 'سيدي حسين',
            'phone'   => '061555102',
        ]);

        $center3 = Center::create([
            'name'    => 'مركز الفرقان لتحفيظ القرآن',
            'city'    => 'مصراتة',
            'address' => 'الزروق',
            'phone'   => '051666103',
        ]);

        // 3. إنشاء معلمين ليبيين وربطهم بالمراكز — ببريد ذي معنى (الاسم + id)
        $teacher1 = $createUserWithEmail([
            'phone' => '0913000002', 'role' => 'teacher', 'password' => $demoPassword,
            'center_id' => $center1->id, 'type' => 'محفظ أساسي',
        ], 'عبدالباسط عمران الككلي');

        $teacher2 = $createUserWithEmail([
            'phone' => '0913000003', 'role' => 'teacher', 'password' => $demoPassword,
            'center_id' => $center1->id, 'type' => 'محفظ معاون',
        ], 'الهادي رمضان الغرياني');

        $teacher3 = $createUserWithEmail([
            'phone' => '0913000004', 'role' => 'teacher', 'password' => $demoPassword,
            'center_id' => $center2->id, 'type' => 'محفظ أساسي',
        ], 'نوري الصادق العريبي');

        $teacher4 = $createUserWithEmail([
            'phone' => '0913000005', 'role' => 'teacher', 'password' => $demoPassword,
            'center_id' => $center3->id, 'type' => 'محفظ أساسي',
        ], 'فرج امحمد الدرقاش');

        // 3.ب مدراء المراكز — بريد بنظام {الاسم اللاتيني}.centeradmin@mutqin.ly (بلا id)
        $managersData = [
            ['عبدالحكيم علي البشتي',  'abdulhakim', $center1->id, '0913000006'],
            ['السنوسي محمد العقوري',  'alsanusi',   $center2->id, '0913000007'],
            ['الطيب خالد الساعدي',    'altayeb',    $center3->id, '0913000008'],
        ];
        foreach ($managersData as [$mName, $mLatin, $mCenterId, $mPhone]) {
            User::create([
                'name'      => $mName,
                'email'     => $mLatin . '.centeradmin@mutqin.ly',
                'phone'     => $mPhone,
                'role'      => 'center_manager',
                'center_id' => $mCenterId,
                'password'  => $demoPassword,
            ]);
        }

        // 4. إنشاء طلاب ليبيين (كلهم ذكور)
        $studentsData = [
            // طلاب الشيخ عبدالباسط (المركز الأول)
            ['name' => 'الزبير عمر بن عاشور', 'guardian_name' => 'عمر بن عاشور', 'guardian_phone' => '0913100001', 'teacher_id' => $teacher1->id, 'center_id' => $center1->id, 'age' => 12, 'phone' => '0914000010'],
            // أخٌ للزبير: نفس ولي الأمر (عمر بن عاشور، نفس الهاتف) → حساب ولي أمر واحد لطفلين
            ['name' => 'البراء عمر بن عاشور', 'guardian_name' => 'عمر بن عاشور', 'guardian_phone' => '0913100001', 'teacher_id' => $teacher1->id, 'center_id' => $center1->id, 'age' => 10, 'phone' => '0914000011'],
            ['name' => 'حذيفة سالم الرقيعي', 'guardian_name' => 'سالم الرقيعي', 'guardian_phone' => '0913100003', 'teacher_id' => $teacher1->id, 'center_id' => $center1->id, 'age' => 14, 'phone' => '0914000012'],
            ['name' => 'عبدالملك فتحي الجضران', 'guardian_name' => 'فتحي الجضران', 'guardian_phone' => '0913100004', 'teacher_id' => $teacher1->id, 'center_id' => $center1->id, 'age' => 9, 'phone' => '0914000013'],
            ['name' => 'سراج عادل الفاخري', 'guardian_name' => 'عادل الفاخري', 'guardian_phone' => '0913100005', 'teacher_id' => $teacher1->id, 'center_id' => $center1->id, 'age' => 15, 'phone' => '0914000014'],

            // طلاب الشيخ الهادي (المركز الأول)
            ['name' => 'أويس نبيل البرعصي', 'guardian_name' => 'نبيل البرعصي', 'guardian_phone' => '0913100006', 'teacher_id' => $teacher2->id, 'center_id' => $center1->id, 'age' => 11, 'phone' => '0914000015'],
            ['name' => 'صهيب رجب المجدوب', 'guardian_name' => 'رجب المجدوب', 'guardian_phone' => '0913100007', 'teacher_id' => $teacher2->id, 'center_id' => $center1->id, 'age' => 13, 'phone' => '0914000016'],
            ['name' => 'الأمين صلاح الحصادي', 'guardian_name' => 'صلاح الحصادي', 'guardian_phone' => '0913100008', 'teacher_id' => $teacher2->id, 'center_id' => $center1->id, 'age' => 16, 'phone' => '0914000017'],
            ['name' => 'وسيم جلال بن غزي', 'guardian_name' => 'جلال بن غزي', 'guardian_phone' => '0913100009', 'teacher_id' => $teacher2->id, 'center_id' => $center1->id, 'age' => 8, 'phone' => '0914000018'],
            ['name' => 'معتصم فوزي الجبالي', 'guardian_name' => 'فوزي الجبالي', 'guardian_phone' => '0913100010', 'teacher_id' => $teacher2->id, 'center_id' => $center1->id, 'age' => 17, 'phone' => '0914000019'],

            // طلاب الشيخ نوري (المركز الثاني)
            ['name' => 'المنتصر رافع أبو زقية', 'guardian_name' => 'رافع أبو زقية', 'guardian_phone' => '0913100011', 'teacher_id' => $teacher3->id, 'center_id' => $center2->id, 'age' => 12, 'phone' => '0914000020'],
            ['name' => 'قصي منير الرياني', 'guardian_name' => 'منير الرياني', 'guardian_phone' => '0913100012', 'teacher_id' => $teacher3->id, 'center_id' => $center2->id, 'age' => 14, 'phone' => '0914000021'],
            ['name' => 'عبدالمولى بشير الفلاح', 'guardian_name' => 'بشير الفلاح', 'guardian_phone' => '0913100013', 'teacher_id' => $teacher3->id, 'center_id' => $center2->id, 'age' => 15, 'phone' => '0914000022'],

            // طلاب الشيخ فرج (المركز الثالث)
            ['name' => 'الطاهر عياد القرقني', 'guardian_name' => 'عياد القرقني', 'guardian_phone' => '0913100014', 'teacher_id' => $teacher4->id, 'center_id' => $center3->id, 'age' => 13, 'phone' => '0914000023'],
            // طالب بالغ أجنبي بلا ولي أمر — لإثبات المسارين الاختياريين (parent_id = null + جنسية غير ليبية)
            ['name' => 'سليمان جمعة التونسي', 'teacher_id' => $teacher4->id, 'center_id' => $center3->id, 'age' => 19, 'phone' => '0914000024',
             'nationality_type' => 'foreigner', 'nationality_name' => 'تونس'],
        ];

        // إنشاء الطلاب:
        //  - ولي الأمر اختياري: إن لم تُذكر بياناته → parent_id = null بلا حساب.
        //  - المطابقة بالهاتف: ولي أمر واحد (نفس الهاتف) ↔ عدة أبناء = حساب واحد ببريد واحد.
        //  - رقم وطني ليبي صالح فريد لكل طالب يبدأ بـ 1 (ذكر — لا إناث في البيانات)،
        //    مع ترك «الطاهر عياد القرقني» بلا رقم وطني والأجنبي بلا رقم ليبي.
        $createdStudents = [];
        $parentsByPhone = [];                      // كاش: الهاتف => مستخدم ولي الأمر
        $natIdNullName = 'الطاهر عياد القرقني';     // الطالب الليبي بلا رقم وطني
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
                        'phone' => $phone, 'role' => 'parent', 'password' => $demoPassword,
                    ], $s['guardian_name'], 'parent.mutqin.ly'); // نطاق أولياء الأمور
                }
                if ($phone) $parentsByPhone[$phone] = $parent;
                $parentId = $parent->id;
            }

            // رقم وطني: يبدأ دائماً بـ '1' (ذكر) + 11 رقماً فريداً — إلا المحدّد بلا رقم والأجنبي
            $nationalId = ($s['name'] === $natIdNullName || ($s['nationality_type'] ?? 'libyan') !== 'libyan')
                ? null
                : ('1' . str_pad((string) (99000000001 + $i), 11, '0', STR_PAD_LEFT));

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
