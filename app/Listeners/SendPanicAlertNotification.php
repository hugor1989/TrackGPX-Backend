<?php

namespace App\Listeners;

use App\Events\PanicAlertTriggered;
use App\Models\Notification;
use App\Services\APICrmWhatSapp; // Tu servicio de WhatsApp
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class SendPanicAlertNotification implements ShouldQueue
{
    protected $whatsappService;

    // Inyectamos tu servicio de WhatsApp igual que en tu controlador
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

            // 1. PREPARAR EL MENSAJE
            $vehicleName = $device->vehicle ? ($device->vehicle->alias ?? $device->vehicle->plates) : $device->imei;
            $googleMapsLink = "http://maps.google.com/?q={$location['lat']},{$location['lon']}";
            
            // Mensaje para WhatsApp
            $whatsappMessage = "🚨 *ALERTA SOS* 🚨\n";
            $whatsappMessage .= "El vehículo *{$vehicleName}* ha reportado una emergencia.\n";
            $whatsappMessage .= "📍 Ver ubicación: {$googleMapsLink}";

            // 2. ENVIAR WHATSAPP A CADA CONTACTO DE EMERGENCIA
            if ($contacts->isEmpty()) {
                Log::warning("⚠️ Alerta de pánico sin contactos de emergencia configurados para Device: {$device->imei}");
            } else {
                Log::info("🚀 Enviando Pánico WhatsApp a " . $contacts->count() . " contactos.");
                
                foreach ($contacts as $contact) {
                    if ($contact->notify_whatsapp) { // Solo si tiene el check activado
                        try {
                            $this->whatsappService->sendMessage($contact->phone, $whatsappMessage);
                            Log::info("✅ WhatsApp enviado a: {$contact->name} ({$contact->phone})");
                        } catch (\Exception $e) {
                            Log::error("❌ Error WhatsApp a {$contact->name}: " . $e->getMessage());
                        }
                    }
                }
            }

            // 3. GUARDAR EN TABLA NOTIFICATIONS (Para el historial de la App del Dueño)
            // Asumimos que el dueño es $device->customer o similar
            $owner = $device->customer; 
            
            if ($owner) {
                Notification::create([
                    'customer_id' => $owner->id,
                    'type' => 'panic_alert', // Tipo nuevo para distinguir
                    'title' => '🚨 BOTÓN DE PÁNICO ACTIVADO',
                    'message' => "Se activó alerta SOS en {$vehicleName}. Se notificó a tus contactos.",
                    'data' => [
                        'device_id' => $device->id,
                        'latitude' => $location['lat'],
                        'longitude' => $location['lon'],
                        'contacts_notified_count' => $contacts->count()
                    ],
                    'is_read' => false,
                    'push_sent' => false, // O true si decides mandar push también
                ]);
                Log::info("📝 Notificación guardada en BD para Owner ID: {$owner->id}");
            }

        } catch (\Exception $e) {
            Log::error('❌ Excepción en SendPanicAlertNotification', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}