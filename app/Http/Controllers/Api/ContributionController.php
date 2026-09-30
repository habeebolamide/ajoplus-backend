<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contribution;
use App\Models\PaymentAttempt;
use App\Models\SavingsGroup;
use App\Support\PaymentReconciler;
use App\Support\Paystack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ContributionController extends Controller
{
    public function checkout(Request $request, SavingsGroup $group, Contribution $contribution, Paystack $paystack): JsonResponse
    {
        $this->authorizeContribution($request, $group, $contribution);
        $attempt = DB::transaction(function () use ($group, $contribution, $request): PaymentAttempt {
            $locked = Contribution::whereKey($contribution->id)->lockForUpdate()->firstOrFail();
            $group->refresh();
            if ($group->status !== 'active' || $locked->cycle !== $group->current_cycle || $locked->status === 'paid') {
                throw ValidationException::withMessages(['contribution' => 'This contribution cannot be paid now.']);
            }
            $existing = PaymentAttempt::where('contribution_id', $locked->id)
                ->whereIn('status', ['initializing', 'pending'])->latest()->first();
            if ($existing) return $existing;

            return PaymentAttempt::create([
                'contribution_id' => $locked->id,
                'user_id' => $request->user()->id,
                'reference' => 'AJO-'.Str::uuid(),
                'status' => 'initializing',
            ]);
        });

        if ($attempt->status === 'initializing' && $attempt->wasRecentlyCreated) {
            try {
                $checkout = $paystack->initialize($request->user()->email, $contribution->amount_kobo, $attempt->reference);
                $attempt->update(['authorization_url' => $checkout['authorization_url'], 'status' => 'pending']);
            } catch (Throwable $exception) {
                $attempt->update(['status' => 'failed']);
                report($exception);
                return response()->json(['message' => 'Payment checkout is unavailable. Please retry.'], 503);
            }
        }
        if ($attempt->status === 'initializing') {
            return response()->json(['message' => 'Payment checkout is being prepared. Please retry shortly.'], 409);
        }

        return response()->json(['reference' => $attempt->reference, 'authorization_url' => $attempt->authorization_url]);
    }

    public function verify(Request $request, SavingsGroup $group, Contribution $contribution, PaymentReconciler $reconciler): JsonResponse
    {
        $this->authorizeContribution($request, $group, $contribution);
        $attempt = PaymentAttempt::where('contribution_id', $contribution->id)->latest()->firstOrFail();
        try {
            $reconciler->verify($attempt);
        } catch (Throwable $exception) {
            report($exception);
            return response()->json(['message' => 'Payment verification is unavailable. Please retry.'], 503);
        }

        return response()->json($contribution->fresh());
    }

    private function authorizeContribution(Request $request, SavingsGroup $group, Contribution $contribution): void
    {
        abort_unless($contribution->group_id === $group->id, 404);
        abort_unless($contribution->member->user_id === $request->user()->id, 403);
    }
}
