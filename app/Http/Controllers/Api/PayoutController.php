<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\GroupTransaction;
use App\Models\Payout;
use App\Models\SavingsGroup;
use App\Support\GroupSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PayoutController extends Controller
{
    public function completeCycle(Request $request, SavingsGroup $group): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $group) {
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

            if ($group->isSavings()) {
                return $this->completeSavingsCycle($group);
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

        return response()->json($result, 201);
    }

    public function settle(Request $request, SavingsGroup $group): JsonResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'min:4', 'max:100', 'unique:transactions,reference'],
            'payout_id' => [Rule::requiredIf($group->isSavings()), 'integer'],
        ]);
        $payout = DB::transaction(function () use ($request, $group, $data): Payout {
            $group = SavingsGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
            abort_unless($group->creator_id === $request->user()->id, 403);
            $cycle = $group->current_cycle;
            $payout = $group->payouts()->where('cycle', $cycle)
                ->when($group->isSavings(), fn ($query) => $query->whereKey($data['payout_id']))
                ->lockForUpdate()->firstOrFail();
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
            if ($group->isSavings()) {
                if (! $group->payouts()->where('cycle', $cycle)->where('status', 'pending')->exists()) {
                    $group->update(['status' => 'completed', 'current_cycle' => $cycle + 1]);
                }
            } elseif ($cycle === $group->totalCycles()) {
                $group->update(['status' => 'completed', 'current_cycle' => $cycle + 1]);
            } else {
                $this->advanceCycle($group);
            }

            return $payout;
        });

        return response()->json($payout);
    }

    private function completeSavingsCycle(SavingsGroup $group): SavingsGroup|array
    {
        if ($group->current_cycle < $group->totalCycles()) {
            $this->advanceCycle($group);

            return $group;
        }
        $maturity = GroupSchedule::dateForCycle($group, $group->totalCycles() + 1);
        if (CarbonImmutable::parse($maturity)->isFuture()) {
            throw ValidationException::withMessages(['cycle' => 'Savings are held until the agreed repayment date of '.$maturity.'.']);
        }
        $contributions = $group->contributions()->get();
        if ($contributions->count() !== $group->max_members * $group->totalCycles()
            || $contributions->contains(fn ($row) => $row->status !== 'paid')) {
            throw ValidationException::withMessages(['cycle' => 'All savings contributions must be paid before repayments are prepared.']);
        }
        $payouts = [];
        foreach ($group->members as $member) {
            $payouts[] = $group->payouts()->create([
                'member_id' => $member->id,
                'cycle' => $group->current_cycle,
                'amount_kobo' => $contributions->where('member_id', $member->id)->sum('amount_kobo'),
                'scheduled_for' => $maturity,
                'status' => 'pending',
            ]);
            AppNotification::create([
                'user_id' => $member->user_id,
                'title' => 'Savings repayment pending',
                'message' => 'Your savings in '.$group->name.' are awaiting manual repayment.',
                'type' => 'payout',
            ]);
        }

        return ['payouts' => $payouts];
    }

    private function advanceCycle(SavingsGroup $group): void
    {
        $group->update(['current_cycle' => $group->current_cycle + 1]);
        foreach ($group->members as $member) {
            $group->contributions()->create([
                'member_id' => $member->id,
                'cycle' => $group->current_cycle,
                'amount_kobo' => $group->contribution_amount_kobo,
                'status' => 'pending',
            ]);
        }
    }
}
