<?php

namespace App\Http\Controllers;

use App\Models\EmergencyContact;
use App\Models\User; // O Customer
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EmergencyContactController extends Controller
{
    // 1. LISTAR CONTACTOS DE UN USUARIO
    public function index($customerId)
    {
        $contacts = EmergencyContact::where('customer_id', $customerId)->get();
        return response()->json(['success' => true, 'data' => $contacts]);
    }

    // 2. CREAR CONTACTO
    public function store(Request $request, $customerId)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email',
            'relationship' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 400);
        }

        try {
            $contact = EmergencyContact::create([
                'customer_id' => $customerId,
                'name' => $request->name,
                'phone' => $request->phone,
                'email' => $request->email,
                'relationship' => $request->relationship,
                'notify_whatsapp' => $request->boolean('notify_whatsapp', true),
                'notify_sms' => $request->boolean('notify_sms', false),
                'notify_email' => $request->boolean('notify_email', false),
                'notify_call' => $request->boolean('notify_call', false),
            ]);

            return response()->json(['success' => true, 'message' => 'Contacto agregado', 'data' => $contact]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // 3. ACTUALIZAR CONTACTO
    public function update(Request $request, $id)
    {
        $contact = EmergencyContact::find($id);
        if (!$contact) return response()->json(['success' => false, 'message' => 'Contacto no encontrado'], 404);

        $contact->update($request->all());

        return response()->json(['success' => true, 'message' => 'Contacto actualizado', 'data' => $contact]);
    }

    // 4. ELIMINAR CONTACTO
    public function destroy($id)
    {
        $contact = EmergencyContact::find($id);
        if (!$contact) return response()->json(['success' => false, 'message' => 'Contacto no encontrado'], 404);

        $contact->delete();
        return response()->json(['success' => true, 'message' => 'Contacto eliminado']);
    }
}