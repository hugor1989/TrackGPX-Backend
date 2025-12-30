<?php

namespace App\Listeners;

use App\Events\SubscriptionPaid;
use App\Services\OneSignalService;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendSubscriptionPaidNotification implements ShouldQueue
{
    private OneSignalService $oneSignal;

    public function __construct(OneSignalService $oneSignal)
    {
        $this->oneSignal = $oneSignal;
    }

    public function handle(SubscriptionPaid $event): void
    {
        $subscription = $event->subscription;
        $customer = $subscription->customer;

        if (!$customer->expo_push_token) {
            return;
        }

        $plan = $subscription->plan;
        $device = $subscription->device;

        $title = '✅ Pago Confirmado';
        $message = "Tu suscripción de {$plan->name} ha sido confirmada. ";
        
        if ($device) {
            $message .= "Dispositivo: {$device->imei}";
        }
        
        $message .= " Válida hasta: " . $subscription->end_date->format('d/m/Y');

        $this->oneSignal->sendToUser(
            $customer->expo_push_token,
            $title,
            $message,
            [
                'type' => 'subscription_paid',
                'subscription_id' => $subscription->id,
                'device_id' => $device?->id,
                'end_date' => $subscription->end_date->toDateString(),
            ]
        );
    }
}