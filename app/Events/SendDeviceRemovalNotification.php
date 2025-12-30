<?php

namespace App\Listeners;

use App\Events\DeviceRemovalAlert;
use App\Services\OneSignalService;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendDeviceRemovalNotification implements ShouldQueue
{
    private OneSignalService $oneSignal;

    public function __construct(OneSignalService $oneSignal)
    {
        $this->oneSignal = $oneSignal;
    }

    public function handle(DeviceRemovalAlert $event): void
    {
        $device = $event->device;
        $customer = $device->customer;

        if (!$customer || !$customer->expo_push_token) {
            return;
        }

        $vehicle = $device->vehicle;
        $vehicleName = $vehicle ? $vehicle->alias ?? $vehicle->plates : $device->imei;

        $title = '🚨 Alerta de Remoción';
        $message = "¡ATENCIÓN! El dispositivo {$vehicleName} ha sido removido o desconectado.";

        $this->oneSignal->sendAlertNotification(
            $customer->expo_push_token,
            $title,
            $message,
            'removal',
            [
                'type' => 'removal_alert',
                'device_id' => $device->id,
                'vehicle_id' => $vehicle?->id,
                'latitude' => $event->locationData['latitude'] ?? null,
                'longitude' => $event->locationData['longitude'] ?? null,
            ]
        );
    }
}