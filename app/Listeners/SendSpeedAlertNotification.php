<?php

namespace App\Listeners;

use App\Events\SpeedAlertTriggered;
use App\Services\OneSignalService;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendSpeedAlertNotification implements ShouldQueue
{
    private OneSignalService $oneSignal;

    public function __construct(OneSignalService $oneSignal)
    {
        $this->oneSignal = $oneSignal;
    }

    public function handle(SpeedAlertTriggered $event): void
    {
        $device = $event->device;
        $customer = $device->customer;

        if (!$customer || !$customer->expo_push_token) {
            return;
        }

        $vehicle = $device->vehicle;
        $vehicleName = $vehicle ? $vehicle->alias ?? $vehicle->plates : $device->imei;

        $title = '⚠️ Alerta de Velocidad';
        $message = "El dispositivo {$vehicleName} excedió el límite de velocidad. ";
        $message .= "Velocidad actual: {$event->currentSpeed} km/h | Límite: {$event->speedLimit} km/h";

        $this->oneSignal->sendAlertNotification(
            $customer->expo_push_token,
            $title,
            $message,
            'speed',
            [
                'type' => 'speed_alert',
                'device_id' => $device->id,
                'vehicle_id' => $vehicle?->id,
                'current_speed' => $event->currentSpeed,
                'speed_limit' => $event->speedLimit,
                'latitude' => $event->locationData['latitude'] ?? null,
                'longitude' => $event->locationData['longitude'] ?? null,
            ]
        );
    }
}