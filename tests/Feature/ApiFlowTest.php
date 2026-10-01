<?php

namespace Tests\Feature;

use App\Models\Contribution;
use App\Models\GroupTransaction;
use App\Models\PaymentAttempt;
use App\Models\SavingsGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_organizer_must_approve_members_when_group_requires_approval(): void
    {
        $organizer = User::factory()->create();
        $friend = User::factory()->create();
        Sanctum::actingAs($organizer, ['api']);
        $groupId = $this->postJson('/api/v1/groups', $this->groupData([
            'max_members' => 2,
            'requires_approval' => true,
        ]))->assertCreated()->json('id');
        $group = SavingsGroup::findOrFail($groupId);

        Sanctum::actingAs($friend, ['api']);
        $this->postJson('/api/v1/groups/lookup', ['invite_code' => $group->invite_code])
            ->assertOk()
            ->assertJsonPath('requires_approval', true);
        $this->postJson('/api/v1/groups/join', ['invite_code' => $group->invite_code])
            ->assertStatus(202)
            ->assertJsonPath('join_status', 'pending');
        $this->assertSame(1, $group->members()->count());
        $this->assertSame(1, $group->contributions()->count());
        $this->getJson("/api/v1/groups/$groupId/join-requests")->assertForbidden();

        Sanctum::actingAs($organizer, ['api']);
        $requestId = $this->getJson("/api/v1/groups/$groupId/join-requests")
            ->assertOk()->assertJsonCount(1, 'data')->json('data.0.id');
        $this->postJson("/api/v1/groups/$groupId/join-requests/$requestId/approve")
            ->assertOk()->assertJsonPath('status', 'approved');

        $this->assertSame(2, $group->members()->count());
        $this->assertSame(2, $group->contributions()->count());
        $this->assertSame('active', $group->fresh()->status);
        Sanctum::actingAs($friend, ['api']);
        $this->postJson('/api/v1/groups/lookup', ['invite_code' => $group->invite_code])
            ->assertOk()
            ->assertJsonPath('join_request_status', 'joined');
    }

    public function test_member_can_pay_while_group_is_still_forming(): void
    {
        config(['services.paystack.secret_key' => 'sk_test_for_tests']);
        Http::fake(fn ($request) => Http::response(['status' => true, 'data' => [
            'authorization_url' => 'https://checkout.paystack.com/test-checkout',
            'reference' => $request['reference'],
        ]]));

        $creator = User::factory()->create();
        Sanctum::actingAs($creator, ['api']);
        $groupId = $this->postJson('/api/v1/groups', $this->groupData(['max_members' => 200]))
            ->assertCreated()->json('id');
        $contribution = Contribution::where('group_id', $groupId)->firstOrFail();

        $this->postJson("/api/v1/groups/$groupId/contributions/{$contribution->id}/checkout")
            ->assertOk()
            ->assertJsonPath('authorization_url', 'https://checkout.paystack.com/test-checkout');

        $this->assertSame('forming', SavingsGroup::findOrFail($groupId)->status);
    }

    public function test_reconciliation_rejects_amount_mismatch_then_credits_verified_payment(): void
    {
        config(['services.paystack.secret_key' => 'sk_test_for_tests']);
        $creator = User::factory()->create();
        $member = User::factory()->create();
        Sanctum::actingAs($creator, ['api']);
        $groupId = $this->postJson('/api/v1/groups', $this->groupData())->assertCreated()->json('id');
        $group = SavingsGroup::findOrFail($groupId);
        Sanctum::actingAs($member, ['api']);
        $this->postJson('/api/v1/groups/join', ['invite_code' => $group->invite_code])->assertOk();
        $contribution = $group->contributions()->whereHas('member', fn ($query) => $query->where('user_id', $member->id))->firstOrFail();
        $attempt = PaymentAttempt::create([
            'contribution_id' => $contribution->id,
            'user_id' => $member->id,
            'reference' => 'AJO-RECONCILE-TEST',
            'authorization_url' => 'https://checkout.paystack.com/test-checkout',
            'status' => 'pending',
        ]);
        DB::table('payment_attempts')->where('id', $attempt->id)->update(['updated_at' => now()->subMinutes(6)]);
        $amount = 1;
        Http::fake(function () use (&$amount, $member) {
            return Http::response(['status' => true, 'data' => [
                'reference' => 'AJO-RECONCILE-TEST', 'amount' => $amount,
                'currency' => 'NGN', 'domain' => 'test', 'status' => 'success',
                'customer' => ['email' => $member->email],
            ]]);
        });
        $this->artisan('payments:reconcile')->assertExitCode(0);
        $this->assertSame('pending', $contribution->fresh()->status);
        $amount = $contribution->amount_kobo;
        DB::table('payment_attempts')->where('id', $attempt->id)->update(['updated_at' => now()->subMinutes(6)]);
        $this->assertTrue($attempt->fresh()->updated_at->lessThanOrEqualTo(now()->subMinutes(5)));
        $this->artisan('payments:reconcile')->assertExitCode(0);
        $this->assertSame('paid', $contribution->fresh()->status);
        $this->assertSame(1, GroupTransaction::where('reference', $attempt->reference)->count());
    }

    public function test_signed_webhook_reconciles_once_and_rejects_forgery(): void
    {
        config(['services.paystack.secret_key' => 'sk_test_for_tests']);
        $creator = User::factory()->create();
        $member = User::factory()->create();
        Sanctum::actingAs($creator, ['api']);
        $groupId = $this->postJson('/api/v1/groups', $this->groupData())->assertCreated()->json('id');
        $group = SavingsGroup::findOrFail($groupId);
        Sanctum::actingAs($member, ['api']);
        $this->postJson('/api/v1/groups/join', ['invite_code' => $group->invite_code])->assertOk();
        $contribution = $group->contributions()->whereHas('member', fn ($query) => $query->where('user_id', $member->id))->firstOrFail();
        Http::fake(function ($request) use ($member, $contribution) {
            if (str_ends_with($request->url(), '/transaction/initialize')) {
                return Http::response(['status' => true, 'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/test-checkout',
                    'reference' => $request['reference'],
                ]]);
            }

            return Http::response(['status' => true, 'data' => [
                'reference' => basename($request->url()),
                'amount' => $contribution->amount_kobo,
                'currency' => 'NGN',
                'domain' => 'test',
                'status' => 'success',
                'customer' => ['email' => $member->email],
            ]]);
        });
        $reference = $this->postJson("/api/v1/groups/$groupId/contributions/{$contribution->id}/checkout")
            ->assertOk()->json('reference');
        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $reference]]);
        $this->call('POST', '/api/v1/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => 'invalid',
        ], $body)->assertForbidden();
        $headers = ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_for_tests')];
        $this->call('POST', '/api/v1/paystack/webhook', [], [], [], $headers, $body)->assertOk();
        $this->call('POST', '/api/v1/paystack/webhook', [], [], [], $headers, $body)->assertOk();
        $this->assertSame('paid', $contribution->fresh()->status);
        $this->assertSame(1, GroupTransaction::where('reference', $reference)->count());
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
            'requires_approval' => false,
            'start_date' => now()->addDay()->toDateString(),
        ], $overrides);
    }
}
