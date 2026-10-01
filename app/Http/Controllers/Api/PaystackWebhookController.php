<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentAttempt;
use App\Support\PaymentReconciler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaystackWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentReconciler $reconciler): JsonResponse
    {
        $secret = config('services.paystack.secret_key');
        $signature = $request->header('x-paystack-signature', '');
        abort_unless(is_string($secret) && $secret !== '' && hash_equals(hash_hmac('sha512', $request->getContent(), $secret), $signature), 403);

        if ($request->input('event') === 'charge.success') {
            $reference = $request->input('data.reference');
            $attempt = is_string($reference) ? PaymentAttempt::where('reference', $reference)->first() : null;
            if ($attempt) {
                $reconciler->verify($attempt);
            }
        }

        return response()->json(['received' => true]);
    }
}
