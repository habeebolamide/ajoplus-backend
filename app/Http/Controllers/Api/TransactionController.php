<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GroupTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['sometimes', Rule::in(['all', 'contribution', 'payout'])]]);

        $query = GroupTransaction::query()
            ->whereHas('group.members', fn ($query) => $query->where('user_id', $request->user()->id))
            ->with('group:id,name')
            ->orderByDesc('created_at')->orderByDesc('id');

        if (isset($data['type']) && $data['type'] !== 'all') {
            $query->where('type', $data['type']);
        }

        return response()->json($query->paginate(30));
    }
}
