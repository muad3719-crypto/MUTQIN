<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * إدارة «مشرفي المراكز» — للمدير الرئيسي فقط.
 * القاعدة: مشرف واحد لكل مركز كحدّ أقصى.
 */
class SupervisorManagementController extends Controller
{
    /** مشرف واحد لكل مركز كحدّ أقصى. */
    protected function assertSingleSupervisor($centerId, $ignoreId = null): void
    {
        $exists = User::where('role', 'center_supervisor')
            ->where('center_id', $centerId)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'center_id' => ['لهذا المركز مشرف بالفعل — مشرف واحد لكل مركز كحدّ أقصى'],
            ]);
        }
    }

    public function index(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => User::where('role', 'center_supervisor')
                ->with('center:id,name,city')
                ->latest()
                ->get(['id', 'name', 'email', 'phone', 'center_id', 'created_at']),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'      => 'required|string|max:255',
            'email'     => 'required|email|unique:users,email',
            'phone'     => 'nullable|string|max:20',
            'password'  => 'required|min:6|confirmed',
            'center_id' => 'required|exists:centers,id',
        ], [
            'name.required'      => 'اسم المشرف مطلوب',
            'email.required'     => 'البريد الإلكتروني مطلوب',
            'email.unique'       => 'هذا البريد مستخدم مسبقاً',
            'password.required'  => 'كلمة المرور مطلوبة',
            'password.min'       => 'كلمة المرور 6 أحرف على الأقل',
            'password.confirmed' => 'كلمتا المرور غير متطابقتين',
            'center_id.required' => 'يجب اختيار المركز',
        ]);

        $this->assertSingleSupervisor($request->center_id);

        $supervisor = User::create([
            'name'      => $request->name,
            'email'     => $request->email,
            'phone'     => PhoneNumber::normalize($request->phone),
            'role'      => 'center_supervisor',
            'password'  => Hash::make($request->password),
            'center_id' => $request->center_id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء مشرف المركز بنجاح',
            'data' => $supervisor,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $supervisor = User::where('role', 'center_supervisor')->findOrFail($id);

        $request->validate([
            'name'      => 'required|string|max:255',
            'email'     => 'required|email|unique:users,email,' . $supervisor->id,
            'phone'     => 'nullable|string|max:20',
            'center_id' => 'required|exists:centers,id',
        ], [
            'name.required'      => 'اسم المشرف مطلوب',
            'email.unique'       => 'هذا البريد مستخدم مسبقاً',
            'center_id.required' => 'يجب اختيار المركز',
        ]);

        $this->assertSingleSupervisor($request->center_id, $supervisor->id);

        $data = [
            'name'      => $request->name,
            'email'     => $request->email,
            'phone'     => PhoneNumber::normalize($request->phone),
            'center_id' => $request->center_id,
        ];

        if ($request->filled('password')) {
            $request->validate(['password' => 'min:6|confirmed']);
            $data['password'] = Hash::make($request->password);
        }

        $supervisor->update($data);

        if ($request->filled('password')) {
            $supervisor->recordPasswordChange('admin');
        }

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث بيانات المشرف بنجاح',
            'data' => $supervisor,
        ]);
    }

    public function destroy($id)
    {
        $supervisor = User::where('role', 'center_supervisor')->findOrFail($id);
        $supervisor->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم حذف المشرف بنجاح',
        ]);
    }
}
