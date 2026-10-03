<?php

namespace Tests\Feature;

use App\Models\GroupTransaction;
use App\Models\SavingsGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SavingsAjoTest extends TestCase
{
    use RefreshDatabase;

    public function test_type_and_duration_validation_and_rotating_default(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['api']);
        $data = $this->groupData();
        $this->postJson('/api/v1/groups', $data)->assertCreated()->assertJsonPath('ajo_type', 'rotating');
        $this->postJson('/api/v1/groups', [...$data, 'ajo_type' => 'other'])->assertUnprocessable();
        foreach ([null, 0, 366, 1.5, 'bad'] as $cycles) {
            $this->postJson('/api/v1/groups', [...$data, 'ajo_type' => 'savings', 'savings_cycles' => $cycles])->assertUnprocessable();
        }
        $this->postJson('/api/v1/groups', [...$data, 'ajo_type' => 'rotating', 'savings_cycles' => 6])->assertUnprocessable();
    }

    public function test_savings_accumulate_then_each_member_is_repaid_at_maturity(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 3)->startOfDay());
        $organizer = User::factory()->create();
        $friend = User::factory()->create();
        $stranger = User::factory()->create();
        Sanctum::actingAs($organizer, ['api']);
        // Three weekly periods with two members: duration is independent of membership.
        $id = $this->postJson('/api/v1/groups', [...$this->groupData(), 'ajo_type' => 'savings', 'savings_cycles' => 3])
            ->assertCreated()->assertJsonPath('savings_cycles', 3)->json('id');
        $group = SavingsGroup::findOrFail($id);
        Sanctum::actingAs($friend, ['api']);
        $this->postJson('/api/v1/groups/lookup', ['invite_code' => $group->invite_code])
            ->assertOk()->assertJsonPath('ajo_type', 'savings')->assertJsonPath('savings_cycles', 3);
        $this->postJson('/api/v1/groups/join', ['invite_code' => $group->invite_code])->assertOk();
        $this->postJson("/api/v1/groups/$id/complete-cycle")->assertForbidden();
        Sanctum::actingAs($organizer, ['api']);
        $this->postJson("/api/v1/groups/$id/complete-cycle")->assertUnprocessable();
        for ($cycle = 1; $cycle <= 2; $cycle++) {
            $group->contributions()->where('cycle', $cycle)->update(['status' => 'paid']);
            $this->postJson("/api/v1/groups/$id/complete-cycle")->assertCreated()->assertJsonPath('current_cycle', $cycle + 1);
            $this->assertSame(0, $group->payouts()->count());
            $this->assertSame(2, $group->contributions()->where('cycle', $cycle + 1)->count());
        }
        $group->contributions()->where('cycle', 3)->update(['status' => 'paid']);
        $this->postJson("/api/v1/groups/$id/complete-cycle")->assertUnprocessable();
        $this->getJson("/api/v1/groups/$id/schedule")->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.cycle', 3)
            ->assertJsonPath('data.0.scheduled_for', '2026-10-24')
            ->assertJsonPath('data.1.scheduled_for', '2026-10-24')
            ->assertJsonPath('data.0.amount_kobo', 375150)
            ->assertJsonPath('data.0.status', 'pending');
        $this->travelTo(now()->setDate(2026, 10, 24)->startOfDay());
        $this->postJson("/api/v1/groups/$id/complete-cycle")->assertCreated()->assertJsonCount(2, 'payouts');
        $this->postJson("/api/v1/groups/$id/complete-cycle")->assertUnprocessable();
        $payouts = $group->payouts()->orderBy('id')->get();
        $this->assertSame(750300, $payouts->sum('amount_kobo'));
        $this->assertSame(0, GroupTransaction::where('type', 'payout')->count());
        $this->getJson("/api/v1/groups/$id/schedule")->assertOk()->assertJsonPath('data.0.status', 'pending');
        $this->postJson("/api/v1/groups/$id/settle-payout", ['reference' => 'BANK-MISSING-ID'])->assertUnprocessable();
        $this->postJson("/api/v1/groups/$id/settle-payout", ['reference' => 'BANK-BAD-ID', 'payout_id' => 999999])->assertNotFound();
        Sanctum::actingAs($stranger, ['api']);
        $this->postJson("/api/v1/groups/$id/settle-payout", ['reference' => 'BANK-FORBIDDEN', 'payout_id' => $payouts[0]->id])->assertForbidden();
        Sanctum::actingAs($organizer, ['api']);
        $this->postJson("/api/v1/groups/$id/settle-payout", ['reference' => 'BANK-MEMBER-1', 'payout_id' => $payouts[0]->id])->assertOk();
        $this->assertSame('active', $group->fresh()->status);
        $this->assertSame(3, $group->fresh()->current_cycle);
        $this->getJson("/api/v1/groups/$id/schedule")->assertOk()
            ->assertJsonPath('data.0.status', 'completed')->assertJsonPath('data.1.status', 'pending');
        $this->postJson("/api/v1/groups/$id/settle-payout", ['reference' => 'BANK-DUPLICATE', 'payout_id' => $payouts[0]->id])->assertUnprocessable();
        $this->postJson("/api/v1/groups/$id/settle-payout", ['reference' => 'BANK-MEMBER-2', 'payout_id' => $payouts[1]->id])->assertOk();
        $this->assertSame('completed', $group->fresh()->status);
        $this->assertSame(4, $group->fresh()->current_cycle);
        $this->assertSame(6, $group->contributions()->count());
        $this->assertSame(2, GroupTransaction::where('type', 'payout')->count());
        $this->postJson("/api/v1/groups/$id/complete-cycle")->assertUnprocessable();
    }

    private function groupData(): array
    {
        return [
            'name' => 'Savings circle',
            'contribution_amount_kobo' => 125050,
            'frequency' => 'weekly',
            'max_members' => 2,
            'requires_approval' => false,
            'start_date' => now()->toDateString(),
        ];
    }
}
