<?php
// app/Listeners/SendSpeedAlertNotification.php

namespace App\Listeners;

use App\Events\SpeedAlertTriggered;
use App\Models\Notification;
use App\Services\OneSignalService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class SendSpeedAlertNotification implements ShouldQueue
{
    private OneSignalService $oneSignal;

    public function __construct(OneSignalService $oneSignal)
    {
        $this->oneSignal = $oneSignal;
    }

    public function handle(SpeedAlertTriggered $event): void
    {
        try {
            $device = $event->device;
            $customer = $device->customer;

            if (!$customer) {
                return;
            }

            $vehicle = $device->vehicle;
            $vehicleName = $vehicle ? $vehicle->alias ?? $vehicle->plates : $device->imei;

            $title = '⚠️ Alerta de Velocidad';
            $message = "El dispositivo {$vehicleName} excedió el límite de velocidad. ";
            $message .= "Velocidad actual: {$event->currentSpeed} km/h | Límite: {$event->speedLimit} km/h";

            $notificationData = [
                'device_id' => $device->id,
                'vehicle_id' => $vehicle?->id,
                'current_speed' => $event->currentSpeed,
                'speed_limit' => $event->speedLimit,
                'latitude' => $event->locationData['latitude'] ?? null,
                'longitude' => $event->locationData['longitude'] ?? null,
            ];

            // ✅ GUARDAR EN BASE DE DATOS
            $notification = Notification::create([
                'customer_id' => $customer->id,
                'event_id' => null, // Si tienes relación con eventos, ponlo aquí
                'type' => 'speed_alert',
                'title' => $title,
                'message' => $message,
                'data' => $notificationData,
                'is_read' => false,
                'push_sent' => false,
            ]);

            Log::info('💾 Notificación guardada en BD', [
                'notification_id' => $notification->id,
                'customer_id' => $customer->id,
            ]);

            // ✅ ENVIAR PUSH NOTIFICATION (solo si tiene token)
            if ($customer->expo_push_token) {
                Log::info('📤 Enviando notificación push de velocidad', [
                    'customer_id' => $customer->id,
                    'external_id' => $customer->expo_push_token,
                ]);

                $result = $this->oneSignal->sendAlertNotification(
                    $customer->expo_push_token,
                    $title,
                    $message,
                    'speed',
                    array_merge($notificationData, [
                        'type' => 'speed_alert',
                        'notification_id' => $notification->id,
                    ])
                );

                if ($result) {
                    // Marcar como enviada
                    $notification->markAsPushSent();
                    Log::info('✅ Push notification de velocidad enviada');
                } else {
                    Log::error('❌ Falló envío de push notification de velocidad');
                }
            }

        } catch (\Exception $e) {
            Log::error('❌ Excepción en SendSpeedAlertNotification', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}