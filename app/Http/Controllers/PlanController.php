<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\Request;

class PlanController extends AppBaseController
{
     // Listar todos los planes
    public function GetAllPlans()
    {
        $plans = Plan::all();
        return $this->success($plans, 'Lista de planes');
    }

    // Crear plan
    public function CreatePlan(Request $request)
    {
        $request->validate([
            'name'          => 'required|string|max:255',
            'price'         => 'required|numeric|min:0',
            'duration_days' => 'required|integer|min:1',
            'description'   => 'nullable|string'
        ]);

        $plan = Plan::create($request->all());

        return $this->success($plan, 'Plan creado correctamente', 201);
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
