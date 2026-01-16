<?php
// app/Listeners/SendLowBatteryNotification.php

namespace App\Listeners;

use App\Events\LowBatteryAlert;
use App\Models\Notification;
use App\Services\OneSignalService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class SendLowBatteryNotification implements ShouldQueue
{
    private OneSignalService $oneSignal;

    public function __construct(OneSignalService $oneSignal)
    {
        $this->oneSignal = $oneSignal;
    }

    public function handle(LowBatteryAlert $event): void
    {
        try {
            $device = $event->device;
            
            // 1. OBTENER LA LISTA DE DESTINATARIOS (Admin + Members)
            $recipients = $event->recipients;

            // Validación de seguridad por si la lista llega vacía
            if ($recipients->isEmpty()) {
                Log::warning('⚠️ Alerta de batería baja procesada pero sin destinatarios.');
                return;
            }

            // 2. PREPARAR DATOS COMUNES (Para no repetirlos en el bucle)
            $vehicle = $device->vehicle;
            $vehicleName = $vehicle ? $vehicle->alias ?? $vehicle->plates : $device->imei;

            $title = '🔋 Batería Baja';
            $message = "El dispositivo {$vehicleName} tiene batería baja: {$event->batteryLevel}%";

            $notificationData = [
                'device_id' => $device->id,
                'vehicle_id' => $vehicle?->id,
                'battery_level' => $event->batteryLevel,
                'latitude' => $event->locationData['latitude'] ?? null,
                'longitude' => $event->locationData['longitude'] ?? null,
                'type' => 'low_battery', // Es buena práctica incluir el tipo en la data también
            ];

            Log::info("🔋 Iniciando envío de alerta de batería a " . $recipients->count() . " usuarios.");

            // 3. BUCLE: PROCESAR CADA USUARIO INDIVIDUALMENTE
            foreach ($recipients as $user) {
                
                // A. GUARDAR EN BASE DE DATOS (Historial individual)
                // Esto es vital para que cada usuario vea la notificación en su propia lista en la App
                $notification = Notification::create([
                    'customer_id' => $user->id, // <--- ID del usuario actual del bucle
                    'event_id' => null,
                    'type' => 'low_battery',
                    'title' => $title,
                    'message' => $message,
                    'data' => $notificationData,
                    'is_read' => false,
                    'push_sent' => false,
                ]);

                // B. ENVIAR PUSH NOTIFICATION (Si tiene token)
                if (!empty($user->expo_push_token)) {
                    
                    $result = $this->oneSignal->sendAlertNotification(
                        $user->expo_push_token, // <--- Token del usuario actual
                        $title,
                        $message,
                        'low_battery',
                        array_merge($notificationData, [
                            'type' => 'low_battery',
                            'notification_id' => $notification->id, // ID único de su notificación en BD
                        ])
                    );

                    if ($result) {
                        $notification->markAsPushSent();
                        Log::info("✅ Push Batería enviada a User ID: {$user->id}");
                    } else {
                        Log::error("❌ Falló push Batería a User ID: {$user->id}");
                    }
                } else {
                    Log::info("ℹ️ User ID: {$user->id} no tiene token configurado para alerta de batería.");
                }
            }

        } catch (\Exception $e) {
            Log::error('❌ Excepción en SendLowBatteryNotification', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(), // Agregamos trace para facilitar depuración
            ]);
        }
    }
}