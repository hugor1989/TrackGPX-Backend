<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\User;

class UserController extends AppBaseController
{
    public function profile(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return $this->error('Usuario no autenticado.', 401);
        }

        return $this->success($user, 'Perfil del usuario');
    }

   
}
