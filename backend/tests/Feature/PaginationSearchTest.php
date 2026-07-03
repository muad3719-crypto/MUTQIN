<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * الترقيم من الخادم + البحث الحي المطبَّع في قوائم الطلاب والمعلمين.
 */
class PaginationSearchTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    public function test_students_are_paginated_20_per_page_with_distinct_pages(): void
    {
        $admin   = $this->makeAdmin();
        $teacher = $this->makeTeacher();
        for ($i = 0; $i < 25; $i++) {
            $this->makeStudent($teacher);
        }
        $token = $this->loginToken($admin);

        $p1 = $this->authed($token)->getJson('/api/students')->assertOk();
        $this->assertCount(20, $p1->json('data.data'));
        $this->assertSame(25, $p1->json('data.total'));
        $this->assertSame(2, $p1->json('data.last_page'));

        $p2 = $this->authed($token)->getJson('/api/students?page=2')->assertOk();
        $this->assertCount(5, $p2->json('data.data'));

        // الصفحة الثانية سجلّات مختلفة تماماً عن الأولى
        $ids1 = collect($p1->json('data.data'))->pluck('id');
        $ids2 = collect($p2->json('data.data'))->pluck('id');
        $this->assertCount(0, $ids1->intersect($ids2), 'تداخل بين الصفحتين!');
    }

    public function test_normalized_search_matches_hamza_and_ta_marbuta_variants(): void
    {
        $admin   = $this->makeAdmin();
        $teacher = $this->makeTeacher();
        $this->makeStudent($teacher, null, ['name' => 'أحمد التجريبي']);
        $this->makeStudent($teacher, null, ['name' => 'فاطمة الاختبار']);
        // طالب بهاتف: يحرس من خلل «الاستعلام العربي يطابق كل من له هاتف»
        // (normalize(نص عربي) كان يُفرغ فيصير LIKE '%%')
        $this->makeStudent($teacher, null, ['name' => 'سالم البعيد', 'phone' => '0918555444']);
        $token = $this->loginToken($admin);

        // «احمد» (بلا همزة) تجد «أحمد» فقط — لا «سالم» صاحب الهاتف
        $r = $this->authed($token)->getJson('/api/students?q=' . urlencode('احمد'))->assertOk();
        $this->assertSame(1, $r->json('data.total'));
        $this->assertSame('أحمد التجريبي', $r->json('data.data.0.name'));

        // والبحث الرقمي بالهاتف يعمل
        $r = $this->authed($token)->getJson('/api/students?q=' . urlencode('0918555444'))->assertOk();
        $this->assertSame(1, $r->json('data.total'));
        $this->assertSame('سالم البعيد', $r->json('data.data.0.name'));

        // «فاطمه» (هاء) تجد «فاطمة» (تاء مربوطة)
        $r = $this->authed($token)->getJson('/api/students?q=' . urlencode('فاطمه'))->assertOk();
        $this->assertSame(1, $r->json('data.total'));
        $this->assertSame('فاطمة الاختبار', $r->json('data.data.0.name'));
    }

    public function test_search_composes_with_filters(): void
    {
        $admin    = $this->makeAdmin();
        $centerA  = $this->makeCenter(['name' => 'مركز أ']);
        $centerB  = $this->makeCenter(['name' => 'مركز ب']);
        $teacherA = $this->makeTeacher($centerA);
        $teacherB = $this->makeTeacher($centerB);
        $this->makeStudent($teacherA, null, ['name' => 'أحمد في المركز أ']);
        $this->makeStudent($teacherB, null, ['name' => 'أحمد في المركز ب']);
        $token = $this->loginToken($admin);

        // البحث وحده: نتيجتان
        $r = $this->authed($token)->getJson('/api/students?q=' . urlencode('احمد'))->assertOk();
        $this->assertSame(2, $r->json('data.total'));

        // البحث + فلتر المركز أ: نتيجة واحدة فقط (يتجمّعان بـ AND)
        $r = $this->authed($token)->getJson('/api/students?q=' . urlencode('احمد') . '&center_id=' . $centerA->id)->assertOk();
        $this->assertSame(1, $r->json('data.total'));
        $this->assertSame('أحمد في المركز أ', $r->json('data.data.0.name'));
    }

    public function test_teachers_search_is_normalized_and_paginated(): void
    {
        $admin  = $this->makeAdmin();
        $center = $this->makeCenter();
        $this->makeTeacher($center, ['name' => 'أسامة المعلم']);
        $this->makeTeacher($center, ['name' => 'بشير المعلم']);
        $token = $this->loginToken($admin);

        $r = $this->authed($token)->getJson('/api/teachers?q=' . urlencode('اسامه'))->assertOk();
        $this->assertSame(1, $r->json('data.total'));
        $this->assertSame('أسامة المعلم', $r->json('data.data.0.name'));

        // شكل الترقيم موجود
        $this->assertIsInt($r->json('data.current_page'));
        $this->assertIsInt($r->json('data.last_page'));
    }
}
