<?php
// app/Http/Controllers/NotificationController.php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    /**
     * Obtener todas las notificaciones del usuario
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        
        $notifications = Notification::where('customer_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'notifications' => $notifications,
        ]);
    }

    /**
     * Obtener notificaciones no leídas
     */
    public function unread(Request $request)
    {
        $user = Auth::user();
        
        $notifications = Notification::where('customer_id', $user->id)
            ->unread()
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'count' => $notifications->count(),
            'notifications' => $notifications,
        ]);
    }

    /**
     * Contar notificaciones no leídas
     */
    public function unreadCount(Request $request)
    {
        $user = Auth::user();
        
        $count = Notification::where('customer_id', $user->id)
            ->unread()
            ->count();

        return response()->json([
            'success' => true,
            'count' => $count,
        ]);
    }

    /**
     * Marcar una notificación como leída
     */
    public function markAsRead(Request $request, $id)
    {
        $user = Auth::user();
        
        $notification = Notification::where('customer_id', $user->id)
            ->findOrFail($id);

        $notification->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'Notificación marcada como leída',
        ]);
    }

    /**
     * Marcar todas como leídas
     */
    public function markAllAsRead(Request $request)
    {
        $user = Auth::user();
        
        Notification::where('customer_id', $user->id)
            ->unread()
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Todas las notificaciones marcadas como leídas',
        ]);
    }

    /**
     * Eliminar una notificación
     */
    public function destroy(Request $request, $id)
    {
        $user = Auth::user();
        
        $notification = Notification::where('customer_id', $user->id)
            ->findOrFail($id);

        $notification->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notificación eliminada',
        ]);
    }

    /**
     * Eliminar todas las notificaciones
     */
    public function destroyAll(Request $request)
    {
        $user = Auth::user();
        
        Notification::where('customer_id', $user->id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Todas las notificaciones eliminadas',
        ]);
    }
}