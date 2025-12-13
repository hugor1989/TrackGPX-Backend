<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Vehicle;
use App\Models\SimCard;
use App\Models\DeviceLog;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DeviceController extends Controller
{
    /**
     * Display a listing of the devices.
     */
    public function getAllDevices(Request $request): JsonResponse
    {
        try {
            $query = Device::with(['vehicle.user', 'simCard'])
                ->select(['devices.*']);

            // Filtro por estado
            if ($request->has('status') && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            // Búsqueda
            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('imei', 'LIKE', "%{$search}%")
                        ->orWhere('serial_number', 'LIKE', "%{$search}%")
                        ->orWhere('manufacturer', 'LIKE', "%{$search}%")
                        ->orWhere('model', 'LIKE', "%{$search}%")
                        ->orWhereHas('vehicle', function ($q) use ($search) {
                            $q->where('plate', 'LIKE', "%{$search}%")
                                ->orWhere('brand', 'LIKE', "%{$search}%")
                                ->orWhere('model', 'LIKE', "%{$search}%")
                                ->orWhereHas('user', function ($q) use ($search) {
                                    $q->where('name', 'LIKE', "%{$search}%")
                                        ->orWhere('email', 'LIKE', "%{$search}%");
                                });
                        });
                });
            }

            // Ordenamiento
            $sortField = $request->get('sort_field', 'created_at');
            $sortDirection = $request->get('sort_direction', 'desc');
            $query->orderBy($sortField, $sortDirection);

            $devices = $query->get();

            return response()->json([
                'success' => true,
                'data' => $devices,
                'message' => 'Dispositivos obtenidos exitosamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los dispositivos: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created device.
     */
    public function createDevice(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'imei' => 'required|digits:15|unique:devices,imei',
            'serial_number' => 'required|string|max:255|unique:devices,serial_number',
            'manufacturer' => 'nullable|string|max:100',
            'model' => 'nullable|string|max:100',
            'protocol' => 'nullable|string|max:50',
            'activation_code' => 'nullable|string|max:10'
        ], [
            'imei.required' => 'El IMEI es obligatorio',
            'imei.digits' => 'El IMEI debe tener exactamente 15 dígitos',
            'imei.unique' => 'El IMEI ya está registrado',
            'serial_number.required' => 'El número de serie es obligatorio',
            'serial_number.unique' => 'El número de serie ya está registrado',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $device = Device::create([
                'imei' => $request->imei,
                'serial_number' => $request->serial_number,
                'manufacturer' => $request->manufacturer,
                'model' => $request->model,
                'protocol' => $request->protocol ?? 'JT808',
                'status' => 'pending',
                'activation_code' => $request->activation_code ?? $this->generateActivationCode(),
                'config_parameters' => $this->getDefaultConfigParameters()
            ]);

            DB::commit();

            // Cargar relaciones para la respuesta
            $device->load(['vehicle.user', 'simCard']);

            return response()->json([
                'success' => true,
                'data' => $device,
                'message' => 'Dispositivo registrado exitosamente'
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al registrar el dispositivo: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Registro Automatico de device
     */
    public function autoRegisterDevices(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'imei' => 'required|unique:devices,imei',
            'ip_address' => 'nullable|ip',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $device = Device::create([
                'imei' => $request->imei,
                'serial_number' => null,
                'protocol' => 'JT808',
                'status' => 'pending',
                'manufacturer' => 'Cherry',
                'model' => '',
                'ip_address' => $request->ip_address ?? $request->ip(),
                'activation_code' => $this->generateActivationCode(),
                'config_parameters' => $this->getDefaultConfigParameters(),
            ]);

            return response()->json([
                'success' => true,
                'data' => $device,
                'message' => 'Dispositivo detectado y registrado automáticamente',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al registrar el dispositivo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified device.
     */
    public function getDevicebyId($id): JsonResponse
    {
        try {
            $device = Device::with(['vehicle.user', 'simCard', 'locations' => function ($query) {
                $query->latest()->limit(10);
            }])->find($id);

            if (!$device) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dispositivo no encontrado'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $device,
                'message' => 'Dispositivo obtenido exitosamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el dispositivo: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified device.
     */
    public function updateDevice(Request $request, $id): JsonResponse
    {
        $device = Device::find($id);

        if (!$device) {
            return response()->json([
                'success' => false,
                'message' => 'Dispositivo no encontrado'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'imei' => 'sometimes|digits:15|unique:devices,imei,' . $device->id,
            'serial_number' => 'sometimes|string|max:255|unique:devices,serial_number,' . $device->id,
            'manufacturer' => 'nullable|string|max:100',
            'model' => 'nullable|string|max:100',
            'protocol' => 'nullable|string|max:50',
            'status' => 'sometimes|in:active,inactive,disconnected,pending',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'sim_card_id' => 'nullable|exists:sim_cards,id', // ← AGREGAR ESTA LÍNEA
            'config_parameters' => 'nullable|json'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $device->update($request->only([
                'imei',
                'serial_number',
                'manufacturer',
                'model',
                'protocol',
                'status',
                'vehicle_id',
                'sim_card_id',
                'config_parameters'
            ]));

            // Si se activa el dispositivo, registrar la fecha de activación
            if ($request->has('status') && $request->status === 'active' && !$device->activated_at) {
                $device->update(['activated_at' => now()]);
            }

            DB::commit();

            // Recargar relaciones actualizadas
            $device->load(['vehicle.user', 'simCard']);

            return response()->json([
                'success' => true,
                'data' => $device,
                'message' => 'Dispositivo actualizado exitosamente'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el dispositivo: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified device.
     */
    public function deletebyId($id): JsonResponse
    {
        try {
            $device = Device::find($id);

            if (!$device) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dispositivo no encontrado'
                ], 404);
            }

            // Verificar si el dispositivo tiene datos asociados
            if ($device->locations()->exists() || $device->events()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede eliminar el dispositivo porque tiene datos históricos asociados'
                ], 422);
            }

            DB::beginTransaction();

            // Eliminar SIM card asociada si existe
            if ($device->simCard) {
                $device->simCard->delete();
            }

            $device->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Dispositivo eliminado exitosamente'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar el dispositivo: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate activation code for device.
     */
    public function generateActivationCodeDevice($id): JsonResponse
    {
        try {
            $device = Device::find($id);

            if (!$device) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dispositivo no encontrado'
                ], 404);
            }

            $activationCode = $this->generateActivationCode();
            $device->update(['activation_code' => $activationCode]);

            return response()->json([
                'success' => true,
                'data' => [
                    'activation_code' => $activationCode,
                    'device_id' => $device->id,
                    'imei' => $device->serial_number
                ],
                'message' => 'Código de activación generado exitosamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al generar código de activación: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk import devices from CSV.
     */
    public function import(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'csv_file' => 'required|file|mimes:csv,txt|max:10240' // 10MB max
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $file = $request->file('csv_file');
            $csvData = array_map('str_getcsv', file($file));
            $headers = array_shift($csvData);

            $imported = 0;
            $errors = [];

            foreach ($csvData as $index => $row) {
                if (count($row) < 2) {
                    $errors[] = "Fila " . ($index + 2) . ": Formato incorrecto";
                    continue;
                }

                $imei = trim($row[0]);
                $serialNumber = trim($row[1]);
                $manufacturer = isset($row[2]) ? trim($row[2]) : null;
                $model = isset($row[3]) ? trim($row[3]) : null;

                // Validar IMEI
                if (!preg_match('/^[0-9]{15}$/', $imei)) {
                    $errors[] = "Fila " . ($index + 2) . ": IMEI inválido - " . $imei;
                    continue;
                }

                // Verificar duplicados
                if (Device::where('imei', $imei)->exists()) {
                    $errors[] = "Fila " . ($index + 2) . ": IMEI ya existe - " . $imei;
                    continue;
                }

                if (Device::where('serial_number', $serialNumber)->exists()) {
                    $errors[] = "Fila " . ($index + 2) . ": Serial ya existe - " . $serialNumber;
                    continue;
                }

                try {
                    Device::create([
                        'imei' => $imei,
                        'serial_number' => $serialNumber,
                        'manufacturer' => $manufacturer,
                        'model' => $model,
                        'protocol' => 'JT808',
                        'status' => 'pending',
                        'activation_code' => $this->generateActivationCode(),
                        'config_parameters' => $this->getDefaultConfigParameters()
                    ]);

                    $imported++;
                } catch (\Exception $e) {
                    $errors[] = "Fila " . ($index + 2) . ": Error al crear - " . $e->getMessage();
                }
            }

            $message = "Importación completada: {$imported} dispositivos importados.";
            if (!empty($errors)) {
                $message .= " Errores: " . implode(', ', array_slice($errors, 0, 10));
                if (count($errors) > 10) {
                    $message .= " ... y " . (count($errors) - 10) . " más";
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'imported' => $imported,
                    'errors' => $errors
                ],
                'message' => $message
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error en la importación: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get device statistics.
     */
    public function statistics(): JsonResponse
    {
        try {
            $stats = [
                'total' => Device::count(),
                'active' => Device::where('status', 'active')->count(),
                'inactive' => Device::where('status', 'inactive')->count(),
                'disconnected' => Device::where('status', 'disconnected')->count(),
                'pending' => Device::where('status', 'pending')->count(),
                'with_vehicle' => Device::whereNotNull('vehicle_id')->count(),
                'without_vehicle' => Device::whereNull('vehicle_id')->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
                'message' => 'Estadísticas obtenidas exitosamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate a unique activation code.
     */
    private function generateActivationCode(): string
    {
        do {
            $code = strtoupper(substr(md5(uniqid()), 0, 6));
        } while (Device::where('activation_code', $code)->exists());

        return $code;
    }

    public function activateFromApp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'imei' => 'required|string|max:255',
            'activation_code' => 'required|string|size:6',
            'user_id' => 'required|exists:customers,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Datos de activación inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Buscar dispositivo pendiente por serial_number
            $device = Device::where('imei', $request->imei)
                ->where('activation_code', $request->activation_code)
                ->where('status', 'pending')
                ->first();

            if (!$device) {
                return response()->json([
                    'success' => false,
                    'message' => 'Código de activación inválido o dispositivo ya activado'
                ], 404);
            }

            // Activar el dispositivo
            $device->update([
                'status' => 'active',
                'activated_at' => now(),
                'customer_id' => $request->user_id,
                // vehicle_id no se asigna aquí, lo hará el usuario desde la app después
            ]);

            // Log de activación
            DeviceLog::create([
                'device_id' => $device->id,
                'action' => 'activation',
                'raw_data' => null,
                'description' => 'Dispositivo activado via app móvil por usuario ID: ' . $request->user_id,
                'ip_address' => $request->ip()
            ]);

            DB::commit();

            // Cargar solo la relación necesaria (simCard si existe)
            $device->load(['simCard']);

            return response()->json([
                'success' => true,
                'data' => [
                    'device' => $device
                ],
                'message' => 'Dispositivo activado exitosamente'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error durante la activación: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get devices by customer ID
     */
    public function getDevicesByCustomer($customerId): JsonResponse
    {
        try {
            $devices = Device::with(['vehicle', 'simCard'])
                ->where('customer_id', $customerId)
                ->where('status', 'active') // Solo dispositivos activos
                ->select([
                    'id',
                    'imei',
                    'serial_number',
                    'manufacturer',
                    'model',
                    'status',
                    'activation_code',
                    'activated_at',
                    'created_at',
                    'updated_at',
                    'vehicle_id',
                    'customer_id'
                ])
                ->orderBy('created_at', 'desc')
                ->get();

            // Formatear la respuesta para la app móvil
            $formattedDevices = $devices->map(function ($device) {
                return [
                    'id' => $device->id,
                    'name' => $device->vehicle ? $device->vehicle->brand . ' ' . $device->vehicle->model : $device->manufacturer . ' ' . $device->model,
                    'imei' => $device->imei,
                    'serial_number' => $device->serial_number,
                    'manufacturer' => $device->manufacturer,
                    'model' => $device->model,
                    'status' => $this->mapDeviceStatus($device->status),
                    'battery' => $this->getBatteryLevel($device->id), // Necesitarás implementar esta función
                    'lastUpdate' => $this->getLastUpdate($device->id), // Necesitarás implementar esta función
                    'vehicle' => $device->vehicle ? [
                        'id' => $device->vehicle->id,
                        'plate' => $device->vehicle->plate,
                        'brand' => $device->vehicle->brand,
                        'model' => $device->vehicle->model,
                        'color' => $device->vehicle->color
                    ] : null,
                    'sim_card' => $device->simCard ? [
                        'id' => $device->simCard->id,
                        'number' => $device->simCard->number,
                        'carrier' => $device->simCard->carrier,
                        'status' => $device->simCard->status
                    ] : null
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedDevices,
                'message' => 'Dispositivos del cliente obtenidos exitosamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los dispositivos del cliente: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Map device status to app status
     */
    private function mapDeviceStatus($status): string
    {
        $statusMap = [
            'active' => 'Conectado',
            'inactive' => 'Desconectado',
            'disconnected' => 'Desconectado',
            'pending' => 'Pendiente'
        ];

        return $statusMap[$status] ?? 'Desconectado';
    }

    /**
     * Get battery level from last device location (needs implementation)
     */
    private function getBatteryLevel($deviceId): int
    {
        try {
            $lastLocation = DB::table('locations')
                ->where('device_id', $deviceId)
                ->whereNotNull('battery_level')
                ->orderBy('created_at', 'desc')
                ->first();

            return $lastLocation ? $lastLocation->battery_level : 100;
        } catch (\Exception $e) {
            return 100; // Valor por defecto
        }
    }

    /**
     * Get last update time
     */
    private function getLastUpdate($deviceId): string
    {
        try {
            $lastLocation = DB::table('locations')
                ->where('device_id', $deviceId)
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$lastLocation) {
                return 'Nunca';
            }

            $lastUpdate = \Carbon\Carbon::parse($lastLocation->created_at);
            $now = \Carbon\Carbon::now();

            $diffInMinutes = $lastUpdate->diffInMinutes($now);

            if ($diffInMinutes < 1) {
                return 'Hace unos segundos';
            } elseif ($diffInMinutes < 60) {
                return "Hace {$diffInMinutes} min";
            } elseif ($diffInMinutes < 1440) {
                $hours = floor($diffInMinutes / 60);
                return "Hace {$hours} " . ($hours === 1 ? 'hora' : 'horas');
            } else {
                $days = floor($diffInMinutes / 1440);
                return "Hace {$days} " . ($days === 1 ? 'día' : 'días');
            }
        } catch (\Exception $e) {
            return 'Desconocido';
        }
    }
    /**
     * Get default configuration parameters for devices.
     */
    private function getDefaultConfigParameters(): array
    {
        return [
            'heartbeat_interval' => 60,
            'gps_interval' => 30,
            'overspeed_limit' => 80,
            'sos_numbers' => [],
            'geo_fences' => [],
            'alarms' => [
                'sos' => true,
                'overspeed' => true,
                'low_battery' => true,
                'power_cut' => true
            ]
        ];
    }

    public function getNearbyDevices($imei, Request $request)
    {
        $radius = $request->query('radius', 2); // kilómetros

        $mainDevice = Device::where('imei', $imei)->first();
        if (!$mainDevice) {
            return response()->json([
                'success' => false,
                'message' => 'Dispositivo principal no encontrado'
            ], 404);
        }

        $mainLocation = Location::where('device_id', $mainDevice->id)
            ->latest('created_at')
            ->first();

        if (!$mainLocation) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontró ubicación para el GPS principal'
            ], 404);
        }

        $lat = $mainLocation->latitude;
        $lon = $mainLocation->longitude;

        // 🔍 Dispositivos cercanos
        $nearbyDevices = Device::where('customer_id', $mainDevice->customer_id)
            ->where('imei', '!=', $imei)
            ->select('devices.id', 'devices.imei')
            ->selectSub(function ($query) {
                $query->from('locations')
                    ->select('latitude')
                    ->whereColumn('locations.device_id', 'devices.id')
                    ->latest('created_at')
                    ->limit(1);
            }, 'latitude')
            ->selectSub(function ($query) {
                $query->from('locations')
                    ->select('longitude')
                    ->whereColumn('locations.device_id', 'devices.id')
                    ->latest('created_at')
                    ->limit(1);
            }, 'longitude')
            ->selectRaw("(
            6371 * acos(
                cos(radians(?)) * cos(radians(latitude)) *
                cos(radians(longitude) - radians(?)) +
                sin(radians(?)) * sin(radians(latitude))
            )
        ) AS distance", [$lat, $lon, $lat])
            ->having('distance', '<=', $radius)
            ->orderBy('distance', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Dispositivos cercanos obtenidos correctamente',
            'data' => [
                'main_device' => [
                    'imei' => $imei,
                    'lat' => $lat,
                    'lon' => $lon
                ],
                'nearby_devices' => $nearbyDevices->map(function ($device) {
                    return [
                        'imei' => $device->imei,
                        'lat' => (float) $device->latitude,
                        'lon' => (float) $device->longitude,
                        'distance_km' => round($device->distance, 2)
                    ];
                })
            ]
        ]);
    }
}
