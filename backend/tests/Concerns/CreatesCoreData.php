<?php

namespace Tests\Concerns;

use App\Models\Center;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * منشئات بيانات مشتركة للاختبارات — مستخدمون بأدوار حقيقية وكلمة مرور معلومة،
 * وتوكنات عبر /api/auth/login الفعلي (حتى تُختبر صلاحيات Sanctum كما في الإنتاج).
 */
trait CreatesCoreData
{
    protected function makeCenter(array $attrs = []): Center
    {
        return Center::create(array_merge(['name' => 'مركز اختبار', 'city' => 'طرابلس'], $attrs));
    }

    protected function makeUser(string $role, array $attrs = []): User
    {
        static $seq = 0;
        $seq++;

        return User::create(array_merge([
            'name'     => "مستخدم {$role} {$seq}",
            'email'    => "{$role}{$seq}@test.mutqin.ly",
            'phone'    => '09100000' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
            'role'     => $role,
            'password' => Hash::make('password'),
        ], $attrs));
    }

    protected function makeAdmin(array $attrs = []): User
    {
        return $this->makeUser('admin', $attrs);
    }

    protected function makeTeacher(?Center $center = null, array $attrs = []): User
    {
        return $this->makeUser('teacher', array_merge([
            'center_id' => ($center ?? $this->makeCenter())->id,
            'type'      => 'محفظ معاون',
        ], $attrs));
    }

    protected function makeParent(array $attrs = []): User
    {
        return $this->makeUser('parent', $attrs);
    }

    /** «مدير المركز» — center_id إلزامي له. */
    protected function makeManager(?Center $center = null, array $attrs = []): User
    {
        return $this->makeUser('center_manager', array_merge([
            'center_id' => ($center ?? $this->makeCenter())->id,
        ], $attrs));
    }

    protected function makeStudent(User $teacher, ?User $parent = null, array $attrs = []): Student
    {
        static $sseq = 0;
        $sseq++;

        return Student::create(array_merge([
            'name'       => "طالب اختبار {$sseq}",
            'age'        => 10,
            'center_id'  => $teacher->center_id,
            'teacher_id' => $teacher->id,
            'parent_id'  => $parent?->id,
            'is_active'  => true,
        ], $attrs));
    }

    /** توكن حقيقي عبر مسار الدخول — يحمل صلاحيات الدور الفعلية ('*' أو 'parent'). */
    protected function loginToken(User $user): string
    {
        $this->app['auth']->forgetGuards(); // لا تسريب هوية بين طلبات نفس الاختبار

        $res = $this->postJson('/api/auth/login', [
            'email'    => $user->email,
            'password' => 'password',
        ]);
        $res->assertOk();

        return $res->json('data.token');
    }

    protected function authed(string $token): static
    {
        // sanctum يخزّن المستخدم المحسوم في الحارس ضمن نفس العملية — نفرغه
        // حتى يُحسم كل طلب من توكنه هو لا من توكن الطلب السابق.
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }
}
