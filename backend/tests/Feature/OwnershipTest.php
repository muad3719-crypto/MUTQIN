<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * الملكية: المحفّظ يرى طلابه فقط، وولي الأمر أبناءه فقط.
 */
class OwnershipTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    public function test_teacher_cannot_access_another_teachers_student(): void
    {
        $center   = $this->makeCenter();
        $teacherA = $this->makeTeacher($center);
        $teacherB = $this->makeTeacher($center);
        $student  = $this->makeStudent($teacherA);

        $tokenB = $this->loginToken($teacherB);

        $this->authed($tokenB)->getJson("/api/students/{$student->id}")->assertStatus(403);
        $this->authed($tokenB)->putJson("/api/students/{$student->id}", ['name' => 'اختراق'])->assertStatus(403);
        $this->authed($tokenB)->deleteJson("/api/students/{$student->id}")->assertStatus(403);

        // صاحبه يصل
        $tokenA = $this->loginToken($teacherA);
        $this->authed($tokenA)->getJson("/api/students/{$student->id}")->assertOk();
    }

    public function test_teacher_cannot_record_memorization_for_another_teachers_student(): void
    {
        $teacherA = $this->makeTeacher();
        $teacherB = $this->makeTeacher();
        $student  = $this->makeStudent($teacherA);

        $this->authed($this->loginToken($teacherB))->postJson('/api/memorizations', [
            'student_id' => $student->id,
            'date'       => '2026-07-01',
            'surah_name' => 'الناس',
            'quality'    => 'good',
        ])->assertStatus(403);
    }

    public function test_parent_cannot_access_another_parents_child(): void
    {
        $teacher = $this->makeTeacher();
        $parentA = $this->makeParent();
        $parentB = $this->makeParent();
        $child   = $this->makeStudent($teacher, $parentA);

        $this->authed($this->loginToken($parentB))
            ->getJson("/api/parent/students/{$child->id}")
            ->assertStatus(403);

        // صاحبه يصل
        $this->authed($this->loginToken($parentA))
            ->getJson("/api/parent/students/{$child->id}")
            ->assertOk();
    }

    public function test_user_cannot_read_anothers_notification(): void
    {
        $admin   = $this->makeAdmin();
        $teacher = $this->makeTeacher();
        $admin->notify(new \App\Notifications\InAppNotification('request_created', 'عنوان', 'نص'));
        $nid = $admin->notifications()->first()->id;

        $this->authed($this->loginToken($teacher))
            ->postJson("/api/notifications/{$nid}/read")
            ->assertStatus(404);
    }
}
