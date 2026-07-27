<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\User;
use App\Services\PayTechService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    public function status(Request $request)
    {
        return response()->json($this->statusPayload($request->user()));
    }

    /**
     * Démarre un paiement PayTech et renvoie l'URL de paiement.
     */
    public function checkout(Request $request, PayTechService $paytech)
    {
        $user = $request->user();
        $availablePlans = array_keys($user->availableSubscriptionPlans());

        $data = $request->validate([
            'plan' => ['required', 'string', Rule::in($availablePlans)],
        ]);

        try {
            if (!$paytech->isConfigured()) {
                return response()->json([
                    'message' => "Le paiement n'est pas encore configuré. Contactez l'administrateur.",
                ], 503);
            }

            $plan = $data['plan'];
            $planDetails = User::subscriptionPlans()[$plan];
            $amount = (int) $planDetails['price'];
            $frontend = rtrim((string) config('services.paytech.frontend_url'), '/');
            $subscriptionPath = $user->role === 'enterprise' ? '/entreprise/abonnement' : '/admin/subscription';
            $refCommand = 'QCMPRO_' . strtoupper($plan) . '_' . $user->id . '_' . time();

            $payment = $paytech->requestPayment([
                'item_name' => 'Check Performance - Formule ' . $planDetails['name'],
                'command_name' => "Formule {$planDetails['name']} (1 mois) - {$user->email}",
                'amount' => $amount,
                'ref_command' => $refCommand,
                'success_url' => $frontend . $subscriptionPath . '?paid=1',
                'cancel_url' => $frontend . $subscriptionPath . '?canceled=1',
                'ipn_url' => url('/api/payments/paytech/ipn'),
                'custom_field' => [
                    'user_id' => $user->id,
                    'ref_command' => $refCommand,
                    'plan' => $plan,
                ],
            ]);

            if (!$payment || empty($payment['token'])) {
                return response()->json([
                    'message' => "Impossible de créer le paiement. Vérifiez la configuration PayTech.",
                ], 502);
            }

            Payment::create([
                'user_id' => $user->id,
                'provider' => 'paytech',
                'token' => $payment['token'],
                'amount' => $amount,
                'currency' => 'XOF',
                'status' => 'pending',
                'meta' => ['ref_command' => $refCommand, 'plan' => $plan],
            ]);

            return response()->json(['url' => $payment['url']]);
        } catch (\Throwable $e) {
            Log::error('PayTech checkout error: ' . $e->getMessage());
            return response()->json([
                'message' => "Une erreur est survenue lors de l'initialisation du paiement. Réessayez.",
            ], 500);
        }
    }

    /**
     * Notification IPN appelée par PayTech (public).
     */
    public function ipn(Request $request, PayTechService $paytech)
    {
        if (!$paytech->verifyIpn($request)) {
            Log::warning('PayTech IPN rejeté (signature invalide)');
            return response()->json(['message' => 'IPN KO'], 403);
        }

        $typeEvent = $request->input('type_event');
        $token = $request->input('token');
        $refCommand = $request->input('ref_command');

        $payment = null;
        if ($token) {
            $payment = Payment::where('token', $token)->first();
        }
        if (!$payment && $refCommand) {
            $payment = Payment::where('meta->ref_command', $refCommand)->first();
        }

        if (!$payment) {
            Log::warning('PayTech IPN : paiement introuvable', ['token' => $token, 'ref' => $refCommand]);
            return response()->json(['message' => 'IPN OK'], 200);
        }

        if ($typeEvent === 'sale_complete') {
            $this->markCompleted($payment, $request->all());
        } elseif ($typeEvent === 'sale_canceled') {
            if ($payment->status !== 'completed') {
                $payment->update(['status' => 'failed', 'meta' => array_merge((array) $payment->meta, $request->all())]);
            }
        }

        return response()->json(['message' => 'IPN OK'], 200);
    }

    /**
     * Vérification déclenchée par le frontend au retour de paiement.
     */
    public function verify(Request $request, PayTechService $paytech)
    {
        $user = $request->user();
        $payment = Payment::where('user_id', $user->id)
            ->where('status', 'pending')
            ->latest()
            ->first();

        if ($payment && $payment->token) {
            $result = $paytech->getStatus($payment->token);
            // On active si PayTech confirme le paiement.
            $state = strtolower((string) (
                $result['status']
                ?? ($result['payment']['status'] ?? '')
                ?? ''
            ));
            if (in_array($state, ['sale_complete', 'complete', 'completed', 'success', 'paid'], true)) {
                $this->markCompleted($payment, $result);
            }
        }

        $user->refresh();

        return response()->json($this->statusPayload($user));
    }

    /**
     * Marque le paiement comme complété et prolonge l'abonnement d'un mois.
     */
    private function markCompleted(Payment $payment, array $meta = []): void
    {
        if ($payment->status === 'completed') {
            return;
        }

        $paymentMeta = (array) $payment->meta;
        $plan = (string) ($paymentMeta['plan'] ?? '');

        $payment->update([
            'status' => 'completed',
            'meta' => array_merge((array) $payment->meta, $meta),
        ]);

        $user = User::find($payment->user_id);
        if ($user) {
            if (!in_array($plan, User::paidPlanIds(), true)) {
                // Les anciens paiements donnaient accès à toutes les fonctionnalités.
                $plan = in_array($user->subscription_plan, User::paidPlanIds(), true)
                    ? $user->subscription_plan
                    : ($user->isEnterprise() ? User::PLAN_ENTERPRISE : User::PLAN_PREMIUM);
            }

            // Prolonge à partir de la date d'expiration si encore active, sinon à partir de maintenant.
            $base = $user->subscribed_until && $user->subscribed_until->isFuture()
                ? $user->subscribed_until
                : Carbon::now();

            $user->update([
                'subscription_plan' => $plan,
                'subscription_status' => 'active',
                'subscribed_until' => $base->copy()->addMonth(),
            ]);
        }
    }

    private function statusPayload(User $user): array
    {
        return [
            'subscription_status' => $user->subscription_status,
            'subscription_plan' => $user->subscription_plan ?: User::PLAN_FREE,
            'current_plan' => $user->effectiveSubscriptionPlan(),
            'subscribed_until' => $user->subscribed_until,
            'is_active' => $user->isPaidSubscriptionActive(),
            'features' => $user->plan_features,
            'plans' => array_values($user->availableSubscriptionPlans()),
            'currency' => 'XOF',
        ];
    }
}
