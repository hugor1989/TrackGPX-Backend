<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class SubscriptionController extends AppBaseController
{
    public function createPending(Request $request)
    {
        $request->validate([
            'plan_id' => 'required|exists:plans,id'
        ]);

        try {
            $user = Auth::user();
            $plan = Plan::findOrFail($request->plan_id);

            DB::beginTransaction();

            // Verificar si ya existe una suscripción pendiente
            $existingPending = Subscription::where('customer_id', $user->id)
                ->where('plan_id', $plan->id)
                ->where('status', 'pending')
                ->first();

            if ($existingPending) {

                return $this->error('Ya tienes una suscripción pendiente para este plan', 404);

               
            }

            // Solo crear en tu BD - NO en OpenPay todavía
            $subscription = Subscription::create([
                'customer_id' => $user->id,
                'plan_id' => $plan->id,
                'device_id' => null,
                'start_date' => null,
                'end_date' => null,
                'status' => 'pending',
                'payment_reference' => null,
                'paid_at' => null
            ]);

            DB::commit();

            return $this->success(null, 'Plan eliminado correctamente');

            return response()->json([
                'success' => true,
                'subscription' => $subscription,
                'message' => 'Suscripción creada pendiente de pago'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error('Error creando suscripción: ' . $e->getMessage(), 500);

            
        }
    }

    /**
     * Obtener suscripciones del usuario actual
     * GET /api/subscriptions
     */
    public function getUserSubscriptions(Request $request)
    {
        try {
            $user = Auth::user();
            
            $subscriptions = Subscription::with(['plan', 'device'])
                ->where('customer_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $subscriptions
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting user subscriptions: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error obteniendo suscripciones'
            ], 500);
        }
    }

    // NUEVO MÉTODO: Procesar pago (aquí sí interactúas con OpenPay)
    public function processPayment(Request $request, $subscriptionId)
    {
        $request->validate([
            'payment_method' => 'required|string', // 'card', 'cash', etc.
            'card_token' => 'required_if:payment_method,card' // Token de tarjeta de OpenPay
        ]);

        try {
            $user = Auth::user();
            $subscription = Subscription::with('plan')
                ->where('id', $subscriptionId)
                ->where('customer_id', $user->id)
                ->where('status', 'pending')
                ->firstOrFail();

            DB::beginTransaction();

            // ✅ AHORA SÍ crear en OpenPay
            $openpayResponse = $this->createOpenPaySubscription($subscription, $request->payment_method, $request->card_token);

            if ($openpayResponse['success']) {
                // Actualizar tu BD con la referencia de OpenPay
                $subscription->update([
                    'status' => 'active',
                    'payment_reference' => $openpayResponse['charge_id'],
                    'openpay_subscription_id' => $openpayResponse['subscription_id'],
                    'start_date' => now(),
                    'end_date' => now()->addMonth(), // Para plan mensual
                    'paid_at' => now()
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Pago procesado exitosamente',
                    'subscription' => $subscription
                ]);
            }

            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $openpayResponse['message']
            ], 400);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error procesando pago: ' . $e->getMessage()
            ], 500);
        }
    }

    private function createOpenPaySubscription($subscription, $paymentMethod, $cardToken = null)
    {
        try {
            $openpay = resolve('Openpay');
            $user = Auth::user();

            // Crear el cargo o suscripción en OpenPay
            $chargeData = [
                'method' => $paymentMethod,
                'amount' => (float) $subscription->plan->price,
                'description' => "Suscripción {$subscription->plan->name}",
                'order_id' => 'sub_' . $subscription->id . '_' . time(),
                'customer' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone_number' => $user->phone
                ]
            ];

            // Si es pago con tarjeta, agregar token
            if ($paymentMethod === 'card' && $cardToken) {
                $chargeData['source_id'] = $cardToken;
            }

            $charge = $openpay->charges->create($chargeData);

            return [
                'success' => $charge->status === 'completed',
                'charge_id' => $charge->id,
                'subscription_id' => $charge->id, // O el ID de suscripción si es recurrente
                'message' => 'Pago procesado en OpenPay'
            ];
        } catch (\Exception $e) {
            Log::error('OpenPay Error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error en procesamiento de pago: ' . $e->getMessage()
            ];
        }
    }

    public function getSubscriptionStatuses()
    {
        // Si necesitas saber los posibles estados del ENUM
        $types = DB::select(DB::raw("SHOW COLUMNS FROM subscriptions WHERE Field = 'status'"))[0]->Type;
        preg_match("/^enum\(\'(.*)\'\)$/", $types, $matches);
        $statuses = explode("','", $matches[1]);

        return response()->json([
            'success' => true,
            'statuses' => $statuses
        ]);
    }
}
