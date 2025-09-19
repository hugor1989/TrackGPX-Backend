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

    // 📋 MÉTODOS ADICIONALES ÚTILES:

    public function createCustomer(array $data)
    {
        try {
            $customerData = [
                'name' => $data['name'],
                'email' => $data['email'],
                'last_name' => $data['last_name'] ?? '',
                'phone_number' => $data['phone_number'] ?? '',
                'requires_account' => false
            ];

            return $this->openpay->customers->add($customerData);

        } catch (\OpenpayApiError $e) {
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
}
