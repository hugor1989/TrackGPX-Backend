<?php

namespace App\Events;

use App\Models\Device;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection; // <--- No olvides importar esto

class LowBatteryAlert
{
    use Dispatchable, SerializesModels;

    public Device $device;
    public int $batteryLevel;
    public array $locationData;
    public Collection $recipients; // <--- Nueva propiedad

    public function __construct(Device $device, int $batteryLevel, array $locationData, Collection $recipients)
    {
        $this->device = $device;
        $this->batteryLevel = $batteryLevel;
        $this->locationData = $locationData;
        $this->recipients = $recipients; // <--- Asignación
    }
}