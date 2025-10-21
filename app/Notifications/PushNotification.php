<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Kreait\Firebase\Messaging\Notification as FirebaseNotification;
use Kreait\Firebase\Messaging\CloudMessage;
use App\Channels\FirebaseChannel;


class PushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $body,
        public array $data = []
    ) {}

    public function via(object $notifiable): array
    {
       return [FirebaseChannel::class];
    }

    public function toFirebase(object $notifiable)
    {
        // Obtener tokens FCM del usuario
        $tokens = $notifiable->fcmTokens()->pluck('token')->toArray();

        if (empty($tokens)) {
            return;
        }

        $message = CloudMessage::new()
            ->withNotification(FirebaseNotification::create($this->title, $this->body))
            ->withData($this->data);

        return [
            'tokens' => $tokens,
            'message' => $message
        ];
    }
}
