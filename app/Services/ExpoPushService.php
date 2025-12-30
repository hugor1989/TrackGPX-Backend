<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;

class ExpoPushService
{
    public static function send($token, $title, $body, array $data = [])
    {
        if (!$token) return;

        Http::post('https://exp.host/--/api/v2/push/send', [
            'to' => $token,
            'title' => $title,
            'body' => $body,
            'sound' => 'default',
            'priority' => 'high',
            'data' => $data,
        ]);
    }
}
