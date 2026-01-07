<?php
namespace App\Listeners;

use App\Events\GeofenceAlertTriggered;
use App\Models\Notification;
use App\Services\OneSignalService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class SendGeofenceNotification implements ShouldQueue
{
    private OneSignalService $oneSignal;

    public function __construct(OneSignalService $oneSignal)
    {
        $this->oneSignal = $oneSignal;
    }

    public function handle(GeofenceAlertTriggered $event): void
    {
        try {
            Log::info('🎧 Listener: SendGeofenceNotification ejecutado', [
                'device_id' => $event->device->id,
                'geofence_id' => $event->geofence->id,
                'event_type' => $event->eventType,
            ]);

            $device = $event->device;
            $geofence = $event->geofence;
            $customer = $device->customer;

            // Verificar que exista el cliente
            if (!$customer) {
                Log::warning('⚠️ Dispositivo sin cliente asignado', [
                    'device_id' => $device->id,
                ]);
                return;
            }

            $vehicle = $device->vehicle;
            $vehicleName = $vehicle ? $vehicle->alias ?? $vehicle->plates : $device->imei;

            // Preparar mensaje según el tipo de evento
            $eventText = $event->eventType === 'enter' ? 'entró a' : 'salió de';
            $emoji = $event->eventType === 'enter' ? '🟢' : '🔴';

            $title = "{$emoji} Alerta de Geocerca";
            $message = "{$vehicleName} {$eventText} la zona \"{$geofence->name}\"";

            // Datos adicionales para la notificación
            $notificationData = [
                'device_id' => $device->id,
                'vehicle_id' => $vehicle?->id,
                'geofence_id' => $geofence->id,
                'geofence_name' => $geofence->name,
                'event_type' => $event->eventType,
                'latitude' => $event->locationData['latitude'] ?? null,
                'longitude' => $event->locationData['longitude'] ?? null,
                'timestamp' => $event->locationData['timestamp'] ?? null,
            ];

            // ✅ GUARDAR EN BASE DE DATOS
            $notification = Notification::create([
                'customer_id' => $customer->id,
                'event_id' => null,
                'type' => 'geofence_alert',
                'title' => $title,
                'message' => $message,
                'data' => $notificationData,
                'is_read' => false,
                'push_sent' => false,
            ]);

            Log::info('✅ Notificación guardada en BD', [
                'notification_id' => $notification->id,
                'customer_id' => $customer->id,
            ]);

            // ✅ ENVIAR PUSH NOTIFICATION
            if ($customer->expo_push_token) {
                $result = $this->oneSignal->sendAlertNotification(
                    $customer->expo_push_token,
                    $title,
                    $message,
                    'geofence_alert',
                    array_merge($notificationData, [
                        'type' => 'geofence_alert',
                        'notification_id' => $notification->id,
                    ])
                );

                if ($result) {
                    $notification->markAsPushSent();
                    Log::info('✅ Push notification enviada exitosamente', [
                        'notification_id' => $notification->id,
                    ]);
                } else {
                    Log::warning('⚠️ No se pudo enviar push notification', [
                        'notification_id' => $notification->id,
                    ]);
                }
            } else {
                Log::info('ℹ️ Cliente sin expo_push_token, solo guardado en BD', [
                    'customer_id' => $customer->id,
                ]);
            }

        } catch (\Exception $e) {
            Log::error('❌ Excepción en SendGeofenceNotification', [
                'device_id' => $event->device->id ?? 'N/A',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
