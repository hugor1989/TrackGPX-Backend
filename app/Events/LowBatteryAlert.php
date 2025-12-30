<?php

namespace App\Events;

use App\Models\Device;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LowBatteryAlert
{
    use Dispatchable, SerializesModels;

    public Device $device;
    public int $batteryLevel;
    public array $locationData;

    public function __construct(Device $device, int $batteryLevel, array $locationData)
    {
        $this->device = $device;
        $this->batteryLevel = $batteryLevel;
        $this->locationData = $locationData;
    }
}