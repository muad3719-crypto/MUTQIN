<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Student;
use App\Models\User;
use App\Notifications\InAppNotification;
use Illuminate\Http\Request;

/**
 * مراسلة ولي الأمر ↔ المحفّظ — محادثة واحدة لكل زوج مقيّدة بابن محدّد
 * (مفتاح الخيط student_id). الفحص الحاسم كله هنا في الباك:
 *  - ولي الأمر يراسل محفّظ كل ابن من أبنائه فقط (students.parent_id) — غيره 403.
 *  - المحفّظ يرد على أولياء أمور طلابه فقط (students.teacher_id) — غيره 403،
 *    والأدمن ليس طرفاً في المحادثة (يمرّ من بوابة teacher لكنه يُرفض هنا).
 *  - لا مرفقات، لا حذف رسائل، لا مراسلة بين أولياء الأمور (لا مسار لها أصلاً).
 */
class MessageController extends Controller
{
    /**
     * يحسم الطالب (طرفَي المحادثة) لصاحب التوكن حسب دوره، أو يعيد ردّ رفض.
     * @return array{0:?Student,1:?\Illuminate\Http\JsonResponse,2:string} [الطالب، ردّ الرفض، دور المرسل]
     */
    protected function resolveStudent(Request $request, $id): array
    {
        $user = $request->user();
        $student = Student::with(['teacher:id,name', 'parent:id,name'])->find($id);

        if ($user->isParent()) {
            if (!$student || (int) $student->parent_id !== (int) $user->id) {
                return [null, response()->json([
                    'success' => false,
                    'message' => 'هذا الطالب غير مسجل تحت ولايتك',
                ], 403), 'parent'];
            }
            return [$student, null, 'parent'];
        }

        // طرف المحفّظ: المحفّظ الفعلي للطالب حصراً (الأدمن ليس طرفاً في المحادثة)
        if (!$user->isTeacher() || !$student || (int) $student->teacher_id !== (int) $user->id) {
            return [null, response()->json([
                'success' => false,
                'message' => 'هذا الطالب ليس من طلابك — المراسلة لمحفّظ الطالب وولي أمره فقط',
            ], 403), 'teacher'];
        }
        return [$student, null, 'teacher'];
    }

    /**
     * قائمة المحادثات لصاحب التوكن: أبناؤه (ولي الأمر) أو طلابه المرتبطون بولي
     * أمر (المحفّظ) — مع اسم الطرف الآخر وآخر رسالة وعدد غير المقروء.
     * كفاءة: ثلاثة استعلامات مجمّعة — بلا N+1 مهما كثر الطلاب.
     */
    public function threads(Request $request)
    {
        $user = $request->user();

        if ($user->isParent()) {
            $students = Student::where('parent_id', $user->id)
                ->with('teacher:id,name')->get(['id', 'name', 'teacher_id']);
            $otherRole = 'teacher';
            $otherName = fn ($s) => $s->teacher->name ?? null;
        } else {
            if (!$user->isTeacher()) {
                return response()->json([
                    'success' => false,
                    'message' => 'المراسلة لمحفّظ الطالب وولي أمره فقط',
                ], 403);
            }
            $students = Student::where('teacher_id', $user->id)
                ->whereNotNull('parent_id')
                ->with('parent:id,name')->get(['id', 'name', 'parent_id']);
            $otherRole = 'parent';
            $otherName = fn ($s) => $s->parent->name ?? null;
        }

        $ids = $students->pluck('id');

        // غير المقروء من الطرف الآخر + آخر رسالة — استعلامان مجمّعان
        $unread = Message::whereIn('student_id', $ids)
            ->where('sender_role', $otherRole)->whereNull('read_at')
            ->selectRaw('student_id, COUNT(*) AS n')->groupBy('student_id')
            ->pluck('n', 'student_id');
        $lastMessages = Message::whereIn('student_id', $ids)
            ->whereIn('id', Message::whereIn('student_id', $ids)->selectRaw('MAX(id)')->groupBy('student_id'))
            ->get()->keyBy('student_id');

        return response()->json([
            'success' => true,
            'data' => $students->map(fn ($s) => [
                'student_id'   => $s->id,
                'student_name' => $s->name,
                'other_name'   => $otherName($s), // الطرف الآخر (محفّظ أو ولي أمر)
                'unread'       => (int) ($unread[$s->id] ?? 0),
                'last_body'    => $lastMessages[$s->id]->body ?? null,
                'last_at'      => $lastMessages[$s->id]->created_at ?? null,
            ])->values(),
        ]);
    }

    /**
     * خيط المحادثة حول طالب — بترتيب زمني تصاعدي (بحدّ آخر 100 رسالة).
     * فتح الخيط يعلّم رسائل الطرف الآخر مقروءةً.
     */
    public function thread(Request $request, $id)
    {
        [$student, $deny, $myRole] = $this->resolveStudent($request, $id);
        if ($deny) {
            return $deny;
        }

        // تعليم الوارد مقروءاً (رسائل الطرف الآخر فقط)
        Message::where('student_id', $student->id)
            ->where('sender_role', '!=', $myRole)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $messages = Message::where('student_id', $student->id)
            ->latest('id')->limit(100)->get()
            ->reverse()->values()
            ->map(fn ($m) => [
                'id'          => $m->id,
                'sender_role' => $m->sender_role,
                'mine'        => $m->sender_role === $myRole,
                'body'        => $m->body,
                'read_at'     => $m->read_at,
                'created_at'  => $m->created_at,
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'student'  => ['id' => $student->id, 'name' => $student->name],
                'other'    => $myRole === 'parent'
                    ? ($student->teacher->name ?? 'محفّظ غير معيّن')
                    : ($student->parent->name ?? '—'),
                'messages' => $messages,
            ],
        ]);
    }

    /** إرسال رسالة في خيط طالب — نص فقط (لا مرفقات)، مع إشعار الطرف الآخر. */
    public function send(Request $request, $id)
    {
        [$student, $deny, $myRole] = $this->resolveStudent($request, $id);
        if ($deny) {
            return $deny;
        }

        // المحادثة تحتاج طرفيها: ابن بلا محفّظ (أو محفّظ لطالب بلا ولي) لا خيط له
        $receiver = $myRole === 'parent'
            ? ($student->teacher_id ? User::find($student->teacher_id) : null)
            : ($student->parent_id ? User::find($student->parent_id) : null);
        if (!$receiver) {
            return response()->json([
                'success' => false,
                'message' => $myRole === 'parent'
                    ? 'لا محفّظ لهذا الطالب حالياً — تواصل مع إدارة المركز'
                    : 'هذا الطالب غير مرتبط بولي أمر — لا يمكن مراسلته',
            ], 422);
        }

        $request->validate([
            'body' => 'required|string|max:2000',
        ], [
            'body.required' => 'نص الرسالة مطلوب',
            'body.max'      => 'الرسالة طويلة جداً (2000 حرف كحدّ أقصى)',
        ]);

        $message = Message::create([
            'student_id'  => $student->id,
            'sender_id'   => $request->user()->id,
            'sender_role' => $myRole,
            'body'        => $request->body,
        ]);

        // إشعار الطرف الآخر — ثانوي لا يُسقط الإرسال
        $senderName = $request->user()->name;
        InAppNotification::sendSafe(
            $receiver,
            'message_received',
            'رسالة جديدة بخصوص «' . $student->name . '»',
            ($myRole === 'parent' ? 'ولي الأمر' : 'المحفّظ') . ' «' . $senderName . '»: '
                . mb_substr($request->body, 0, 80) . (mb_strlen($request->body) > 80 ? '…' : ''),
            $student->id,
            ($myRole === 'parent' ? 'teacher' : 'parent') . '/messages.html?student=' . $student->id
        );

        return response()->json([
            'success' => true,
            'message' => 'أُرسلت الرسالة',
            'data'    => $message,
        ], 201);
    }
}
