<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TaxInformation;

class TaxInformationController extends Controller
{
    // Obtener datos
    public function show(Request $request)
    {
        // Asumiendo que el usuario autenticado es el customer o tiene relación
        $user = $request->user(); 
        
        $taxInfo = TaxInformation::where('customer_id', $user->id)->first();
        
        return response()->json([
            'success' => true,
            'data' => $taxInfo // Puede venir null si nunca ha guardado
        ]);
    }

    // Guardar o Actualizar
    public function store(Request $request)
    {
        $request->validate([
            'razon_social' => 'required|string',
            'rfc' => 'required|string|min:12|max:13', // Validación básica RFC
            'codigo_postal' => 'required|numeric|digits:5',
            'regimen_fiscal' => 'required',
            'correo_facturacion' => 'required|email',
        ]);

        $user = $request->user();

        $taxInfo = TaxInformation::updateOrCreate(
            ['customer_id' => $user->id], // Buscamos por ID
            [
                'razon_social' => strtoupper($request->razon_social),
                'rfc' => strtoupper($request->rfc),
                'regimen_fiscal' => $request->regimen_fiscal,
                'codigo_postal' => $request->codigo_postal,
                'direccion' => $request->direccion,
                'correo_facturacion' => $request->correo_facturacion,
                'uso_cfdi' => $request->uso_cfdi ?? 'G03'
            ]
        );

        return response()->json([
            'success' => true, 
            'message' => 'Datos fiscales guardados correctamente.',
            'data' => $taxInfo
        ]);
    }
}
