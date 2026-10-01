<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateGroupRequest;
use App\Models\SavingsGroup;
use App\Support\GroupSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $groups = SavingsGroup::query()
            ->whereHas('members', fn ($query) => $query->where('user_id', $request->user()->id))
            ->withCount('members')
            ->latest()
            ->paginate(20);

        return response()->json($groups);
    }

    public function store(CreateGroupRequest $request): JsonResponse
    {
        $group = DB::transaction(function () use ($request): SavingsGroup {
            $group = SavingsGroup::create([
                ...$request->validated(),
                'creator_id' => $request->user()->id,
                'invite_code' => $this->inviteCode(),
                'current_cycle' => 1,
                'status' => 'forming',
            ]);

            $member = $group->members()->create([
                'user_id' => $request->user()->id,
                'payout_position' => 1,
                'joined_at' => now(),
            ]);

            $group->contributions()->create([
                'member_id' => $member->id,
                'cycle' => 1,
                'amount_kobo' => $group->contribution_amount_kobo,
                'status' => 'pending',
            ]);

            return $group;
        });

        return response()->json($group->loadCount('members'), 201);
    }

    public function show(Request $request, SavingsGroup $group): JsonResponse
    {
        $this->requireMembership($request, $group);

        return response()->json($group->loadCount('members')->load([
            'members.user:id,name',
            'contributions' => fn ($query) => $query->where('cycle', $group->current_cycle)->orderBy('member_id'),
            'payouts' => fn ($query) => $query->orderBy('cycle'),
        ]));
    }

    public function join(Request $request): JsonResponse
    {
        $data = $request->validate(['invite_code' => ['required', 'string', 'max:16']]);

        $group = DB::transaction(function () use ($request, $data): SavingsGroup {
            $group = SavingsGroup::where('invite_code', Str::upper(trim($data['invite_code'])))
                ->lockForUpdate()->firstOrFail();

            if ($group->members()->where('user_id', $request->user()->id)->exists()) {
                throw ValidationException::withMessages(['invite_code' => 'You are already a member.']);
            }

            $position = $group->members()->count() + 1;

            if ($group->status !== 'forming' || $position > $group->max_members) {
                throw ValidationException::withMessages(['invite_code' => 'This group is closed to new members.']);
            }

            $member = $group->members()->create([
                'user_id' => $request->user()->id,
                'payout_position' => $position,
                'joined_at' => now(),
            ]);

            $group->contributions()->create([
                'member_id' => $member->id,
                'cycle' => 1,
                'amount_kobo' => $group->contribution_amount_kobo,
                'status' => 'pending',
            ]);

            if ($position === $group->max_members) {
                $group->update(['status' => 'active']);
            }

            return $group;
        });

        return response()->json($group->loadCount('members'));
    }

    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate(['invite_code' => ['required', 'string', 'max:16']]);
        $group = SavingsGroup::where('invite_code', Str::upper(trim($data['invite_code'])))
            ->where('status', 'forming')->withCount('members')->firstOrFail();

        return response()->json($group->only([
            'id', 'name', 'description', 'contribution_amount_kobo', 'frequency',
            'max_members', 'start_date', 'members_count', 'status',
        ]));
    }

    public function schedule(Request $request, SavingsGroup $group): JsonResponse
    {
        $this->requireMembership($request, $group);

        $members = $group->members()->with('user:id,name')->orderBy('payout_position')->get();
        $payouts = $group->payouts()->get()->keyBy('cycle');
        $poolKobo = $group->contribution_amount_kobo * $group->max_members;

        return response()->json(['data' => $members->map(fn ($member) => [
            'cycle' => $member->payout_position,
            'recipient' => $member->user,
            'scheduled_for' => GroupSchedule::dateForCycle($group, $member->payout_position),
            'amount_kobo' => $poolKobo,
            'status' => $payouts->has($member->payout_position) ? 'completed' : 'pending',
        ])]);
    }

    public function contributions(Request $request, SavingsGroup $group): JsonResponse
    {
        $this->requireMembership($request, $group);

        return response()->json($group->contributions()
            ->with('member.user:id,name')
            ->orderByDesc('cycle')->orderBy('member_id')->paginate(30));
    }

    private function requireMembership(Request $request, SavingsGroup $group): void
    {
        abort_unless($group->members()->where('user_id', $request->user()->id)->exists(), 403);
    }

    private function inviteCode(): string
    {
        do {
            $code = Str::upper(Str::random(8));
        } while (SavingsGroup::where('invite_code', $code)->exists());

        return $code;
    }
}
