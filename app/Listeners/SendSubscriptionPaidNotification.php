<?php
// app/Listeners/SendSubscriptionPaidNotification.php

namespace App\Listeners;

use App\Events\SubscriptionPaid;
use App\Models\Notification;
use App\Services\OneSignalService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

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

            if (!$customer) {
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

            $notificationData = [
                'subscription_id' => $subscription->id,
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'device_id' => $device?->id,
                'start_date' => $subscription->start_date->toDateString(),
                'end_date' => $subscription->end_date->toDateString(),
            ];

            // ✅ GUARDAR EN BASE DE DATOS
            $notification = Notification::create([
                'customer_id' => $customer->id,
                'event_id' => null,
                'type' => 'subscription_paid',
                'title' => $title,
                'message' => $message,
                'data' => $notificationData,
                'is_read' => false,
                'push_sent' => false,
            ]);

            // ✅ ENVIAR PUSH NOTIFICATION
            if ($customer->expo_push_token) {
                $result = $this->oneSignal->sendToUser(
                    $customer->expo_push_token,
                    $title,
                    $message,
                    array_merge($notificationData, [
                        'type' => 'subscription_paid',
                        'notification_id' => $notification->id,
                    ])
                );

                if ($result) {
                    $notification->markAsPushSent();
                }
            }

        } catch (\Exception $e) {
            Log::error('❌ Excepción en SendSubscriptionPaidNotification', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}