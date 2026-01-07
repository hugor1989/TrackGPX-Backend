<?php

namespace App\Providers;

use App\Events\DeviceRemovalAlert;
use App\Events\LowBatteryAlert;
use App\Events\SpeedAlertTriggered;
use App\Events\SubscriptionPaid;
use App\Events\GeofenceAlertTriggered;
use App\Listeners\SendDeviceRemovalNotification;
use App\Listeners\SendLowBatteryNotification;
use App\Listeners\SendSpeedAlertNotification;
use App\Listeners\SendSubscriptionPaidNotification;
use App\Listeners\SendGeofenceNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        SubscriptionPaid::class => [
            SendSubscriptionPaidNotification::class,
        ],
        SpeedAlertTriggered::class => [
            SendSpeedAlertNotification::class,
        ],
        LowBatteryAlert::class => [
            SendLowBatteryNotification::class,
        ],
        DeviceRemovalAlert::class => [
            SendDeviceRemovalNotification::class,
        ],
        GeofenceAlertTriggered::class => [
            SendGeofenceNotification::class,
        ],
    ];

    public function boot(): void
    {
        //
    }
}