<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OneSignalService
{
    private string $appId;
    private string $restApiKey;
    private string $apiUrl;

    public function __construct()
    {
        $this->appId = config('services.onesignal.app_id');
        $this->restApiKey = config('services.onesignal.rest_api_key');
        $this->apiUrl = config('services.onesignal.api_url');
    }

    /**
     * Enviar notificación a un usuario específico por external_id
     */
    public function sendToUser(string $externalId, string $title, string $message, array $data = [])
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . $this->restApiKey,
                'Content-Type' => 'application/json',
            ])->post($this->apiUrl . '/notifications', [
                'app_id' => $this->appId,
                'include_external_user_ids' => [$externalId],
                'headings' => ['en' => $title],
                'contents' => ['en' => $message],
                'data' => $data,
                // ❌ REMOVIDO: 'android_channel_id' => 'gps-alerts',
                'priority' => 10,
            ]);

            if ($response->successful()) {
                Log::info('OneSignal notification sent successfully', [
                    'external_id' => $externalId,
                    'title' => $title,
                    'response' => $response->json(),
                ]);
                return $response->json();
            }

            Log::error('OneSignal notification failed', [
                'external_id' => $externalId,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('OneSignal exception', [
                'external_id' => $externalId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Enviar notificación a múltiples usuarios
     */
    public function sendToMultipleUsers(array $externalIds, string $title, string $message, array $data = [])
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . $this->restApiKey,
                'Content-Type' => 'application/json',
            ])->post($this->apiUrl . '/notifications', [
                'app_id' => $this->appId,
                'include_external_user_ids' => $externalIds,
                'headings' => ['en' => $title],
                'contents' => ['en' => $message],
                'data' => $data,
                // ❌ REMOVIDO: 'android_channel_id' => 'gps-alerts',
                'priority' => 10,
            ]);

            if ($response->successful()) {
                Log::info('OneSignal batch notification sent', [
                    'recipients' => count($externalIds),
                    'response' => $response->json(),
                ]);
                return $response->json();
            }

            return null;
        } catch (\Exception $e) {
            Log::error('OneSignal batch exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Enviar notificación con prioridad alta
     */
    public function sendAlertNotification(string $externalId, string $title, string $message, string $alertType, array $data = [])
    {
        try {
            Log::info('=== INICIANDO ENVÍO DE NOTIFICACIÓN ===', [
                'external_id' => $externalId,
                'title' => $title,
                'message' => $message,
                'alert_type' => $alertType,
            ]);

            $data['alert_type'] = $alertType;
            
            $payload = [
                'app_id' => $this->appId,
                'include_external_user_ids' => [$externalId],
                'headings' => ['en' => $title],
                'contents' => ['en' => $message],
                'data' => $data,
                // ❌ REMOVIDO: 'android_channel_id' => 'gps-alerts',
                'priority' => 10,
            ];

            // Para alertas críticas
            if (in_array($alertType, ['low_battery', 'removal', 'speed'])) {
                $payload['android_accent_color'] = 'FFFF0000';
                $payload['priority'] = 10;
            }

            Log::info('OneSignal Request Payload', [
                'url' => $this->apiUrl . '/notifications',
                'payload' => $payload,
            ]);

            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . $this->restApiKey,
                'Content-Type' => 'application/json',
            ])->post($this->apiUrl . '/notifications', $payload);

            Log::info('OneSignal Response', [
                'status' => $response->status(),
                'body' => $response->body(),
                'successful' => $response->successful(),
            ]);

            if ($response->successful()) {
                Log::info('✅ OneSignal notification sent successfully', [
                    'external_id' => $externalId,
                    'alert_type' => $alertType,
                    'response' => $response->json(),
                ]);
                return $response->json();
            }

            Log::error('❌ OneSignal notification failed', [
                'external_id' => $externalId,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('❌ OneSignal exception', [
                'external_id' => $externalId,
                'alert_type' => $alertType,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Cancelar notificación programada
     */
    public function cancelNotification(string $notificationId)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . $this->restApiKey,
            ])->delete($this->apiUrl . '/notifications/' . $notificationId . '?app_id=' . $this->appId);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('OneSignal cancel notification exception', ['error' => $e->getMessage()]);
            return false;
        }
    }
}