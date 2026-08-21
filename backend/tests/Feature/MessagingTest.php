<?php

namespace Tests\Feature;

use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

/**
 * مراسلة ولي الأمر ↔ المحفّظ — محادثة واحدة لكل زوج مقيّدة بابن محدّد:
 * ولي الأمر يراسل محفّظ ابنه فقط، والمحفّظ يرد لأولياء أمور طلابه فقط،
 * وكل زوج آخر 403 في الباك. لا حذف رسائل، وفتح الخيط يعلّم الوارد مقروءاً.
 */
class MessagingTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    /** محفّظ + ولي أمر + ابن يربطهما. */
    private function makeConversationParties(): array
    {
        $teacher = $this->makeTeacher();
        $parent  = $this->makeParent();
        $student = $this->makeStudent($teacher, $parent);

        return [$teacher, $parent, $student];
    }

    public function test_parent_messages_own_childs_teacher_and_teacher_replies(): void
    {
        [$teacher, $parent, $student] = $this->makeConversationParties();

        // ولي الأمر يرسل
        $this->authed($this->loginToken($parent))
            ->postJson("/api/parent/messages/{$student->id}", ['body' => 'كيف مستوى ابني في الحفظ؟'])
            ->assertCreated();
        $this->assertDatabaseHas('messages', [
            'student_id' => $student->id, 'sender_id' => $parent->id,
            'sender_role' => 'parent', 'read_at' => null,
        ]);

        // المحفّظ يفتح الخيط: يرى الرسالة وتُعلَّم مقروءة
        $teacherToken = $this->loginToken($teacher);
        $r = $this->authed($teacherToken)
            ->getJson("/api/teacher/messages/{$student->id}")
            ->assertOk();
        $this->assertSame('كيف مستوى ابني في الحفظ؟', $r->json('data.messages.0.body'));
        $this->assertFalse($r->json('data.messages.0.mine'));
        $this->assertNotNull(Message::where('student_id', $student->id)->first()->read_at);

        // المحفّظ يرد — وولي الأمر يرى الرسالتين بترتيبهما
        $this->app['auth']->forgetGuards();
        $this->authed($teacherToken)
            ->postJson("/api/teacher/messages/{$student->id}", ['body' => 'ممتاز، أتم جزء عمّ'])
            ->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $r2 = $this->authed($this->loginToken($parent))
            ->getJson("/api/parent/messages/{$student->id}")
            ->assertOk();
        $this->assertCount(2, $r2->json('data.messages'));
        $this->assertTrue($r2->json('data.messages.0.mine'));   // رسالته هو
        $this->assertFalse($r2->json('data.messages.1.mine'));  // ردّ المحفّظ
    }

    public function test_parent_cannot_message_for_someone_elses_child(): void
    {
        [, , $student] = $this->makeConversationParties();
        $stranger = $this->makeParent();
        $token = $this->loginToken($stranger);

        $this->authed($token)
            ->postJson("/api/parent/messages/{$student->id}", ['body' => 'مرحبا'])
            ->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->authed($token)
            ->getJson("/api/parent/messages/{$student->id}")
            ->assertForbidden();

        $this->assertSame(0, Message::count());
    }

    public function test_teacher_cannot_reply_for_student_not_his(): void
    {
        [, , $student] = $this->makeConversationParties();
        $otherTeacher = $this->makeTeacher();

        $this->authed($this->loginToken($otherTeacher))
            ->postJson("/api/teacher/messages/{$student->id}", ['body' => 'مرحبا'])
            ->assertForbidden();

        $this->assertSame(0, Message::count());
    }

    public function test_role_gates_and_admin_are_not_conversation_parties(): void
    {
        [$teacher, $parent, $student] = $this->makeConversationParties();

        // توكن ولي الأمر (قدرته parent) لا يبلغ مسار المحفّظ — والعكس
        $this->authed($this->loginToken($parent))
            ->postJson("/api/teacher/messages/{$student->id}", ['body' => 'اختراق'])
            ->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->authed($this->loginToken($teacher))
            ->postJson("/api/parent/messages/{$student->id}", ['body' => 'اختراق'])
            ->assertForbidden();

        // الأدمن يمرّ من بوابة teacher لكنه ليس طرفاً في المحادثة → 403
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->authed($this->loginToken($this->makeAdmin()))
            ->postJson("/api/teacher/messages/{$student->id}", ['body' => 'مرحبا'])
            ->assertForbidden();

        $this->assertSame(0, Message::count());
    }

    public function test_child_without_teacher_gets_arabic_422(): void
    {
        $parent  = $this->makeParent();
        $orphan  = $this->makeStudent($this->makeTeacher(), $parent, ['teacher_id' => null]);

        $r = $this->authed($this->loginToken($parent))
            ->postJson("/api/parent/messages/{$orphan->id}", ['body' => 'مرحبا'])
            ->assertStatus(422);
        $this->assertStringContainsString('لا محفّظ', $r->json('message'));
    }
}
