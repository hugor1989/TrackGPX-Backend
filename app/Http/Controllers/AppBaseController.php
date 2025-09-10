<?php

namespace App\Http\Controllers;

use App\Utils\ResponseUtil;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Response;


class AppBaseController extends Controller
{
    /**
     * Respuesta exitosa genérica
     */
    protected function success($data = null, string $message = 'Success', int $code = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
            'status_code' => $code,
        ], $code);
    }

    /**
     * Respuesta con error
     */
    protected function error(string $message = 'Error', int $code = 400, $errors = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
            'status_code' => $code,
        ], $code);
    }

    /**
     * Respuesta personalizada (ej: login con token)
     */
    protected function respond(bool $success, $token = null, $data = null, string $message = null, int $code = 200): JsonResponse
    {
        return response()->json([
            'success' => $success,
            'token'   => $token,
            'data'    => $data,
            'message' => $message,
            'status_code' => $code,
        ], $code);
    }

 
}