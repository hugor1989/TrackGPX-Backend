<?php

namespace App\Events;

use App\Models\Device;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection; // <--- IMPORTANTE: Importar Collection

class SpeedAlertTriggered
{
    use Dispatchable, SerializesModels;

    public Device $device;
    public float $currentSpeed;
    public float $speedLimit;
    public array $locationData;
    public Collection $recipients; // <--- NUEVA PROPIEDAD

    /**
     * Create a new event instance.
     *
     * @param Device $device
     * @param float $currentSpeed
     * @param float $speedLimit
     * @param array $locationData
     * @param Collection $recipients  <--- NUEVO PARÁMETRO
     */
    public function __construct(Device $device, float $currentSpeed, float $speedLimit, array $locationData, Collection $recipients)
    {
        $this->device = $device;
        $this->currentSpeed = $currentSpeed;
        $this->speedLimit = $speedLimit;
        $this->locationData = $locationData;
        $this->recipients = $recipients; // <--- ASIGNACIÓN
    }
}