<?php

namespace Tests\Feature;

use App\Models\Contribution;
use App\Models\GroupTransaction;
use App\Models\SavingsGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_and_bearer_token_authentication(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated()->assertJsonPath('user.email', 'ada@example.com');

        $token = $response->json('token');
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.name', 'Ada');
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_group_cycle_uses_only_integer_kobo_and_requires_all_payments(): void
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();
        Sanctum::actingAs($creator);

        $this->postJson('/api/v1/groups', $this->groupData(['contribution_amount_kobo' => 100.50]))
            ->assertUnprocessable();

        $groupId = $this->postJson('/api/v1/groups', $this->groupData())
            ->assertCreated()->json('id');
        $group = SavingsGroup::findOrFail($groupId);
        $this->assertSame(125050, $group->contribution_amount_kobo);

        Sanctum::actingAs($member);
        $this->postJson('/api/v1/groups/join', ['invite_code' => $group->invite_code])
            ->assertOk()->assertJsonPath('status', 'active');
        $this->postJson('/api/v1/groups/join', ['invite_code' => $group->invite_code])
            ->assertUnprocessable();

        $creatorContribution = $group->contributions()->whereHas('member', fn ($query) => $query->where('user_id', $creator->id))->firstOrFail();
        $memberContribution = $group->contributions()->whereHas('member', fn ($query) => $query->where('user_id', $member->id))->firstOrFail();

        $this->postJson("/api/v1/groups/$groupId/contributions/{$creatorContribution->id}/simulate-payment", ['successful' => true])
            ->assertForbidden();
        $this->postJson("/api/v1/groups/$groupId/contributions/{$memberContribution->id}/simulate-payment", ['successful' => false])
            ->assertOk()->assertJsonPath('status', 'failed');
        $this->postJson("/api/v1/groups/$groupId/contributions/{$memberContribution->id}/simulate-payment", ['successful' => true])
            ->assertOk()->assertJsonPath('amount_kobo', 125050);
        $this->postJson("/api/v1/groups/$groupId/contributions/{$memberContribution->id}/simulate-payment", ['successful' => true])
            ->assertUnprocessable();

        Sanctum::actingAs($creator);
        $this->postJson("/api/v1/groups/$groupId/complete-cycle")->assertUnprocessable();
        $this->postJson("/api/v1/groups/$groupId/contributions/{$creatorContribution->id}/simulate-payment", ['successful' => true])
            ->assertOk();
        $this->postJson("/api/v1/groups/$groupId/complete-cycle")
            ->assertCreated()->assertJsonPath('amount_kobo', 250100);

        $this->assertDatabaseHas('contributions', ['group_id' => $groupId, 'cycle' => 2, 'amount_kobo' => 125050]);
        $this->assertSame(2, Contribution::where('group_id', $groupId)->where('cycle', 2)->count());
        $this->assertSame(3, GroupTransaction::where('group_id', $groupId)->where('status', 'successful')->count());
        $this->getJson('/api/v1/transactions?type=payout')->assertOk()->assertJsonCount(1, 'data');

        $this->getJson("/api/v1/groups/$groupId/schedule")
            ->assertOk()
            ->assertJsonPath('data.0.status', 'completed')
            ->assertJsonPath('data.1.amount_kobo', 250100);

        $cycleTwo = $group->contributions()->where('cycle', 2)->get();
        foreach ($cycleTwo as $contribution) {
            Sanctum::actingAs($contribution->member->user);
            $this->postJson("/api/v1/groups/$groupId/contributions/{$contribution->id}/simulate-payment", ['successful' => true])
                ->assertOk();
        }

        Sanctum::actingAs($creator);
        $this->postJson("/api/v1/groups/$groupId/complete-cycle")
            ->assertCreated()->assertJsonPath('member_id', $memberContribution->member_id);
        $this->assertSame('completed', $group->fresh()->status);
        $this->postJson("/api/v1/groups/$groupId/complete-cycle")->assertUnprocessable();
    }

    public function test_non_members_cannot_read_group_or_transactions(): void
    {
        $creator = User::factory()->create();
        $stranger = User::factory()->create();
        Sanctum::actingAs($creator);
        $groupId = $this->postJson('/api/v1/groups', $this->groupData())->assertCreated()->json('id');

        Sanctum::actingAs($stranger);
        $this->getJson("/api/v1/groups/$groupId")->assertForbidden();
        $this->getJson("/api/v1/groups/$groupId/schedule")->assertForbidden();
        $this->getJson('/api/v1/groups')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/transactions')->assertOk()->assertJsonCount(0, 'data');
    }

    private function groupData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Market circle',
            'contribution_amount_kobo' => 125050,
            'frequency' => 'monthly',
            'max_members' => 2,
            'start_date' => now()->addDay()->toDateString(),
        ], $overrides);
    }
}
