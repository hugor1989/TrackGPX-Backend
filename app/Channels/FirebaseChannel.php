<?php

namespace App\Channels;

use Illuminate\Notifications\Notification;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Laravel\Firebase\Facades\FirebaseMessaging;
use Illuminate\Support\Facades\Log;


class FirebaseChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $firebaseMessage = $notification->toFirebase($notifiable);

        if (!$firebaseMessage) {
            return;
        }

        $tokens = $firebaseMessage['tokens'];
        $message = $firebaseMessage['message'];

        try {
            $report = FirebaseMessaging::sendMulticast($message, $tokens);
            
            // Eliminar tokens inválidos
            $this->removeInvalidTokens($report, $notifiable);
            
        } catch (\Exception $e) {
            Log::error('Error sending FCM notification: ' . $e->getMessage());
        }
    }

    private function removeInvalidTokens(MulticastSendReport $report, object $notifiable): void
    {
        $invalidTokens = $report->invalidTokens();
        
        if (!empty($invalidTokens)) {
            $notifiable->fcmTokens()
                ->whereIn('token', $invalidTokens)
                ->delete();
        }
    }
}