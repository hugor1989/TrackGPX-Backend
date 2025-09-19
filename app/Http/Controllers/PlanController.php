<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\Request;
use App\Services\OpenPayService;

class PlanController extends AppBaseController
{
    protected $openPayService;

    // Inyectar el servicio en el constructor
    public function __construct(OpenPayService $openPayService)
    {
        $this->openPayService = $openPayService;
    }
    // Listar todos los planes
    public function GetAllPlans()
    {
        $plans = Plan::all();
        return $this->success($plans, 'Lista de planes');
    }

    public function CreatePlan(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'interval_count' => 'required|integer|min:1',
            'interval' => 'required|in:day,week,month,year',
            'features' => 'nullable|array',         // 👈 debe ser array
        ]);

        try {
            // Crear plan en OpenPay usando el SDK
            $openpayPlan = $this->openPayService->createPlan([
                'name' => $request->name,
                'amount' => $request->price,
                'currency' => 'MXN',
                'interval_count' => $request->interval_count,
                'interval' => $request->interval,
                'status_payd' => 'cancelled',
                'description' => $request->description,
                'status' => 'active'
            ]);

            // Guardar plan en base de datos
            $plan = Plan::create([
                'openpay_plan_id' => $openpayPlan->id,
                'name' => $request->name,
                'description' => $request->description,
                'price' => $request->price,
                'currency' => 'MXN',
                'interval' => $request->interval,
                'interval_count' => $request->interval_count,
                'features' => $request->features,   // 👈 guarda el array como JSON
                'status' => 'active'
            ]);

            return $this->success($plan, 'Plan creado correctamente', 201);
        } catch (\Exception $e) {
            return $this->error('Error creating plan: ' . $e->getMessage(), 500);
        }
    }
   

    // Ver un plan
    public function GetPlanById($id)
    {
        $plan = Plan::find($id);

        if (!$plan) {
            return $this->error('Plan no encontrado', 404);
        }

        return $this->success($plan, 'Plan encontrado');
    }

    // Actualizar plan
    public function PlanUpdateData(Request $request, $id)
    {
        $plan = Plan::find($id);

        if (!$plan) {
            return $this->error('Plan no encontrado', 404);
        }

        $request->validate([
            'name'          => 'sometimes|string|max:255',
            'price'         => 'sometimes|numeric|min:0',
            'duration_days' => 'sometimes|integer|min:1',
            'description'   => 'nullable|string'
        ]);

        $plan->update($request->all());

        return $this->success($plan, 'Plan actualizado correctamente');
    }

    // Eliminar plan
    public function PlanDeleteById($id)
    {
        $plan = Plan::find($id);

        if (!$plan) {
            return $this->error('Plan no encontrado', 404);
        }

        $plan->delete();

        return $this->success(null, 'Plan eliminado correctamente');
    }
}
