<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\Customer;

class CustomerController extends AppBaseController
{

    public function profile(Request $request)
    {
        $customer = $request->user();

        if (!$customer) {
            return $this->error('Cliente no autenticado.', 401);
        }

        return $this->success($customer, 'Perfil del cliente');
    }

     // Listar todos los clientes
    public function GetAllCustomers()
    {
        $customers = Customer::all();
        return $this->success($customers, 'Lista de clientes');
    }

    // Mostrar un cliente por ID
    public function GetCustomerById($id)
    {
        $customer = Customer::find($id);

        if (!$customer) {
            return $this->error('Cliente no encontrado', 404);
        }

        return $this->success($customer, 'Cliente encontrado');
    }


    // Activar/Inactivar cliente
    public function toggleActiveCustomer($id)
    {
        $customer = Customer::find($id);

        if (!$customer) {
            return $this->error('Cliente no encontrado', 404);
        }

        $customer->status = $customer->status === 'active' ? 'inactive' : 'active';
        $customer->save();

        return $this->success($customer, "Cliente {$customer->status} correctamente");
    }

    // Eliminar cliente
    public function Customerdestroy($id)
    {
        $customer = Customer::find($id);

        if (!$customer) {
            return $this->error('Cliente no encontrado', 404);
        }

        $customer->delete();

        return $this->success(null, 'Cliente eliminado correctamente');
    }

}