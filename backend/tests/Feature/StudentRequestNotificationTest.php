<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * دورة طلب الطالب مع الإشعارات: إنشاء → إشعار الأدمن؛ موافقة/رفض → إشعار صاحب الطلب.
 */
class StudentRequestNotificationTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    public function test_create_request_notifies_all_admins(): void
    {
        $admin1  = $this->makeAdmin();
        $admin2  = $this->makeAdmin();
        $teacher = $this->makeTeacher();

        $this->authed($this->loginToken($teacher))->postJson('/api/student-requests', [
            'type'             => 'add',
            'name'             => 'طالب طلب الاختبار',
            'national_id'      => '155500000001',
            'nationality_type' => 'libyan',
        ])->assertStatus(201);

        $this->assertDatabaseHas('student_requests', ['student_name' => 'طالب طلب الاختبار', 'status' => 'pending']);

        foreach ([$admin1, $admin2] as $admin) {
            $this->assertSame(1, $admin->notifications()->count(), "الأدمن {$admin->id} لم يُشعَر");
            $this->assertSame('request_created', $admin->notifications()->first()->data['type']);
        }
        // المحفّظ نفسه لا يُشعَر بالإنشاء
        $this->assertSame(0, $teacher->notifications()->count());
    }

    public function test_approve_creates_student_and_notifies_requester(): void
    {
        $admin   = $this->makeAdmin();
        $teacher = $this->makeTeacher();

        $this->authed($this->loginToken($teacher))->postJson('/api/student-requests', [
            'type' => 'add', 'name' => 'طالب للموافقة', 'national_id' => '155500000002',
        ])->assertStatus(201);
        $reqId = \App\Models\StudentRequest::where('student_name', 'طالب للموافقة')->value('id');

        $this->authed($this->loginToken($admin))
            ->postJson("/api/admin/student-requests/{$reqId}/approve")
            ->assertOk();

        // الطالب أُنشئ لدى المحفّظ الطالب
        $this->assertDatabaseHas('students', [
            'name' => 'طالب للموافقة', 'teacher_id' => $teacher->id, 'center_id' => $teacher->center_id,
        ]);
        // وصاحب الطلب أُشعر بالموافقة
        $types = $teacher->notifications()->get()->pluck('data.type');
        $this->assertTrue($types->contains('request_approved'));
    }

    public function test_reject_notifies_requester_with_reason(): void
    {
        $admin   = $this->makeAdmin();
        $teacher = $this->makeTeacher();

        $this->authed($this->loginToken($teacher))->postJson('/api/student-requests', [
            'type' => 'add', 'name' => 'طالب للرفض', 'national_id' => '155500000003',
        ])->assertStatus(201);
        $reqId = \App\Models\StudentRequest::where('student_name', 'طالب للرفض')->value('id');

        $this->authed($this->loginToken($admin))
            ->postJson("/api/admin/student-requests/{$reqId}/reject", ['admin_note' => 'بيانات ناقصة'])
            ->assertOk();

        $notif = $teacher->notifications()->get()->firstWhere('data.type', 'request_rejected');
        $this->assertNotNull($notif, 'لم يصل إشعار الرفض');
        $this->assertStringContainsString('بيانات ناقصة', $notif->data['body']);
        // ولم يُنشأ طالب
        $this->assertDatabaseMissing('students', ['name' => 'طالب للرفض']);
    }
}
