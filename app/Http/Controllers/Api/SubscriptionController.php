<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\User;
use App\Services\PayDunyaService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SubscriptionController extends Controller
{
    public function status(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'subscription_status' => $user->subscription_status,
            'subscribed_until' => $user->subscribed_until,
            'is_active' => $user->hasActiveSubscription(),
            'amount' => (int) config('services.paydunya.amount'),
            'currency' => 'XOF',
        ]);
    }

    /**
     * Démarre un paiement PayDunya et renvoie l'URL de paiement.
     */
    public function checkout(Request $request, PayDunyaService $paydunya)
    {
        if (!$paydunya->isConfigured()) {
            return response()->json([
                'message' => "Le paiement n'est pas encore configuré. Contactez l'administrateur.",
            ], 503);
        }

        $user = $request->user();
        $amount = (int) config('services.paydunya.amount');
        $frontend = rtrim(config('services.paydunya.frontend_url'), '/');

        $invoice = $paydunya->createInvoice([
            'amount' => $amount,
            'description' => "Abonnement mensuel QCM Pro - {$user->email}",
            'return_url' => $frontend . '/admin/subscription?paid=1',
            'cancel_url' => $frontend . '/admin/subscription?canceled=1',
            'callback_url' => url('/api/payments/paydunya/callback'),
            'custom_data' => ['user_id' => $user->id],
        ]);

        if (!$invoice) {
            return response()->json([
                'message' => "Impossible de créer la facture de paiement. Réessayez.",
            ], 502);
        }

        Payment::create([
            'user_id' => $user->id,
            'provider' => 'paydunya',
            'token' => $invoice['token'],
            'amount' => $amount,
            'currency' => 'XOF',
            'status' => 'pending',
        ]);

        return response()->json(['url' => $invoice['url']]);
    }

    /**
     * Callback IPN appelé par PayDunya (public).
     */
    public function callback(Request $request, PayDunyaService $paydunya)
    {
        $token = $request->input('token') ?? $request->input('data.invoice.token');
        if (!$token) {
            return response()->json(['message' => 'Token manquant.'], 400);
        }

        $this->activateFromToken($token, $paydunya);

        return response()->json(['message' => 'OK']);
    }

    /**
     * Vérification déclenchée par le frontend au retour de paiement.
     */
    public function verify(Request $request, PayDunyaService $paydunya)
    {
        $user = $request->user();
        $payment = Payment::where('user_id', $user->id)
            ->where('status', 'pending')
            ->latest()
            ->first();

        if ($payment) {
            $this->activateFromToken($payment->token, $paydunya);
        }

        $user->refresh();

        return response()->json([
            'subscription_status' => $user->subscription_status,
            'subscribed_until' => $user->subscribed_until,
            'is_active' => $user->hasActiveSubscription(),
        ]);
    }

    /**
     * Confirme le paiement auprès de PayDunya et active l'abonnement (1 mois).
     */
    private function activateFromToken(string $token, PayDunyaService $paydunya): void
    {
        $payment = Payment::where('token', $token)->first();
        if (!$payment || $payment->status === 'completed') {
            return;
        }

        $result = $paydunya->confirm($token);
        $status = $result['status'] ?? null;

        if ($status === 'completed') {
            $payment->update(['status' => 'completed', 'meta' => $result]);

            $user = User::find($payment->user_id);
            if ($user) {
                // Prolonge à partir de la date d'expiration si encore active, sinon à partir de maintenant
                $base = $user->subscribed_until && $user->subscribed_until->isFuture()
                    ? $user->subscribed_until
                    : Carbon::now();

                $user->update([
                    'subscription_status' => 'active',
                    'subscribed_until' => $base->copy()->addMonth(),
                ]);
            }
        } elseif (in_array($status, ['cancelled', 'failed'], true)) {
            $payment->update(['status' => 'failed', 'meta' => $result]);
        }
    }
}
