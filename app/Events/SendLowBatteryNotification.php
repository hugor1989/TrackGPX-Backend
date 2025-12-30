<?php

namespace App\Listeners;

use App\Events\LowBatteryAlert;
use App\Services\OneSignalService;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendLowBatteryNotification implements ShouldQueue
{
    private OneSignalService $oneSignal;

    public function __construct(OneSignalService $oneSignal)
    {
        $this->oneSignal = $oneSignal;
    }

    public function handle(LowBatteryAlert $event): void
    {
        $device = $event->device;
        $customer = $device->customer;

        if (!$customer || !$customer->expo_push_token) {
            return;
        }

        $vehicle = $device->vehicle;
        $vehicleName = $vehicle ? $vehicle->alias ?? $vehicle->plates : $device->imei;

        $title = '🔋 Batería Baja';
        $message = "El dispositivo {$vehicleName} tiene batería baja: {$event->batteryLevel}%";

        $this->oneSignal->sendAlertNotification(
            $customer->expo_push_token,
            $title,
            $message,
            'low_battery',
            [
                'type' => 'low_battery',
                'device_id' => $device->id,
                'vehicle_id' => $vehicle?->id,
                'battery_level' => $event->batteryLevel,
                'latitude' => $event->locationData['latitude'] ?? null,
                'longitude' => $event->locationData['longitude'] ?? null,
            ]
        );
    }
}