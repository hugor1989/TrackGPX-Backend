<?php
// app/Listeners/SendSpeedAlertNotification.php

namespace App\Listeners;

use App\Events\SpeedAlertTriggered;
use App\Models\Notification;
use App\Services\OneSignalService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class SendSpeedAlertNotification implements ShouldQueue
{
    private OneSignalService $oneSignal;

    public function __construct(OneSignalService $oneSignal)
    {
        $this->oneSignal = $oneSignal;
    }

    public function handle(SpeedAlertTriggered $event): void
    {
        try {
            $device = $event->device;
            
            // 1. OBTENER LA LISTA DE DESTINATARIOS DEL EVENTO
            $recipients = $event->recipients;

            if ($recipients->isEmpty()) {
                Log::warning('⚠️ Alerta de velocidad procesada pero sin destinatarios (Lista vacía).');
                return;
            }

            // 2. PREPARAR DATOS COMUNES (Para no repetir lógica en el bucle)
            $vehicle = $device->vehicle;
            $vehicleName = $vehicle ? $vehicle->alias ?? $vehicle->plates : $device->imei;

            $title = '⚠️ Alerta de Velocidad';
            $message = "El dispositivo {$vehicleName} excedió el límite de velocidad. ";
            $message .= "Velocidad actual: {$event->currentSpeed} km/h | Límite: {$event->speedLimit} km/h";

            $notificationData = [
                'device_id' => $device->id,
                'vehicle_id' => $vehicle?->id,
                'current_speed' => $event->currentSpeed,
                'speed_limit' => $event->speedLimit,
                'latitude' => $event->locationData['latitude'] ?? null,
                'longitude' => $event->locationData['longitude'] ?? null,
                'type' => 'speed_alert', // Agregado aquí para consistencia
            ];

            Log::info("🚀 Iniciando envío de alertas a " . $recipients->count() . " usuarios.");

            // 3. BUCLE PARA CADA USUARIO (ADMIN + MEMBERS)
            foreach ($recipients as $user) {
                
                // A. GUARDAR EN BASE DE DATOS (Registro individual por usuario)
                $notification = Notification::create([
                    'customer_id' => $user->id, // <--- ID del usuario actual del bucle
                    'event_id' => null,
                    'type' => 'speed_alert',
                    'title' => $title,
                    'message' => $message,
                    'data' => $notificationData,
                    'is_read' => false,
                    'push_sent' => false,
                ]);

                // B. ENVIAR PUSH NOTIFICATION
                if (!empty($user->expo_push_token)) {
                    
                    // Enviamos usando el token específico de ESTE usuario
                    $result = $this->oneSignal->sendAlertNotification(
                        $user->expo_push_token,
                        $title,
                        $message,
                        'speed',
                        array_merge($notificationData, [
                            'notification_id' => $notification->id,
                            'recipient_id' => $user->id // Útil para depuración
                        ])
                    );

                    if ($result) {
                        $notification->markAsPushSent();
                        Log::info("✅ Push enviada a User ID: {$user->id}");
                    } else {
                        Log::error("❌ Falló push a User ID: {$user->id}");
                    }
                } else {
                    Log::info("ℹ️ User ID: {$user->id} no tiene token push configurado.");
                }
            }

        } catch (\Exception $e) {
            Log::error('❌ Excepción en SendSpeedAlertNotification', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}