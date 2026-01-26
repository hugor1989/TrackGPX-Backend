<?php

namespace App\Events;

use App\Models\Device;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class PanicAlertTriggered
{
    use Dispatchable, SerializesModels;

    public Device $device;
    public array $locationData; // ['lat', 'lon', 'timestamp']
    public Collection $contacts; // Lista de contactos de emergencia

    /**
     * Create a new event instance.
     */
    public function __construct(Device $device, array $locationData, Collection $contacts)
    {
        $this->device = $device;
        $this->locationData = $locationData;
        $this->contacts = $contacts;
    }
}