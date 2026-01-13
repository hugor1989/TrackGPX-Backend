<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Device;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MemberController extends AppBaseController
{
    /**
     * Crear miembro (familia / amigo)
     */
    public function store(Request $request)
    {
        // 🔥 AQUÍ ESTÁ LA CLAVE
        $admin = Auth::guard('customer')->user();

        abort_unless($admin && $admin->role === 'admin', 403, 'Solo el admin puede crear miembros');

        $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:customers,email',
            'phone' => 'required|string|max:20',
            'password' => 'required|min:6'
        ]);

        $member = Customer::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'role' => 'member',          // ✅ YA NO SE PISA
            'parent_id' => $admin->id,   // ✅ YA SE ASIGNA
            'status' => 'active'
        ]);

        return $this->success($member, 'Miembro creado correctamente', 201);
    }

    /**
     * Listar miembros del admin
     */
    public function index(Request $request)
    {
        $admin = Auth::guard('customer')->user();

        abort_unless($admin && $admin->role === 'admin', 403, 'Solo el admin puede ver los miembros');

        $members = Customer::where('parent_id', $admin->id)
            ->select('id', 'name', 'email', 'phone', 'status')
            ->get();

        return $this->success(
            $members,
            'Listado de miembros',
            200
        );
    }

    /**
     * Asignar dispositivos a un miembro
     */
    public function assignDevices(Request $request, Customer $member)
    {
        // 🔐 USAR GUARD CORRECTO
        $admin = Auth::guard('customer')->user();

        abort_unless($admin && $admin->role === 'admin', 403, 'No autorizado');
        abort_unless(
            $member->parent_id === $admin->id,
            403,
            'Este miembro no pertenece a tu cuenta'
        );

        $request->validate([
            'device_ids' => 'required|array',
            'device_ids.*' => 'exists:devices,id'
        ]);

        // 🔒 Solo dispositivos del admin
        $validDevices = Device::whereIn('id', $request->device_ids)
            ->where('customer_id', $admin->id)
            ->pluck('id')
            ->values(); // 👈 limpia índices

        // 🔁 Sync dispositivos compartidos
        $member->sharedDevices()->sync($validDevices);

        return $this->success(
            [
                'member_id' => $member->id,
                'assigned_device_ids' => $validDevices
            ],
            'Dispositivos asignados correctamente',
            200
        );
    }

    public function devices(Customer $member)
    {
        $admin = Auth::guard('customer')->user();

        abort_unless($admin && $admin->role === 'admin', 403);
        abort_unless($member->parent_id === $admin->id, 403);

        $devices = Device::where('customer_id', $admin->id)
            ->select('id', 'imei', 'status')
            ->get();

        $assigned = $member->sharedDevices()->pluck('devices.id');

        return $this->success([
            'devices' => $devices,
            'assigned' => $assigned
        ], 'Dispositivos del miembro');
    }
}
