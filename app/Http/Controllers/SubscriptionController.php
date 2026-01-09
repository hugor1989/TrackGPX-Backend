<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\Plan;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\OpenPayService;
use App\Events\SubscriptionPaid;

class SubscriptionController extends AppBaseController
{
    protected $openPayService;

    public function __construct(OpenPayService $openPayService)
    {
        $this->openPayService = $openPayService;
    }

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

    /**
     * Obtener suscripciones para mostrar al admin  
     * GET /api/subscriptions
     */
    public function getAdminSubscriptions(Request $request)
    {
        try {
            $status = $request->query('status');

            $query = Subscription::with([
                'plan',
                'customer:id,name,email,phone,address',
                'device:id,device_id,name,imei'
            ]);

            // Usar scopes para filtros
            if ($status && $status !== 'all') {
                switch ($status) {
                    case Subscription::STATUS_PENDING:
                        $query->pending();
                        break;
                    case Subscription::STATUS_ACTIVE:
                        $query->active();
                        break;
                    case Subscription::STATUS_CANCELLED:
                        $query->where('status', Subscription::STATUS_CANCELLED);
                        break;
                    case Subscription::STATUS_EXPIRED:
                        $query->where('status', Subscription::STATUS_EXPIRED);
                        break;
                }
            }

            $subscriptions = $query->orderBy('created_at', 'desc')->get();

            $formattedSubscriptions = $subscriptions->map(function ($subscription) {
                return [
                    'id' => $subscription->id,
                    'customer_id' => $subscription->customer_id,
                    'device_id' => $subscription->device_id,
                    'plan_id' => $subscription->plan_id,
                    'start_date' => $subscription->start_date?->toDateString(),
                    'end_date' => $subscription->end_date?->toDateString(),
                    'status' => $subscription->status,
                    'payment_reference' => $subscription->payment_reference,
                    'paid_at' => $subscription->paid_at?->toDateTimeString(),
                    'openpay_subscription_id' => $subscription->openpay_subscription_id,
                    'created_at' => $subscription->created_at?->toDateTimeString(),
                    'updated_at' => $subscription->updated_at?->toDateTimeString(),

                    'customer' => $subscription->customer ? [
                        'id' => $subscription->customer->id,
                        'name' => $subscription->customer->name,
                        'email' => $subscription->customer->email,
                        'phone' => $subscription->customer->phone,
                        'address' => $subscription->customer->address
                    ] : null,

                    'plan' => $subscription->plan ? [
                        'id' => $subscription->plan->id,
                        'name' => $subscription->plan->name,
                        'description' => $subscription->plan->description,
                        'price' => $subscription->plan->price,
                        'interval' => $subscription->plan->interval,
                        'interval_count' => $subscription->plan->interval_count,
                        'features' => $subscription->plan->features
                    ] : null,

                    'device' => $subscription->device ? [
                        'id' => $subscription->device->id,
                        'device_id' => $subscription->device->device_id,
                        'name' => $subscription->device->name,
                        'imei' => $subscription->device->imei
                    ] : null
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedSubscriptions
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting admin subscriptions: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error obteniendo suscripciones'
            ], 500);
        }
    }

    public function getSubscriptionStats()
    {
        try {
            $stats = [
                'total' => Subscription::count(),
                'active' => Subscription::active()->count(),
                'pending' => Subscription::pending()->count(),
                'cancelled' => Subscription::where('status', Subscription::STATUS_CANCELLED)->count(),
                'expired' => Subscription::where('status', Subscription::STATUS_EXPIRED)->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting subscription stats: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error obteniendo estadísticas'
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
    // app/Http/Controllers/SubscriptionController.php
    /*  public function pay(Request $request)
    {
        $request->validate([
            'subscription_id' => 'required|integer|exists:subscriptions,id',
            'card_id' => 'required|string',
            'device_session_id' => 'required|string'
        ]);

        $user = Auth::user();
        $subscription = Subscription::with(['plan', 'device.vehicle'])->find($request->subscription_id);

        // Verificaciones de seguridad
        if ($subscription->customer_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'No autorizado'
            ], 403);
        }

        if ($subscription->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'La suscripción no está pendiente de pago'
            ], 400);
        }

        try {
            Log::info('💳 Procesando pago de subscripción', [
                'customer_id' => $user->id,
                'subscription_id' => $subscription->id,
                'plan_id' => $subscription->plan_id,
            ]);

            // Procesar el pago con device_session_id
            $result = $this->openPayService->paySubscription(
                $user->openpay_customer_id,
                $subscription,
                $request->card_id,
                $request->device_session_id
            );

            if ($result['success']) {
                // Actualizar la subscripción
                $subscription->update([
                    'status' => 'active',
                    'start_date' => now(),
                    'end_date' => now()->addMonths($subscription->plan->interval === 'month' ? 1 : 12),
                    'paid_at' => now(),
                    'payment_reference' => $result['transaction_id'] ?? null, // Si OpenPay devuelve ID
                ]);

                Log::info('✅ Pago procesado exitosamente', [
                    'subscription_id' => $subscription->id,
                    'start_date' => $subscription->start_date,
                    'end_date' => $subscription->end_date,
                ]);

                // ✅ DISPARAR EVENTO DE NOTIFICACIÓN
                SubscriptionPaid::dispatch($subscription);

                return response()->json([
                    'success' => true,
                    'message' => 'Pago procesado correctamente',
                    'subscription' => [
                        'id' => $subscription->id,
                        'plan' => $subscription->plan->name,
                        'start_date' => $subscription->start_date->format('Y-m-d'),
                        'end_date' => $subscription->end_date->format('Y-m-d'),
                        'status' => $subscription->status,
                    ]
                ]);
            }

            Log::error('❌ Error en pago de OpenPay', [
                'subscription_id' => $subscription->id,
                'error' => $result['error'],
            ]);

            return response()->json([
                'success' => false,
                'message' => $result['error']
            ], 400);
        } catch (\Exception $e) {
            Log::error('❌ Excepción procesando pago', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error procesando el pago: ' . $e->getMessage()
            ], 500);
        }
    } */

    public function pay(Request $request)
    {
        $request->validate([
            'subscription_id' => 'required|integer|exists:subscriptions,id',
            'card_id' => 'required|string',
            'device_session_id' => 'required|string',
        ]);

        $user = Auth::user();

        $subscription = Subscription::with(['plan'])
            ->findOrFail($request->subscription_id);

        // 🔒 Seguridad
        if ($subscription->customer_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'No autorizado'
            ], 403);
        }

        if ($subscription->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'La suscripción no está pendiente de pago'
            ], 400);
        }

        DB::beginTransaction();

        try {
            Log::info('💳 Procesando pago de suscripción', [
                'customer_id' => $user->id,
                'subscription_id' => $subscription->id,
            ]);

            // =============================
            // 1️⃣ COBRAR EN OPENPAY
            // =============================
            $result = $this->openPayService->paySubscription(
                $user->openpay_customer_id,
                $subscription,
                $request->card_id,
                $request->device_session_id
            );

            if (!$result['success']) {
                throw new \Exception($result['error']);
            }

            // =============================
            // 2️⃣ REGISTRAR PAGO
            // =============================
            $payment = Payment::create([
                'subscription_id' => $subscription->id,
                'customer_id' => $user->id,
                'amount' => $subscription->plan->price,
                'method' => 'card',
                'transaction_reference' => $result['charge_id'],
                'status' => 'approved', // 👈 ENUM correcto
                'paid_at' => now(),
            ]);

            // =============================
            // 3️⃣ ACTIVAR SUSCRIPCIÓN
            // =============================
            $subscription->update([
                'status' => 'active',
                'start_date' => now(),
                'end_date' => now()->addMonths(
                    $subscription->plan->interval === 'month' ? 1 : 12
                ),
                'paid_at' => now(),
                'payment_reference' => $result['charge_id'],
            ]);

            DB::commit();

            // =============================
            // 4️⃣ EVENTO
            // =============================
            SubscriptionPaid::dispatch($subscription);

            Log::info('✅ Pago registrado y suscripción activada', [
                'payment_id' => $payment->id,
                'subscription_id' => $subscription->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Pago procesado correctamente',
                'payment' => [
                    'id' => $payment->id,
                    'amount' => $payment->amount,
                    'status' => $payment->status,
                    'paid_at' => $payment->paid_at->toDateTimeString(),
                ],
                'subscription' => [
                    'id' => $subscription->id,
                    'status' => $subscription->status,
                    'start_date' => $subscription->start_date->format('Y-m-d'),
                    'end_date' => $subscription->end_date->format('Y-m-d'),
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('❌ Error procesando pago', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error procesando el pago: ' . $e->getMessage()
            ], 500);
        }
    }
}
