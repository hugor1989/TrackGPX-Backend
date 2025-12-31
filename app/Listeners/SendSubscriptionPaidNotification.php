<?php
// app/Listeners/SendSubscriptionPaidNotification.php

namespace App\Listeners;

use App\Events\SubscriptionPaid;
use App\Services\OneSignalService;
use Illuminate\Support\Facades\Log;
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
        try {
            $subscription = $event->subscription;
            $customer = $subscription->customer;

            if (!$customer || !$customer->expo_push_token) {
                Log::warning('No se puede enviar notificación de pago - sin customer o token', [
                    'subscription_id' => $subscription->id,
                ]);
                return;
            }

            $plan = $subscription->plan;
            $device = $subscription->device;

            $title = '✅ Pago Confirmado';
            $message = "Tu suscripción de {$plan->name} ha sido confirmada. ";
            
            if ($device) {
                $vehicle = $device->vehicle;
                $deviceName = $vehicle ? ($vehicle->alias ?? $vehicle->plates) : $device->imei;
                $message .= "Dispositivo: {$deviceName}. ";
            }
            
            $message .= "Válida hasta: " . $subscription->end_date->format('d/m/Y');

            Log::info('📤 Enviando notificación de pago confirmado', [
                'customer_id' => $customer->id,
                'subscription_id' => $subscription->id,
                'external_id' => $customer->expo_push_token,
            ]);

            $result = $this->oneSignal->sendToUser(
                $customer->expo_push_token,
                $title,
                $message,
                [
                    'type' => 'subscription_paid',
                    'subscription_id' => $subscription->id,
                    'plan_id' => $plan->id,
                    'plan_name' => $plan->name,
                    'device_id' => $device?->id,
                    'start_date' => $subscription->start_date->toDateString(),
                    'end_date' => $subscription->end_date->toDateString(),
                ]
            );

            if ($result) {
                Log::info('✅ Notificación de pago enviada exitosamente');
            } else {
                Log::error('❌ Falló el envío de notificación de pago');
            }

        } catch (\Exception $e) {
            Log::error('❌ Excepción en SendSubscriptionPaidNotification', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}