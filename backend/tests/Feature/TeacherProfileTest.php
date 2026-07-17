<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * «ملفّي الشخصي» للمحفّظ: بيانات صاحب التوكن حصراً، تعديل الهاتف وكلمة
 * المرور فقط، والحقول العرضية محمية من أي تعديل عبر هذا المسار.
 */
class TeacherProfileTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    public function test_teacher_gets_only_his_own_profile_data(): void
    {
        $center  = $this->makeCenter(['name' => 'مركز البروفايل']);
        $teacher = $this->makeTeacher($center, ['type' => 'محفظ أساسي']);
        $this->makeStudent($teacher);
        $this->makeStudent($teacher);
        $token = $this->loginToken($teacher);

        $r = $this->authed($token)->getJson('/api/profile')->assertOk();
        $this->assertSame($teacher->name, $r->json('data.name'));
        $this->assertSame('T1', $r->json('data.display_code'));
        $this->assertSame('مركز البروفايل', $r->json('data.center_name'));
        $this->assertSame('محفظ أساسي', $r->json('data.type'));
        $this->assertSame(2, $r->json('data.students_count'));
    }

    public function test_two_teachers_each_see_their_own_data_and_parent_is_blocked(): void
    {
        $center = $this->makeCenter();
        $a = $this->makeTeacher($center, ['name' => 'المحفّظ الأول']);
        $b = $this->makeTeacher($center, ['name' => 'المحفّظ الثاني']);
        $this->makeStudent($a); // طالب للأول فقط

        // كلٌّ يرى بياناته هو (المسار لا يقبل معرّفاً أصلاً — صاحب التوكن فقط)
        $ra = $this->authed($this->loginToken($a))->getJson('/api/profile')->assertOk();
        $this->assertSame('المحفّظ الأول', $ra->json('data.name'));
        $this->assertSame(1, $ra->json('data.students_count'));

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $rb = $this->authed($this->loginToken($b))->getJson('/api/profile')->assertOk();
        $this->assertSame('المحفّظ الثاني', $rb->json('data.name'));
        $this->assertSame(0, $rb->json('data.students_count'));

        // ولي الأمر ممنوع من المسار كلياً
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $parent = $this->makeParent();
        $this->authed($this->loginToken($parent))->getJson('/api/profile')->assertForbidden();
    }

    public function test_display_fields_cannot_be_modified_through_phone_update(): void
    {
        $center  = $this->makeCenter();
        $teacher = $this->makeTeacher($center, ['name' => 'اسم أصلي', 'type' => 'محفظ معاون']);
        $token   = $this->loginToken($teacher);

        // محاولة حقن حقول عرضية مع تحديث الهاتف — تُتجاهل كلياً
        $this->authed($token)->putJson('/api/profile/phone', [
            'phone'     => '+218911111222',
            'name'      => 'اسم مزوَّر',
            'type'      => 'محفظ أساسي',
            'center_id' => 999,
            'role'      => 'admin',
            'display_code' => 'T999',
        ])->assertOk();

        $teacher->refresh();
        $this->assertSame('0911111222', $teacher->phone);     // طُبّع وحُفظ
        $this->assertSame('اسم أصلي', $teacher->name);         // لم يتغيّر
        $this->assertSame('محفظ معاون', $teacher->type);       // لم يتغيّر
        $this->assertSame($center->id, $teacher->center_id);   // لم يتغيّر
        $this->assertSame('teacher', $teacher->role);          // لم يتغيّر
        $this->assertSame('T1', $teacher->display_code);       // لم يتغيّر

        // هاتف غير صالح → 422 برسالة عربية
        $this->app['auth']->forgetGuards();
        $this->authed($token)->putJson('/api/profile/phone', ['phone' => 'abc'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_password_change_requires_current_and_revokes_tokens(): void
    {
        $teacher = $this->makeTeacher();
        $token   = $this->loginToken($teacher);

        // كلمة حالية خاطئة → 422 ولا شيء يتغيّر
        $this->authed($token)->postJson('/api/profile/password', [
            'current_password' => 'wrong', 'password' => 'newsecret1', 'password_confirmation' => 'newsecret1',
        ])->assertStatus(422);

        // الصحيحة → نجاح + سجل method=self + إبطال التوكن القديم
        $this->app['auth']->forgetGuards();
        $this->authed($token)->postJson('/api/profile/password', [
            'current_password' => 'password', 'password' => 'newsecret1', 'password_confirmation' => 'newsecret1',
        ])->assertOk();

        $teacher->refresh();
        $this->assertTrue(Hash::check('newsecret1', $teacher->password));
        $this->assertSame(1, $teacher->password_changed_count);
        $this->assertSame('self', $teacher->passwordChangeLogs()->latest('id')->value('method'));

        // التوكن القديم أُبطل — الطلب التالي به 401
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->authed($token)->getJson('/api/profile')->assertUnauthorized();
    }
}
