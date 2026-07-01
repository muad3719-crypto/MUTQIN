<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Center;
use Illuminate\Http\Request;

class CenterController extends Controller
{
    public function index(Request $request)
    {
        $query = Center::withCount('students')->latest();

        // فلتر اختياري بالمدينة (الفلترة في الـ Backend)
        if ($request->filled('city')) {
            $query->where('city', $request->city);
        }

        if ($request->has('all') && $request->all == 1) {
            $centers = $query->get();
        } else {
            $centers = $query->paginate(10);
        }

        return response()->json([
            'success' => true,
            'data' => $centers
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'city' => 'nullable|string|max:255',
        ], [
            'name.required' => 'اسم المركز مطلوب',
        ]);

        $center = Center::create($request->only(['name', 'city', 'address', 'phone', 'is_active']));

        return response()->json([
            'success' => true,
            'message' => 'تم إضافة المركز بنجاح',
            'data' => $center
        ], 201);
    }

    public function show($id)
    {
        $center = Center::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $center
        ]);
    }

    public function update(Request $request, $id)
    {
        $center = Center::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'city' => 'nullable|string|max:255',
        ]);

        $center->update($request->only(['name', 'city', 'address', 'phone', 'is_active']));

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث بيانات المركز بنجاح',
            'data' => $center
        ]);
    }

    public function destroy($id)
    {
        $center = Center::findOrFail($id);
        $center->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم حذف المركز بنجاح'
        ]);
    }
}
