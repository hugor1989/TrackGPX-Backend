<?php

namespace App\Http\Controllers;

use App\Models\SimCard;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class SimCardController extends Controller
{
    /**
     * Obtener todas las SIM cards
     */
    public function getAllSimCard(): JsonResponse
    {
        try {
            $simCards = SimCard::all();
            return response()->json($simCards);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al obtener las SIM cards',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crear una nueva SIM card
     */
    public function create_SimCard(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'sim_id' => 'required|string|unique:sim_cards,sim_id',
            'iccid' => 'required|string|unique:sim_cards,iccid',
            'carrier' => 'required|string',
            'phone_number' => 'nullable|string',
            'status' => 'required|in:active,inactive,suspended',
            'imsi' => 'nullable|string',
            'subscription_type' => 'nullable|string',
            'data_usage' => 'nullable|numeric',
            'data_limit' => 'nullable|numeric',
            'voice_usage' => 'nullable|string',
            'sms_usage' => 'nullable|string',
            'plan_name' => 'nullable|string',
            'client_name' => 'nullable|string',
            'device_brand' => 'nullable|string',
            'monthly_fee' => 'nullable|numeric',
            'activation_date' => 'nullable|date',
            'expiration_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'apn' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $simCard = SimCard::create($request->all());

            return response()->json([
                'message' => 'SIM card creada exitosamente',
                'data' => $simCard
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al crear la SIM card',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar una SIM card
     */
    public function updateSimCard(Request $request, $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'sim_id' => 'sometimes|string|unique:sim_cards,sim_id,' . $id,
            'iccid' => 'sometimes|string|unique:sim_cards,iccid,' . $id,
            'carrier' => 'sometimes|string',
            'phone_number' => 'nullable|string',
            'status' => 'sometimes|in:active,inactive,suspended',
            'imsi' => 'nullable|string',
            'subscription_type' => 'nullable|string',
            'data_usage' => 'nullable|numeric',
            'data_limit' => 'nullable|numeric',
            'voice_usage' => 'nullable|string',
            'sms_usage' => 'nullable|string',
            'plan_name' => 'nullable|string',
            'client_name' => 'nullable|string',
            'device_brand' => 'nullable|string',
            'monthly_fee' => 'nullable|numeric',
            'activation_date' => 'nullable|date',
            'expiration_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'apn' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $simCard = SimCard::find($id);

            if (!$simCard) {
                return response()->json([
                    'error' => 'SIM card no encontrada'
                ], 404);
            }

            $simCard->update($request->all());

            return response()->json([
                'message' => 'SIM card actualizada exitosamente',
                'data' => $simCard
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al actualizar la SIM card',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Importar SIM cards desde CSV del proveedor
     */
    public function importFromProvider(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'sim_cards' => 'required|array',

            'sim_cards.*.sim_id' => 'required|string',
            'sim_cards.*.iccid' => 'required|string',
            'sim_cards.*.carrier' => 'required|string',

            'sim_cards.*.phone_number' => 'nullable|string',
            'sim_cards.*.status' => 'required|string', // ← ya NO validamos aquí el enum
            'sim_cards.*.imsi' => 'nullable|string',
            'sim_cards.*.subscription_type' => 'nullable|string',

            'sim_cards.*.data_usage' => 'nullable|numeric',
            'sim_cards.*.data_limit' => 'nullable|numeric',

            'sim_cards.*.voice_usage' => 'nullable|string',
            'sim_cards.*.sms_usage' => 'nullable|string',

            'sim_cards.*.plan_name' => 'nullable|string',
            'sim_cards.*.client_name' => 'nullable|string',
            'sim_cards.*.device_brand' => 'nullable|string',

            'sim_cards.*.monthly_fee' => 'nullable|numeric',
            'sim_cards.*.activation_date' => 'nullable|date',
            'sim_cards.*.expiration_date' => 'nullable|date',

            'sim_cards.*.notes' => 'nullable|string',
            'sim_cards.*.apn' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $importedCount = 0;
            $errors = [];

            foreach ($request->sim_cards as $index => $raw) {

                try {
                    // 🔹 Normalizar estado
                    $status = $this->normalizeStatus($raw['status'] ?? null);

                    if (!$status) {
                        $errors[] = "Estado inválido '{$raw['status']}' en fila {$index}";
                        continue;
                    }

                    // 🔹 Validar duplicados
                    if (SimCard::where('iccid', $raw['iccid'])->exists()) {
                        $errors[] = "SIM con ICCID {$raw['iccid']} ya existe (fila {$index})";
                        continue;
                    }

                    if (SimCard::where('sim_id', $raw['sim_id'])->exists()) {
                        $errors[] = "SIM con ID {$raw['sim_id']} ya existe (fila {$index})";
                        continue;
                    }

                    // 🔹 Crear SIM
                    SimCard::create([
                        'sim_id'            => trim($raw['sim_id']),
                        'iccid'             => trim($raw['iccid']),
                        'carrier'           => trim($raw['carrier']),
                        'phone_number'      => $raw['phone_number'] ?? null,

                        'status'            => $status, // 👈 ENUM SEGURO

                        'imsi'              => $raw['imsi'] ?? null,
                        'subscription_type' => $raw['subscription_type'] ?? null,

                        'data_usage'        => $raw['data_usage'] ?? null,
                        'data_limit'        => $raw['data_limit'] ?? null,

                        'voice_usage'       => $raw['voice_usage'] ?? null,
                        'sms_usage'         => $raw['sms_usage'] ?? null,

                        'plan_name'         => $raw['plan_name'] ?? null,
                        'client_name'       => $raw['client_name'] ?? null,
                        'device_brand'      => $raw['device_brand'] ?? null,

                        'monthly_fee'       => $raw['monthly_fee'] ?? null,
                        'activation_date'   => $raw['activation_date'] ?? null,
                        'expiration_date'   => $raw['expiration_date'] ?? null,

                        'notes'             => $raw['notes'] ?? null,
                        'apn'               => $raw['apn'] ?? null,
                    ]);

                    $importedCount++;
                } catch (\Throwable $e) {
                    $errors[] = "Error en fila {$index}: " . $e->getMessage();
                }
            }

            DB::commit();

            return response()->json([
                'message' => "{$importedCount} SIM cards importadas correctamente",
                'imported_count' => $importedCount,
                'errors' => $errors
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'error' => 'Error en la importación',
                'message' => $e->getMessage()
            ], 500);
        }
    }


    private function normalizeStatus(?string $status): ?string
    {
        if (!$status) {
            return null;
        }

        // Normalizar texto
        $clean = strtolower($status);
        $clean = preg_replace('/[\r\n\t]+/', '', $clean); // quita saltos invisibles
        $clean = preg_replace('/\s+/', ' ', $clean);      // espacios múltiples
        $clean = trim($clean);

        return match (true) {
            str_contains($clean, 'pre')      => 'inactive',   // Pre-Activada
            str_contains($clean, 'inact')    => 'inactive',
            str_contains($clean, 'activ')    => 'active',     // Activada
            str_contains($clean, 'suspend')  => 'suspended',  // Suspendida
            default                          => null,
        };
    }

    /**
     * Obtener una SIM card específica
     */
    public function Get_Simcard_ById($id): JsonResponse
    {
        try {
            $simCard = SimCard::find($id);

            if (!$simCard) {
                return response()->json([
                    'error' => 'SIM card no encontrada'
                ], 404);
            }

            return response()->json($simCard);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al obtener la SIM card',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar una SIM card
     */
    public function destroy($id): JsonResponse
    {
        try {
            $simCard = SimCard::find($id);

            if (!$simCard) {
                return response()->json([
                    'error' => 'SIM card no encontrada'
                ], 404);
            }

            $simCard->delete();

            return response()->json([
                'message' => 'SIM card eliminada exitosamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al eliminar la SIM card',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener SIM cards disponibles (sin asignar a dispositivo)
     */
    public function getAvailableSims(Request $request): JsonResponse
    {
        try {
            $query = SimCard::where('status', 'inactive')
                ->whereNull('device_id');

            // Si necesitas filtrar por operadora u otros criterios
            if ($request->has('carrier')) {
                $query->where('carrier', $request->carrier);
            }

            $sims = $query->get();

            return response()->json([
                'success' => true,
                'data' => $sims,
                'message' => 'SIM cards disponibles obtenidas exitosamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener SIM cards disponibles: ' . $e->getMessage()
            ], 500);
        }
    }
}
