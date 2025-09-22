<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Openpay\Data\Openpay;
use Exception;


class OpenPayService
{
    protected $openpay;

    public function __construct()
    {
        // ⚠️ Según el SDK, getInstance espera: id, apiKey, country, publicIp
        $this->openpay = Openpay::getInstance(
            config('services.openpay.merchant_id'),
            config('services.openpay.private_key'),
            'MX', // country — Openpay necesita esto para saber el endpoint
            request()->ip() // publicIp — si no lo necesitas, puedes pasar null
        );
    }

    //crear plan en open pay
    public function createPlan(array $data)
    {
        try {
            $planData = [
                'name' => $data['name'],
                'amount' => floatval($data['amount']),
                'currency' => $data['currency'] ?? 'MXN',
                'repeat_every' => $data['interval_count'],
                'repeat_unit' => $data['interval'],
                'status_after_retry' => $data['status_payd'],
                'trial_days' => $data['trial_days'] ?? 0,
                'status' => $data['status'] ?? 'active'
            ];

            if (!empty($data['description'])) {
                $planData['description'] = $data['description'];
            }

            Log::info('Creating OpenPay plan:', $planData);

            $plan = $this->openpay->plans->add($planData);

            Log::info('OpenPay plan created successfully:', [
                'id' => $plan->id,
                'name' => $plan->name
            ]);

            return $plan;
        } catch (OpenpayApiError $e) {
            Log::error('OpenPay API Error:', [
                'error_code' => $e->getErrorCode(),
                'category' => $e->getCategory(),
                'description' => $e->getDescription(),
                'http_code' => $e->getHttpCode()
            ]);

            throw new Exception('OpenPay Error: ' . $e->getDescription());
        } catch (Exception $e) {
            Log::error('General error creating plan: ' . $e->getMessage());
            throw new Exception('Error creating plan: ' . $e->getMessage());
        }
    }

    public function paySubscription($customerId, $subscription, $cardId, $deviceSessionId)
    {
        try {
            $openpay = $this->openpay;
            $customer = $openpay->customers->get($customerId);

            $chargeData = [
                'method' => 'card',
                'source_id' => $cardId,
                'amount' => (float) $subscription->plan->price,
                'description' => 'Pago de suscripción: ' . $subscription->plan->name,
                'order_id' => 'SUB-' . $subscription->id . '-' . time(),
                'device_session_id' => $deviceSessionId, // ← Agregar device_session_id
            ];

            $charge = $customer->charges->create($chargeData);

            return [
                'success' => true,
                'charge_id' => $charge->id
            ];
        } catch (OpenpayApiError $e) {
            Log::error('OpenPay API Error processing payment: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getDescription()
            ];
        } catch (\Exception $e) {
            Log::error('Error processing payment: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }


    // 📋 MÉTODOS ADICIONALES ÚTILES:

    public function createCustomer(array $data)
    {
        try {
            $customerData = [
                'name' => $data['name'],
                'email' => $data['email'],
                'last_name' => $data['last_name'] ?? '',
                'phone_number' => $data['phone_number'] ?? '',
                'requires_account' => false,
            ];

            return $this->openpay->customers->add($customerData);
        } catch (OpenpayApiError $e) {
            throw new Exception('OpenPay Customer Error: ' . $e->getDescription());
        }
    }

    public function createSubscription($customerId, $planId, $paymentSourceId)
    {
        try {
            $subscriptionData = [
                'plan_id' => $planId,
                'card' => $paymentSourceId
            ];

            $customer = $this->openpay->customers->get($customerId);
            return $customer->subscriptions->add($subscriptionData);
        } catch (OpenpayApiError $e) {
            throw new Exception('OpenPay Subscription Error: ' . $e->getDescription());
        }
    }

    public function getPlan($planId)
    {
        try {
            return $this->openpay->plans->get($planId);
        } catch (OpenpayApiError $e) {
            throw new Exception('OpenPay Get Plan Error: ' . $e->getDescription());
        }
    }

    public function deletePlan($planId)
    {
        try {
            $plan = $this->openpay->plans->get($planId);
            $plan->delete();
            return true;
        } catch (OpenpayApiError $e) {
            throw new Exception('OpenPay Delete Plan Error: ' . $e->getDescription());
        }
    }
    public function verifyWebhookSignature($payload, $signature)
    {
        // Para webhooks de verificación, no hay firma
        if (empty($signature)) {
            Log::info('Webhook verification received (no signature required)');
            return true; // Los webhooks de verificación no requieren firma
        }

        $webhookSecret = config('services.openpay.webhook_secret');

        if (empty($webhookSecret)) {
            Log::error('OpenPay webhook secret is not configured');
            return false;
        }

        $computedSignature = base64_encode(
            hash_hmac('sha256', $payload, $webhookSecret, true)
        );

        return hash_equals($computedSignature, $signature);
    }

    #region Gestion de Tarjetas
    /**
     * Agregar una tarjeta a un cliente
     */
    public function addCardToCustomer($customerId, $tokenId, $deviceSessionId)
    {
        try {
            $openpay = $this->openpay;
            $customer = $openpay->customers->get($customerId);

            $cardData = [
                'token_id' => $tokenId,
                'device_session_id' => $deviceSessionId
            ];

            $card = $customer->cards->add($cardData);

            return [
                'success' => true,
                'card' => $card
            ];
        } catch (OpenpayApiError $e) {
            Log::error('OpenPay API Error adding card: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getDescription(),
                'error_code' => $e->getErrorCode(),
                'category' => $e->getCategory()
            ];
        } catch (\Exception $e) {
            Log::error('Error adding card: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Obtener todas las tarjetas de un cliente
     */
    public function getCustomerCards($customerId)
    {
        try {
            $openpay = $this->openpay;
            $customer = $openpay->customers->get($customerId);

            // Pasar un array vacío como parámetro a getList()
            $cards = $customer->cards->getList([]);
            return [
                'success' => true,
                'cards' => $cards
            ];
        } catch (OpenpayApiError $e) {
            Log::error('OpenPay API Error getting cards: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getDescription()
            ];
        } catch (\Exception $e) {
            Log::error('Error getting cards: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Eliminar una tarjeta de un cliente
     */
    public function deleteCustomerCard($customerId, $cardId)
    {
        try {
            $openpay = $this->openpay;
            $customer = $openpay->customers->get($customerId);

            $card = $customer->cards->get($cardId);
            $card->delete();

            return [
                'success' => true
            ];
        } catch (OpenpayApiError $e) {
            Log::error('OpenPay API Error deleting card: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getDescription()
            ];
        } catch (Exception $e) {
            Log::error('Error deleting card: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Obtener una tarjeta específica de un cliente
     */
    public function getCustomerCard($customerId, $cardId)
    {
        try {
            $openpay = $this->openpay;
            $customer = $openpay->customers->get($customerId);

            $card = $customer->cards->get($cardId);

            return [
                'success' => true,
                'card' => $card
            ];
        } catch (OpenpayApiError $e) {
            Log::error('OpenPay API Error getting card: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getDescription()
            ];
        } catch (\Exception $e) {
            Log::error('Error getting card: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    #endregion
}
