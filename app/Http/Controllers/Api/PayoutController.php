<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\GroupTransaction;
use App\Models\Payout;
use App\Models\SavingsGroup;
use App\Support\GroupSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayoutController extends Controller
{
    public function completeCycle(Request $request, SavingsGroup $group): JsonResponse
    {
        $payout = DB::transaction(function () use ($request, $group): Payout {
            $group = SavingsGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
            abort_unless($group->creator_id === $request->user()->id, 403);

            $cycle = $group->current_cycle;
            $contributions = $group->contributions()->where('cycle', $cycle)->get();

            if ($group->status !== 'active'
                || $contributions->count() !== $group->max_members
                || $contributions->contains(fn ($contribution) => $contribution->status !== 'paid')) {
                throw ValidationException::withMessages(['cycle' => 'Every member must contribute before the cycle can be completed.']);
            }
            if ($group->payouts()->where('cycle', $cycle)->exists()) {
                throw ValidationException::withMessages(['cycle' => 'A payout is already pending for this cycle.']);
            }

            $recipient = $group->members()->where('payout_position', $cycle)->firstOrFail();
            $amountKobo = $contributions->sum('amount_kobo');

            $payout = $group->payouts()->create([
                'member_id' => $recipient->id,
                'cycle' => $cycle,
                'amount_kobo' => $amountKobo,
                'scheduled_for' => GroupSchedule::dateForCycle($group, $cycle),
                'status' => 'pending',
            ]);

            AppNotification::create([
                'user_id' => $recipient->user_id,
                'title' => 'Payout pending',
                'message' => 'Your payout for '.$group->name.' is awaiting manual settlement.',
                'type' => 'payout',
            ]);

            return $payout;
        });

        return response()->json($payout, 201);
    }

    public function settle(Request $request, SavingsGroup $group): JsonResponse
    {
        $data = $request->validate(['reference' => ['required', 'string', 'min:4', 'max:100', 'unique:transactions,reference']]);
        $payout = DB::transaction(function () use ($request, $group, $data): Payout {
            $group = SavingsGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
            abort_unless($group->creator_id === $request->user()->id, 403);
            $cycle = $group->current_cycle;
            $payout = $group->payouts()->where('cycle', $cycle)->lockForUpdate()->firstOrFail();
            if ($payout->status !== 'pending') {
                throw ValidationException::withMessages(['cycle' => 'This payout has already been settled.']);
            }
            $payout->update(['status' => 'completed', 'completed_at' => now()]);
            GroupTransaction::create([
                'group_id' => $group->id,
                'member_id' => $payout->member_id,
                'type' => 'payout',
                'amount_kobo' => $payout->amount_kobo,
                'status' => 'successful',
                'reference' => trim($data['reference']),
            ]);
            AppNotification::create([
                'user_id' => $payout->member->user_id,
                'title' => 'Payout settled',
                'message' => 'Your payout for '.$group->name.' was recorded as manually settled.',
                'type' => 'payout',
            ]);
            if ($cycle === $group->max_members) {
                $group->update(['status' => 'completed', 'current_cycle' => $cycle + 1]);
            } else {
                $group->update(['current_cycle' => $cycle + 1]);
                foreach ($group->members as $member) {
                    $group->contributions()->create([
                        'member_id' => $member->id,
                        'cycle' => $cycle + 1,
                        'amount_kobo' => $group->contribution_amount_kobo,
                        'status' => 'pending',
                    ]);
                }
            }
            return $payout;
        });

        return response()->json($payout);
    }
}
