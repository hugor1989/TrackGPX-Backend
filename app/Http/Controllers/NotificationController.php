<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\PushNotification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    public function sendToUser(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'title' => 'required|string',
            'body' => 'required|string',
            'data' => 'nullable|array'
        ]);

        $user->notify(new PushNotification(
            $request->title,
            $request->body,
            $request->data ?? []
        ));

        return response()->json([
            'message' => 'Notificación enviada exitosamente'
        ]);
    }

    public function sendToAllUsers(Request $request): JsonResponse
    {
        $request->validate([
            'title' => 'required|string',
            'body' => 'required|string',
            'data' => 'nullable|array'
        ]);

        $users = User::has('fcmTokens')->get();

        foreach ($users as $user) {
            $user->notify(new PushNotification(
                $request->title,
                $request->body,
                $request->data ?? []
            ));
        }

        return response()->json([
            'message' => 'Notificación enviada a todos los usuarios'
        ]);
    }
}