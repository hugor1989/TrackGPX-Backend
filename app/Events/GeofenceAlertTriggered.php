<?php
namespace App\Events;

use App\Models\Device;
use App\Models\Geofence;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GeofenceAlertTriggered
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $device;
    public $geofence;
    public $eventType; // 'enter' o 'exit'
    public $locationData;

    public function __construct(Device $device, Geofence $geofence, string $eventType, array $locationData)
    {
        $this->device = $device;
        $this->geofence = $geofence;
        $this->eventType = $eventType;
        $this->locationData = $locationData;
    }
}
