<?php

namespace Tests\Feature;

use App\Models\Memorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * البحث الموحّد في سجلات الحفظ — GET /api/memorizations?q=:
 * رقم جزء (حتى بالأرقام العربية) أو اسم جزء شائع (عمّ/تبارك — بتشكيل أو بدونه)
 * أو اسم طالب. التحويل عبر SurahReference::juzFromName + namesOfJuz —
 * عمود juz المخزّن يبقى غير معتمَد، واسم جزء غير معروف يعيد نتيجة فارغة لا 500.
 */
class MemorizationJuzSearchTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    /** يبذر ثلاث سور من أجزاء مختلفة (الجزء المخزّن متعمَّد الخطأ — يجب تجاهله). */
    private function seedMemos(): array
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent($teacher, null, ['name' => 'حذيفة الاختبار']);

        foreach ([
            ['surah_name' => 'الناس',  'juz' => 7],  // فعلياً جزء 30 — المخزّن خطأ عمداً
            ['surah_name' => 'الملك',  'juz' => 3],  // فعلياً جزء 29
            ['surah_name' => 'البقرة', 'juz' => 30], // فعلياً جزء 1
        ] as $i => $m) {
            Memorization::create($m + [
                'student_id' => $student->id, 'teacher_id' => $teacher->id,
                'date' => '2026-07-0' . ($i + 1), 'quality' => 'good',
            ]);
        }

        return [$teacher, $student];
    }

    /** أسماء السور في نتيجة البحث q. */
    private function surahsFor(string $token, string $q): array
    {
        $this->app['auth']->forgetGuards();
        $r = $this->authed($token)->getJson('/api/memorizations?q=' . urlencode($q))->assertOk();

        return collect($r->json('data.data'))->pluck('surah_name')->sort()->values()->all();
    }

    public function test_juz_name_number_and_arabic_digits_all_match_same_records(): void
    {
        [$teacher] = $this->seedMemos();
        $token = $this->loginToken($teacher);

        // «عم» = «عمّ» (بالشدة) = «30» = «٣٠» = «جزء 30» — كلها سور الجزء 30 فقط
        foreach (['عم', 'عمّ', '30', '٣٠', 'جزء 30'] as $q) {
            $this->assertSame(['الناس'], $this->surahsFor($token, $q), "فشل الاستعلام: {$q}");
        }

        // «تبارك» → الجزء 29 رغم أن juz المخزّن للملك = 3 (العمود غير معتمَد)
        $this->assertSame(['الملك'], $this->surahsFor($token, 'تبارك'));
    }

    public function test_unknown_juz_name_returns_empty_not_500(): void
    {
        [$teacher] = $this->seedMemos();
        $token = $this->loginToken($teacher);

        $this->assertSame([], $this->surahsFor($token, 'جزء لا وجود له'));
        // رقم خارج المدى ليس جزءاً → يُعامل اسمَ طالب → فارغة أيضاً
        $this->assertSame([], $this->surahsFor($token, '55'));
    }

    public function test_student_name_search_returns_his_records(): void
    {
        [$teacher] = $this->seedMemos();
        $token = $this->loginToken($teacher);

        // «حذيفه» بلا همزة تطابق «حذيفة» (التطبيع الموحّد) — كل سجلاته الثلاثة
        $this->assertSame(['البقرة', 'الملك', 'الناس'], $this->surahsFor($token, 'حذيفه'));
    }
}
