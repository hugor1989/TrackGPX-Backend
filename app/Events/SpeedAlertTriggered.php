<?php

namespace App\Events;

use App\Models\Device;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SpeedAlertTriggered
{
    use Dispatchable, SerializesModels;

    public Device $device;
    public float $currentSpeed;
    public float $speedLimit;
    public array $locationData;

    public function __construct(Device $device, float $currentSpeed, float $speedLimit, array $locationData)
    {
        $this->device = $device;
        $this->currentSpeed = $currentSpeed;
        $this->speedLimit = $speedLimit;
        $this->locationData = $locationData;
    }
}