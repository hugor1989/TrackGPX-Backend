<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class APICrmWhatSapp
{
    protected $client;
    protected $apiKey;
    protected $urlBase;

    public function __construct()
    {
        $this->apiKey = env('WHAT_API_KEY'); // Asegúrate de configurar esta variable en tu .env
        $this->urlBase = env('WHAT_API_URL_BASE'); // Configura esta variable en tu .env
        $this->client = new Client([
            'base_uri' => $this->urlBase,
            'timeout'  => 10.0,
            'headers'  => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ],
        ]);
    }

    public function sendMessage($to, $message)
    {
        try {
            $response = $this->client->post('/api/messages/send', [
                'json' => [
                    'number' => $to,
                    'body' => $message,
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            // Manejar errores de la solicitud
            return ['error' => $e->getMessage()];
        }
    }
}
