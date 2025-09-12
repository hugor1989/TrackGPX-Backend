<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Subscription;
use App\Models\Plan;
use Illuminate\Support\Carbon;

class SubscriptionWebhookController extends AppBaseController
{
    public function handleStripeWebhook(Request $request)
    {
        // ⚠️ IMPORTANTE: validar firma del webhook (Stripe manda header: Stripe-Signature)
        // Para simplificar, aquí omitimos validación. En producción usa la librería oficial.

        $payload = $request->all();

        // Detectar evento de pago exitoso
        if (isset($payload['type']) && $payload['type'] === 'checkout.session.completed') {
            $session = $payload['data']['object'];

            // Aquí deberías tener en tu sesión de Stripe el subscription_id de tu BD
            $subscriptionId = $session['metadata']['subscription_id'] ?? null;

            if ($subscriptionId) {
                $subscription = Subscription::find($subscriptionId);

                if ($subscription) {
                    $plan = $subscription->plan;

                    $start = Carbon::now();
                    $end = Carbon::now()->addDays($plan->duration_days);

                    $subscription->update([
                        'status'            => 'active',
                        'start_date'        => $start,
                        'end_date'          => $end,
                        'payment_reference' => $session['payment_intent'] ?? null,
                        'paid_at'           => now(),
                    ]);
                }
            }
        }

        return response()->json(['received' => true]);
    }
}
