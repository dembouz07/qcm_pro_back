<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PayTechService
{
    private string $baseUrl = 'https://paytech.sn/api';

    private function headers(): array
    {
        return [
            'API_KEY' => (string) config('services.paytech.api_key'),
            'API_SECRET' => (string) config('services.paytech.api_secret'),
            'Content-Type' => 'application/json',
        ];
    }

    public function isConfigured(): bool
    {
        return (bool) config('services.paytech.api_key')
            && (bool) config('services.paytech.api_secret');
    }

    /**
     * Crée une demande de paiement PayTech.
     * Retourne ['token' => ..., 'url' => ...] ou null en cas d'échec.
     */
    public function requestPayment(array $params): ?array
    {
        $payload = [
            'item_name' => $params['item_name'],
            'item_price' => $params['amount'],
            'currency' => 'XOF',
            'ref_command' => $params['ref_command'],
            'command_name' => $params['command_name'] ?? $params['item_name'],
            'env' => config('services.paytech.env', 'test'),
            'ipn_url' => $params['ipn_url'],
            'success_url' => $params['success_url'],
            'cancel_url' => $params['cancel_url'],
            'custom_field' => json_encode($params['custom_field'] ?? []),
        ];

        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->withHeaders($this->headers())
                ->post($this->baseUrl . '/payment/request-payment', $payload);
        } catch (\Throwable $e) {
            Log::error('PayTech requestPayment error: ' . $e->getMessage());
            return null;
        }

        $data = $response->json();

        if ((int) ($data['success'] ?? 0) === 1 && !empty($data['redirect_url'])) {
            return [
                'token' => $data['token'] ?? null,
                'url' => $data['redirect_url'],
            ];
        }

        Log::warning('PayTech requestPayment refusé', ['body' => $response->body()]);
        return null;
    }

    /**
     * Récupère le statut d'un paiement à partir de son token.
     */
    public function getStatus(string $token): array
    {
        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->withHeaders($this->headers())
                ->get($this->baseUrl . '/payment/get-status', [
                    'token_payment' => $token,
                ]);
        } catch (\Throwable $e) {
            Log::error('PayTech getStatus error: ' . $e->getMessage());
            return [];
        }

        return $response->json() ?? [];
    }

    /**
     * Vérifie l'authenticité d'une notification IPN via le hachage SHA256 des clés API.
     */
    public function verifyIpn(Request $request): bool
    {
        $keyHash = (string) $request->input('api_key_sha256');
        $secretHash = (string) $request->input('api_secret_sha256');

        if ($keyHash === '' || $secretHash === '') {
            return false;
        }

        $expectedKey = hash('sha256', (string) config('services.paytech.api_key'));
        $expectedSecret = hash('sha256', (string) config('services.paytech.api_secret'));

        return hash_equals($expectedKey, $keyHash)
            && hash_equals($expectedSecret, $secretHash);
    }
}
