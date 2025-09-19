<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\User;
use App\Models\Plan;
use App\Services\OpenPayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    protected $openPayService;

    public function __construct(OpenPayService $openPayService)
    {
        $this->openPayService = $openPayService;
    }

    public function handleOpenPayWebhook(Request $request)
    {
        // Loggear el webhook recibido para debugging
        Log::info('OpenPay Webhook Received', [
            'headers' => $request->headers->all(),
            'payload' => $request->all()
        ]);

        // Verificar la firma del webhook
        $payload = $request->getContent();
        $signature = $request->header('X-Openpay-Signature');

        if (!$this->openPayService->verifyWebhookSignature($payload, $signature)) {
            Log::warning('Invalid webhook signature', [
                'received_signature' => $signature,
                'payload' => $payload
            ]);

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $event = $request->all();

        // Procesar el tipo de evento
        switch ($event['type']) {
            case 'charge.succeeded':
                $this->handleChargeSucceeded($event);
                break;

            case 'charge.cancelled':
                $this->handleChargeCancelled($event);
                break;

            case 'charge.failed':
                $this->handleChargeFailed($event);
                break;

            case 'subscription.created':
                $this->handleSubscriptionCreated($event);
                break;

            case 'subscription.cancelled':
                $this->handleSubscriptionCancelled($event);
                break;

            case 'subscription.paid':
                $this->handleSubscriptionPaid($event);
                break;

            case 'subscription.payment_failed':
                $this->handleSubscriptionPaymentFailed($event);
                break;

            case 'payout.created':
                $this->handlePayoutCreated($event);
                break;

            case 'payout.succeeded':
                $this->handlePayoutSucceeded($event);
                break;

            case 'payout.failed':
                $this->handlePayoutFailed($event);
                break;

            default:
                Log::info('Unhandled webhook type: ' . $event['type']);
                break;
        }

        return response()->json(['status' => 'success']);
    }

    protected function handleChargeSucceeded($event)
    {
        $charge = $event['transaction'];
        Log::info('Charge succeeded', $charge);

        // Aquí puedes actualizar tu base de datos con el pago exitoso
        // Por ejemplo, marcar una orden como pagada
    }

    protected function handleChargeFailed($event)
    {
        $charge = $event['transaction'];
        Log::warning('Charge failed', $charge);

        // Notificar al usuario sobre el pago fallido
    }

    protected function handleSubscriptionCreated($event)
    {
        $subscriptionData = $event['transaction'];
        Log::info('Subscription created', $subscriptionData);

        // Buscar el usuario por customer_id
        $user = User::where('openpay_customer_id', $subscriptionData['customer_id'])->first();

        if ($user) {
            Subscription::updateOrCreate(
                ['openpay_subscription_id' => $subscriptionData['id']],
                [
                    'user_id' => $user->id,
                    'plan_id' => $this->getPlanIdByOpenPayId($subscriptionData['plan_id']),
                    'status' => $subscriptionData['status'],
                    'current_period_start' => $subscriptionData['current_period_start'],
                    'current_period_end' => $subscriptionData['current_period_end'],
                    'trial_start' => $subscriptionData['trial_start'] ?? null,
                    'trial_end' => $subscriptionData['trial_end'] ?? null,
                ]
            );
        }
    }

    protected function handleSubscriptionPaid($event)
    {
        $subscriptionData = $event['transaction'];
        Log::info('Subscription paid', $subscriptionData);

        // Actualizar la suscripción con la nueva fecha de renovación
        $subscription = Subscription::where('openpay_subscription_id', $subscriptionData['id'])->first();

        if ($subscription) {
            $subscription->update([
                'status' => 'active',
                'current_period_start' => $subscriptionData['current_period_start'],
                'current_period_end' => $subscriptionData['current_period_end'],
                'last_payment_date' => now(),
            ]);
        }
    }

    protected function handleSubscriptionCancelled($event)
    {
        $subscriptionData = $event['transaction'];
        Log::info('Subscription cancelled', $subscriptionData);

        $subscription = Subscription::where('openpay_subscription_id', $subscriptionData['id'])->first();

        if ($subscription) {
            $subscription->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);
        }
    }

    protected function handleSubscriptionPaymentFailed($event)
    {
        $subscriptionData = $event['transaction'];
        Log::warning('Subscription payment failed', $subscriptionData);

        $subscription = Subscription::where('openpay_subscription_id', $subscriptionData['id'])->first();

        if ($subscription) {
            $subscription->update([
                'status' => 'past_due',
                'last_payment_attempt' => now(),
            ]);

            // Notificar al usuario sobre el pago fallido
            // $user = $subscription->user;
            // Mail::to($user->email)->send(new PaymentFailed($subscription));
        }
    }

    protected function getPlanIdByOpenPayId($openpayPlanId)
    {
        // Buscar el plan en tu base de datos por el ID de OpenPay
        $plan = Plan::where('openpay_plan_id', $openpayPlanId)->first();
        return $plan ? $plan->id : null;
    }

    // Manejar otros tipos de eventos según sea necesario
    protected function handleChargeCancelled($event) { /* ... */ }
    protected function handlePayoutCreated($event) { /* ... */ }
    protected function handlePayoutSucceeded($event) { /* ... */ }
    protected function handlePayoutFailed($event) { /* ... */ }
}