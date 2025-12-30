<?php

namespace App\Http\Controllers;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use Illuminate\Http\Request;

class PushTokenController extends Controller
{
   public function store(Request $request)
    {
         Log::info('🔔 Push token endpoint hit');

        Log::info('📦 Request data', $request->all());

        $request->validate([
            'token' => 'required|string',
        ]);

        $customer = $request->user();

         Log::info('👤 Customer', [
        'id' => $customer?->id,
        'email' => $customer?->email,
    ]);

        // Evita guardar el mismo token repetido
        if ($customer->expo_push_token !== $request->token) {
            $customer->expo_push_token = $request->token;
            $customer->save();
        }

        return response()->json(['ok' => true]);
    }
}
