<?php

namespace App\Console\Commands;

use App\Models\PaymentAttempt;
use App\Support\PaymentReconciler;
use Illuminate\Console\Command;
use Throwable;

class ReconcilePendingPayments extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Verify pending Paystack payments with the provider';

    public function handle(PaymentReconciler $reconciler): int
    {
        PaymentAttempt::where('status', 'pending')
            ->where('updated_at', '<=', now()->subMinutes(5))
            ->orderBy('updated_at')
            ->limit(100)
            ->get()
            ->each(function (PaymentAttempt $attempt) use ($reconciler): void {
                try {
                    $reconciler->verify($attempt);
                } catch (Throwable $exception) {
                    report($exception);
                    $attempt->touch();
                }
            });

        return self::SUCCESS;
    }
}
