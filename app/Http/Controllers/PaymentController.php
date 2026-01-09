<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    public function index(): JsonResponse
    {
        $payments = Payment::with(['customer', 'subscription'])
            ->orderByDesc('paid_at')
            ->get()
            ->map(function (Payment $payment) {

                // 👉 Comisión ejemplo (ajústala si usas otra lógica)
                $fee = round($payment->amount * 0.05, 2);
                $netAmount = $payment->amount - $fee;

                return [
                    'id' => 'PAY-' . str_pad($payment->id, 6, '0', STR_PAD_LEFT),

                    'subscription_id' => $payment->subscription?->id,
                    'subscription_code' => $payment->subscription?->code ?? null,

                    'client_name' => $payment->customer?->name ?? 'N/A',

                    'amount' => (float) $payment->amount,
                    'fee' => (float) $fee,
                    'net_amount' => (float) $netAmount,

                    'status' => $this->normalizeStatus($payment->status),

                    'payment_method' => $payment->method,
                    'payment_type' => $this->paymentTypeFromMethod($payment->method),

                    'transaction_id' => $payment->transaction_reference,

                    'paid_at' => optional($payment->paid_at)->toISOString(),

                    'description' => $payment->subscription
                        ? 'Pago de suscripción'
                        : 'Pago',
                ];
            });

        return response()->json([
            'data' => $payments
        ]);
    }

    /**
     * Normaliza status para el frontend
     */
    private function normalizeStatus(string $status): string
    {
        return match ($status) {
            'approved', 'paid', 'success' => 'completed',
            'pending' => 'pending',
            'failed', 'declined' => 'failed',
            'refunded' => 'refunded',
            default => 'pending',
        };
    }

    /**
     * Traduce method a tipo legible
     */
    private function paymentTypeFromMethod(string $method): string
    {
        return match (strtolower($method)) {
            'card', 'credit_card' => 'Tarjeta de Crédito',
            'debit_card' => 'Tarjeta de Débito',
            'transfer', 'spei' => 'Transferencia',
            default => ucfirst($method),
        };
    }
}
