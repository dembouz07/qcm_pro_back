<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PayDunyaService
{
    private function baseUrl(): string
    {
        return config('services.paydunya.mode') === 'live'
            ? 'https://app.paydunya.com/api/v1'
            : 'https://app.paydunya.com/sandbox-api/v1';
    }

    private function headers(): array
    {
        return [
            'PAYDUNYA-MASTER-KEY' => config('services.paydunya.master_key'),
            'PAYDUNYA-PRIVATE-KEY' => config('services.paydunya.private_key'),
            'PAYDUNYA-TOKEN' => config('services.paydunya.token'),
            'Content-Type' => 'application/json',
        ];
    }

    public function isConfigured(): bool
    {
        return config('services.paydunya.master_key')
            && config('services.paydunya.private_key')
            && config('services.paydunya.token');
    }

    /**
     * Crée une facture PayDunya. Retourne ['token' => ..., 'url' => ...] ou null.
     */
    public function createInvoice(array $params): ?array
    {
        $payload = [
            'invoice' => [
                'total_amount' => $params['amount'],
                'description' => $params['description'] ?? 'Abonnement QCM Pro',
            ],
            'store' => [
                'name' => config('services.paydunya.store_name'),
            ],
            'actions' => [
                'cancel_url' => $params['cancel_url'],
                'return_url' => $params['return_url'],
                'callback_url' => $params['callback_url'],
            ],
            'custom_data' => $params['custom_data'] ?? [],
        ];

        try {
            $response = Http::timeout(20)
                ->connectTimeout(10)
                ->withHeaders($this->headers())
                ->post($this->baseUrl() . '/checkout-invoice/create', $payload);
        } catch (\Throwable $e) {
            Log::error('PayDunya createInvoice error: ' . $e->getMessage());
            return null;
        }

        $data = $response->json();

        if (($data['response_code'] ?? null) === '00') {
            return [
                'token' => $data['token'],
                'url' => $data['response_text'], // URL de paiement
            ];
        }

        Log::warning('PayDunya createInvoice refuse', ['body' => $response->body()]);
        return null;
    }

    /**
     * Vérifie le statut d'une facture. Retourne le tableau de réponse PayDunya.
     */
    public function confirm(string $token): array
    {
        try {
            $response = Http::timeout(20)
                ->connectTimeout(10)
                ->withHeaders($this->headers())
                ->get($this->baseUrl() . '/checkout-invoice/confirm/' . $token);
        } catch (\Throwable $e) {
            Log::error('PayDunya confirm error: ' . $e->getMessage());
            return [];
        }

        return $response->json() ?? [];
    }
}
