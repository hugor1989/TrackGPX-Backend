<?php
// app/Listeners/SendLowBatteryNotification.php

namespace App\Listeners;

use App\Events\LowBatteryAlert;
use App\Models\Notification;
use App\Services\OneSignalService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;


class SendLowBatteryNotification implements ShouldQueue
{
    private OneSignalService $oneSignal;

    public function __construct(OneSignalService $oneSignal)
    {
        $this->oneSignal = $oneSignal;
    }

    public function handle(LowBatteryAlert $event): void
    {
        try {
            $device = $event->device;
            $customer = $device->customer;

            if (!$customer) {
                return;
            }

            $vehicle = $device->vehicle;
            $vehicleName = $vehicle ? $vehicle->alias ?? $vehicle->plates : $device->imei;

            $title = '🔋 Batería Baja';
            $message = "El dispositivo {$vehicleName} tiene batería baja: {$event->batteryLevel}%";

            $notificationData = [
                'device_id' => $device->id,
                'vehicle_id' => $vehicle?->id,
                'battery_level' => $event->batteryLevel,
                'latitude' => $event->locationData['latitude'] ?? null,
                'longitude' => $event->locationData['longitude'] ?? null,
            ];

            // ✅ GUARDAR EN BASE DE DATOS
            $notification = Notification::create([
                'customer_id' => $customer->id,
                'event_id' => null,
                'type' => 'low_battery',
                'title' => $title,
                'message' => $message,
                'data' => $notificationData,
                'is_read' => false,
                'push_sent' => false,
            ]);

            // ✅ ENVIAR PUSH NOTIFICATION
            if ($customer->expo_push_token) {
                $result = $this->oneSignal->sendAlertNotification(
                    $customer->expo_push_token,
                    $title,
                    $message,
                    'low_battery',
                    array_merge($notificationData, [
                        'type' => 'low_battery',
                        'notification_id' => $notification->id,
                    ])
                );

                if ($result) {
                    $notification->markAsPushSent();
                }
            }

        } catch (\Exception $e) {
            Log::error('❌ Excepción en SendLowBatteryNotification', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}