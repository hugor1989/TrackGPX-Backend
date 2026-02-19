<?php

namespace App\Listeners;

use App\Events\PanicAlertTriggered;
use App\Models\Notification;
use App\Models\DeviceShare; // 👈 Importar modelo
use App\Services\APICrmWhatSapp;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str; // 👈 Importar Str
use Carbon\Carbon; // 👈 Importar Carbon

class SendPanicAlertNotification implements ShouldQueue
{
    protected $whatsappService;

    public function __construct(APICrmWhatSapp $whatsappService)
    {
        $this->whatsappService = $whatsappService;
    }

    public function handle(PanicAlertTriggered $event): void
    {
        try {
            $device = $event->device;
            $contacts = $event->contacts;
            $location = $event->locationData;

            // 1. 🔥 GENERAR EL LINK DE RASTREO (15 MINUTOS)
            // Replicamos la lógica de tu controlador aquí mismo
            $token = Str::random(40);
            
            DeviceShare::create([
                'device_id' => $device->id,
                'token' => $token,
                'expires_at' => Carbon::now()->addMinutes(15), // 15 min fijo como pediste
                'is_active' => true
            ]);

            // URL del Frontend Web
            $liveLink = "https://live-trackers.track-gpx.com/" . $token;

            // 2. PREPARAR EL MENSAJE CON EL LINK INCLUIDO
            $vehicleName = $device->vehicle ? ($device->vehicle->alias ?? $device->vehicle->plates) : $device->imei;
            $staticMap = "http://maps.google.com/?q={$location['lat']},{$location['lon']}";
            
            // Mensaje Profesional para WhatsApp
            $whatsappMessage = "🚨 *ALERTA SOS* 🚨\n";
            $whatsappMessage .= "El vehículo *{$vehicleName}* ha reportado una emergencia.\n\n";
            $whatsappMessage .= "📡 *SIGUE LA UBICACIÓN EN VIVO (15 min):*\n{$liveLink}\n\n";
            $whatsappMessage .= "📍 Ubicación del reporte: {$staticMap}";

            // 3. ENVIAR WHATSAPP A CADA CONTACTO
            if ($contacts->isEmpty()) {
                Log::warning("⚠️ Alerta de pánico sin contactos para: {$device->imei}");
            } else {
                Log::info("🚀 Enviando Pánico WhatsApp con Link a " . $contacts->count() . " contactos.");
                
                foreach ($contacts as $contact) {
                    if ($contact->notify_whatsapp) {
                        try {
                            $this->whatsappService->sendMessage($contact->phone, $whatsappMessage);
                            Log::info("✅ WhatsApp enviado a: {$contact->name}");
                        } catch (\Exception $e) {
                            Log::error("❌ Error WhatsApp a {$contact->name}: " . $e->getMessage());
                        }
                    }
                }
            }

            // 4. GUARDAR EN HISTORIAL (NOTIFICATIONS)
            $owner = $device->customer; 
            if ($owner) {
                Notification::create([
                    'customer_id' => $owner->id,
                    'type' => 'panic_alert',
                    'title' => '🚨 BOTÓN DE PÁNICO ACTIVADO',
                    'message' => "SOS en {$vehicleName}. Link de rastreo generado.",
                    'data' => [
                        'device_id' => $device->id,
                        'latitude' => $location['lat'],
                        'longitude' => $location['lon'],
                        'live_url' => $liveLink, // Guardamos el link en el historial también
                        'contacts_notified_count' => $contacts->count()
                    ],
                    'is_read' => false,
                    'push_sent' => false,
                ]);
            }

        } catch (\Exception $e) {
            Log::error('❌ Error en SendPanicAlertNotification: ' . $e->getMessage());
        }
    }
}