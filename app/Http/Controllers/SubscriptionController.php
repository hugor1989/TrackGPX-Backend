<?php

namespace App\Http\Controllers;
use App\Models\Subscription;
use App\Models\Plan;

use Illuminate\Http\Request;

class SubscriptionController extends AppBaseController
{
    public function createSubscription(Request $request)
    {
        $request->validate([
            'plan_id' => 'required|exists:plans,id',
        ]);

        $customer = $request->user();

        $plan = Plan::findOrFail($request->plan_id);

        // 🔹 Insert en la tabla subscriptions
        $subscription = Subscription::create([
            'customer_id' => $customer->id,
            'plan_id'     => $plan->id,
            'status'      => 'pending',
            'start_date'  => null,
            'end_date'    => null,
        ]);

        // Aquí normalmente se crea ¿ la sesión de Stripe Checkout
        // Ejemplo simplificado:
        // $session = \Stripe\Checkout\Session::create([
        //     'payment_method_types' => ['card'],
        //     'line_items' => [[
        //         'price_data' => [
        //             'currency' => 'mxn',
        //             'product_data' => ['name' => $plan->name],
        //             'unit_amount' => $plan->price * 100,
        //         ],
        //         'quantity' => 1,
        //     ]],
        //     'mode' => 'payment',
        //     'success_url' => config('app.url') . '/payment-success',
        //     'cancel_url'  => config('app.url') . '/payment-cancel',
        //     'metadata' => [
        //         'subscription_id' => $subscription->id
        //     ]
        // ]);

        return $this->success([
            'subscription' => $subscription,
            // 'checkout_url' => $session->url // habilitar cuando integres Stripe
        ], 'Suscripción creada correctamente. Procede al pago.');
    }
}
