<?php

namespace App\Events;

use App\Models\Device;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeviceRemovalAlert
{
    use Dispatchable, SerializesModels;

    public Device $device;
    public array $locationData;

    public function __construct(Device $device, array $locationData)
    {
        $this->device = $device;
        $this->locationData = $locationData;
    }
}