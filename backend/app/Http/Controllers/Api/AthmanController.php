<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Athman;
use Illuminate\Http\Request;

class AthmanController extends Controller
{
    /**
     * بحث/إكمال تلقائي في الأثمان عبر بداية النص (منزوع التشكيل).
     * GET /api/athman/search?q=...&limit=...
     */
    public function search(Request $request)
    {
        $raw = trim((string) $request->query('q', ''));
        $limit = min((int) $request->query('limit', 10) ?: 10, 25);

        if (mb_strlen($raw) < 2) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $q = Athman::normalizeQuery($raw);
        if ($q === '') {
            return response()->json(['success' => true, 'data' => []]);
        }

        $like = '%' . $q . '%';

        $results = Athman::query()
            ->where(function ($w) use ($like, $raw) {
                $w->where('start_text_norm', 'like', $like)
                  ->orWhere('surah_name', 'like', '%' . $raw . '%');
            })
            // المطابقات التي تبدأ بالاستعلام أولاً، ثم الترتيب العام
            ->orderByRaw('CASE WHEN start_text_norm LIKE ? THEN 0 ELSE 1 END', [$q . '%'])
            ->orderBy('global_order')
            ->limit($limit)
            ->get(['id', 'hizb', 'thumn_in_hizb', 'global_order', 'surah_name', 'start_text', 'page']);

        return response()->json(['success' => true, 'data' => $results]);
    }

    /**
     * أثمان حزب معيّن (1..60) مرتّبة.
     * GET /api/athman/hizb/{n}
     */
    public function hizb($n)
    {
        $athman = Athman::where('hizb', (int) $n)
            ->orderBy('thumn_in_hizb')
            ->get(['id', 'hizb', 'thumn_in_hizb', 'global_order', 'surah_name', 'start_text', 'page']);

        return response()->json(['success' => true, 'data' => $athman]);
    }

    /**
     * ثمن واحد بالمعرّف.
     * GET /api/athman/{id}
     */
    public function show($id)
    {
        $athman = Athman::findOrFail((int) $id);

        return response()->json(['success' => true, 'data' => $athman]);
    }
}
