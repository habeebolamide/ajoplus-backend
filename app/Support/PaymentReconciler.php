<?php

namespace App\Support;

use App\Models\AppNotification;
use App\Models\Contribution;
use App\Models\GroupTransaction;
use App\Models\PaymentAttempt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaymentReconciler
{
    public function __construct(private Paystack $paystack) {}

    public function verify(PaymentAttempt $attempt): void
    {
        $data = $this->paystack->verify($attempt->reference);
        $contribution = $attempt->contribution;
        if (($data['reference'] ?? null) !== $attempt->reference
            || ($data['amount'] ?? null) !== $contribution->amount_kobo
            || ($data['currency'] ?? null) !== 'NGN'
            || ($data['domain'] ?? null) !== 'test'
            || ($data['customer']['email'] ?? null) !== $attempt->user->email) {
            throw new RuntimeException('Payment verification details do not match.');
        }

        DB::transaction(function () use ($attempt, $data): void {
            $locked = PaymentAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $contribution = Contribution::whereKey($locked->contribution_id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'successful') {
                return;
            }
            if (($data['status'] ?? null) !== 'success') {
                if (in_array($data['status'] ?? null, ['failed', 'abandoned'], true)) {
                    $locked->update(['status' => 'failed']);
                    if ($contribution->status !== 'paid') {
                        $contribution->update(['status' => 'failed']);
                    }
                } else {
                    $locked->touch();
                }

                return;
            }
            if ($contribution->status === 'paid') {
                $locked->update(['status' => 'duplicate']);
                report(new RuntimeException('Duplicate successful Paystack charge: '.$locked->reference));

                return;
            }
            $locked->update(['status' => 'successful']);
            $contribution->update(['status' => 'paid', 'payment_reference' => $locked->reference, 'paid_at' => now()]);
            GroupTransaction::create([
                'group_id' => $contribution->group_id,
                'member_id' => $contribution->member_id,
                'type' => 'contribution',
                'amount_kobo' => $contribution->amount_kobo,
                'status' => 'successful',
                'reference' => $locked->reference,
            ]);
            AppNotification::create([
                'user_id' => $locked->user_id,
                'title' => 'Contribution received',
                'message' => 'Your contribution was verified.',
                'type' => 'contribution',
            ]);
        });
    }
}
