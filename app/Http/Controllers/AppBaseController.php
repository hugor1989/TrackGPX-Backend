<?php

namespace App\Http\Controllers;

use App\Utils\ResponseUtil;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Response;


class AppBaseController extends Controller
{
    public function sendResponse($result, $message): JsonResponse
    {
        return Response::json(ResponseUtil::makeResponse($message, $result));
    }

    public function sendError($error, $code = 422): JsonResponse
    {
        return Response::json(ResponseUtil::makeError($error), $code);
    }

    public function sendSuccess($message): JsonResponse
    {
        return Response::json([
            'success' => true,
            'message' => $message,
        ], 200);
    }


    //Estructura de errores y respuestas
    protected function respond($success, $token= null, $data = null, $message = null, $statusCode = 200)
    {
        return response()->json([
            'success' => $success,
            'token' => $token,
            'data' => $data,
            'message' => $message,
            'status_code' => $statusCode
        ], $statusCode);
    }

    public static function error($message = 'Error', $statusCode = 400)
    {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => $message,
            'status_code' => $statusCode
        ], $statusCode);
    }

 
}