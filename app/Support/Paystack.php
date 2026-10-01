<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class Paystack
{
    private function request()
    {
        $secret = config('services.paystack.secret_key');
        if (! is_string($secret) || ! str_starts_with($secret, 'sk_test_')) {
            throw new RuntimeException('Payments are not configured.');
        }

        return Http::baseUrl(config('services.paystack.base_url'))
            ->withToken($secret)->acceptJson()->timeout(10)->retry(2, 300);
    }

    public function initialize(string $email, int $amountKobo, string $reference): array
    {
        $response = $this->request()->post('/transaction/initialize', [
            'email' => $email,
            'amount' => $amountKobo,
            'currency' => 'NGN',
            'reference' => $reference,
        ]);
        if (! $response->successful() || $response->json('status') !== true) {
            throw new RuntimeException('Payment checkout is unavailable. Please retry.');
        }
        $url = $response->json('data.authorization_url');
        $returnedReference = $response->json('data.reference');
        if (! is_string($url) || ! str_starts_with($url, 'https://checkout.paystack.com/') || $returnedReference !== $reference) {
            throw new RuntimeException('Invalid response from payment provider.');
        }

        return ['authorization_url' => $url, 'reference' => $reference];
    }

    public function verify(string $reference): array
    {
        $response = $this->request()->get('/transaction/verify/'.rawurlencode($reference));
        if (! $response->successful() || $response->json('status') !== true || ! is_array($response->json('data'))) {
            throw new RuntimeException('Could not verify payment. Please retry.');
        }

        return $response->json('data');
    }
}
