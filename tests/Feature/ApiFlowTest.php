<?php

namespace Tests\Feature;

use App\Models\Contribution;
use App\Models\GroupTransaction;
use App\Models\PaymentAttempt;
use App\Models\SavingsGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_refresh_and_logout(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();
        $access = $response->json('token');
        $refresh = $response->json('refresh_token');
        $this->withToken($access)->getJson('/api/v1/auth/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($refresh)->getJson('/api/v1/auth/me')->assertForbidden();

        $rotated = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])->assertOk();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $newAccess = $rotated->json('token');
        $this->withToken($newAccess)->postJson('/api/v1/auth/logout', [
            'refresh_token' => $rotated->json('refresh_token'),
        ])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($newAccess)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_verified_paystack_contributions_and_manual_payout(): void
    {
        config(['services.paystack.secret_key' => 'sk_test_for_tests']);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/transaction/initialize')) {
                return Http::response(['status' => true, 'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/test-checkout',
                    'reference' => $request['reference'],
                ]]);
            }
            $reference = basename($request->url());
            $attempt = PaymentAttempt::where('reference', $reference)->firstOrFail();

            return Http::response(['status' => true, 'data' => [
                'reference' => $reference,
                'amount' => $attempt->contribution->amount_kobo,
                'currency' => 'NGN',
                'domain' => 'test',
                'status' => 'success',
                'customer' => ['email' => $attempt->user->email],
            ]]);
        });

        $creator = User::factory()->create();
        $member = User::factory()->create();
        Sanctum::actingAs($creator, ['api']);
        $this->postJson('/api/v1/groups', $this->groupData(['contribution_amount_kobo' => 100.50]))
            ->assertUnprocessable();
        $groupId = $this->postJson('/api/v1/groups', $this->groupData())
            ->assertCreated()->json('id');
        $group = SavingsGroup::findOrFail($groupId);
        $this->assertSame(125050, $group->contribution_amount_kobo);

        Sanctum::actingAs($member, ['api']);
        $this->postJson('/api/v1/groups/lookup', ['invite_code' => $group->invite_code])
            ->assertOk()->assertJsonPath('members_count', 1);
        $this->postJson('/api/v1/groups/join', ['invite_code' => $group->invite_code])
            ->assertOk()->assertJsonPath('status', 'active');
        $memberContribution = $group->contributions()->whereHas('member',
            fn ($query) => $query->where('user_id', $member->id))->firstOrFail();
        $creatorContribution = $group->contributions()->whereHas('member',
            fn ($query) => $query->where('user_id', $creator->id))->firstOrFail();

        $this->postJson("/api/v1/groups/$groupId/contributions/{$creatorContribution->id}/checkout")
            ->assertForbidden();
        $this->payContribution($groupId, $memberContribution);
        $this->postJson("/api/v1/groups/$groupId/contributions/{$memberContribution->id}/checkout")
            ->assertUnprocessable();
        Sanctum::actingAs($creator, ['api']);
        $this->postJson("/api/v1/groups/$groupId/complete-cycle")->assertUnprocessable();
        $this->payContribution($groupId, $creatorContribution);
        $this->postJson("/api/v1/groups/$groupId/complete-cycle")
            ->assertCreated()->assertJsonPath('status', 'pending');
        $this->assertSame(0, GroupTransaction::where('type', 'payout')->count());
        $this->postJson("/api/v1/groups/$groupId/complete-cycle")->assertUnprocessable();
        $this->postJson("/api/v1/groups/$groupId/settle-payout", ['reference' => 'BANK-12345'])
            ->assertOk()->assertJsonPath('status', 'completed');
        $this->assertSame(2, $group->fresh()->current_cycle);
        $this->assertSame(2, Contribution::where('group_id', $groupId)->where('cycle', 2)->count());
        $this->getJson('/api/v1/transactions?type=payout')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/groups/$groupId/schedule")->assertOk()->assertJsonPath('data.0.status', 'completed');
    }

    public function test_non_members_cannot_read_group(): void
    {
        $creator = User::factory()->create();
        $stranger = User::factory()->create();
        Sanctum::actingAs($creator, ['api']);
        $groupId = $this->postJson('/api/v1/groups', $this->groupData())->assertCreated()->json('id');
        Sanctum::actingAs($stranger, ['api']);
        $this->getJson("/api/v1/groups/$groupId")->assertForbidden();
        $this->getJson('/api/v1/groups')->assertOk()->assertJsonCount(0, 'data');
    }

    private function payContribution(int $groupId, Contribution $contribution): void
    {
        $path = "/api/v1/groups/$groupId/contributions/{$contribution->id}";
        $checkout = $this->postJson("$path/checkout")->assertOk();
        $this->assertStringStartsWith('AJO-', $checkout->json('reference'));
        $this->postJson("$path/verify")->assertOk()->assertJsonPath('status', 'paid');
        $this->postJson("$path/verify")->assertOk();
        $this->assertSame(1, GroupTransaction::where('reference', $checkout->json('reference'))->count());
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
