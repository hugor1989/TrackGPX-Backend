<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\OpenPayService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class CardController extends AppBaseController
{
    protected $openPayService;

    public function __construct(OpenPayService $openPayService)
    {
        $this->openPayService = $openPayService;
    }

    /**
     * Agregar una nueva tarjeta al cliente en OpenPay
     * POST /api/cards
     */
    public function addCard(Request $request)
    {
        $request->validate([
            'token_id' => 'required|string',
            'device_session_id' => 'required|string'
        ]);

        $user = Auth::user();

        if (!$user->openpay_customer_id) {
            return response()->json([
                'success' => false,
                'message' => 'El usuario no tiene un ID de cliente en OpenPay'
            ], 400);
        }

        // Usar el servicio para agregar la tarjeta
        $result = $this->openPayService->addCardToCustomer(
            $user->openpay_customer_id,
            $request->token_id,
            $request->device_session_id
        );

        if ($result['success']) {
            $card = $result['card'];

            return response()->json([
                'success' => true,
                'message' => 'Tarjeta agregada correctamente',
                'card' => [
                    'id' => $card->id,
                    'brand' => $card->brand,
                    'card_number' => $card->card_number,
                    'holder_name' => $card->holder_name,
                    'expiration_month' => $card->expiration_month,
                    'expiration_year' => $card->expiration_year,
                    'allows_charges' => $card->allows_charges,
                    'allows_payouts' => $card->allows_payouts,
                    'bank_name' => $card->bank_name,
                    'bank_code' => $card->bank_code,
                    'type' => $card->type
                ]
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => $result['error'],
            'error_code' => $result['error_code'] ?? null,
            'category' => $result['category'] ?? null
        ], 400);
    }

    /**
     * Obtener todas las tarjetas del cliente
     * GET /api/cards
     */
    public function getCards(Request $request)
    {
        $user = Auth::user();

        if (!$user->openpay_customer_id) {
            return response()->json([
                'success' => false,
                'message' => 'El usuario no tiene un ID de cliente en OpenPay'
            ], 400);
        }

        $result = $this->openPayService->getCustomerCards($user->openpay_customer_id);

        if ($result['success']) {
            $formattedCards = [];
            foreach ($result['cards'] as $card) {
                $formattedCards[] = [
                    'id' => $card->id,
                    'brand' => $card->brand,
                    'last4' => substr($card->card_number, -4), // Solo últimos 4 dígitos
                    'exp_month' => $card->expiration_month,
                    'exp_year' => $card->expiration_year,
                    'is_default' => false, // OpenPay no tiene este campo por defecto
                    'holder_name' => $card->holder_name,
                    'allows_charges' => $card->allows_charges,
                    'allows_payouts' => $card->allows_payouts,
                    'bank_name' => $card->bank_name,
                    'type' => $card->type,
                    'creation_date' => $card->creation_date
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $formattedCards
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Error obteniendo tarjetas: ' . $result['error']
        ], 500);
    }

    /**
     * Eliminar una tarjeta
     * DELETE /api/cards/{cardId}
     */
    public function deleteCard(Request $request, $cardId)
    {
        $user = Auth::user();

        if (!$user->openpay_customer_id) {
            return response()->json([
                'success' => false,
                'message' => 'El usuario no tiene un ID de cliente en OpenPay'
            ], 400);
        }

        $result = $this->openPayService->deleteCustomerCard($user->openpay_customer_id, $cardId);

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'message' => 'Tarjeta eliminada correctamente'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => $result['error']
        ], 400);
    }

    /**
     * Obtener información de una tarjeta específica
     * GET /api/cards/{cardId}
     */
    public function getCard(Request $request, $cardId)
    {
        $user = Auth::user();

        if (!$user->openpay_customer_id) {
            return response()->json([
                'success' => false,
                'message' => 'El usuario no tiene un ID de cliente en OpenPay'
            ], 400);
        }

        $result = $this->openPayService->getCustomerCard($user->openpay_customer_id, $cardId);

        if ($result['success']) {
            $card = $result['card'];

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $card->id,
                    'brand' => $card->brand,
                    'card_number' => $card->card_number,
                    'holder_name' => $card->holder_name,
                    'expiration_month' => $card->expiration_month,
                    'expiration_year' => $card->expiration_year,
                    'allows_charges' => $card->allows_charges,
                    'allows_payouts' => $card->allows_payouts,
                    'bank_name' => $card->bank_name,
                    'bank_code' => $card->bank_code,
                    'type' => $card->type,
                    'creation_date' => $card->creation_date
                ]
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => $result['error']
        ], 400);
    }
}
