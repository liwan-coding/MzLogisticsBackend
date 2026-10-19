<?php

namespace App\Services\Push;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class FcmClient
{
    /**
     * @param  list<array{id: int, token: string, title: string, body: string, data: array<string, string>}>  $messages
     * @return array<int, FcmDelivery>
     */
    public function sendBatch(array $messages): array
    {
        $results = [];
        foreach ($messages as $message) {
            $results[$message['id']] = FcmDelivery::Failed;
        }

        if ($messages === []) {
            return $results;
        }

        $projectId = (string) config('services.firebase.project_id');
        if (! preg_match('/^[a-z0-9-]+$/', $projectId)) {
            Log::warning('FCM project id is missing.');

            return $results;
        }

        $accessToken = $this->accessToken();
        if ($accessToken === null) {
            return $results;
        }

        foreach (array_chunk($messages, 20) as $chunk) {
            $responses = Http::pool(function (Pool $pool) use ($chunk, $accessToken, $projectId) {
                foreach ($chunk as $message) {
                    $pool->as((string) $message['id'])
                        ->withToken($accessToken)
                        ->connectTimeout(3)
                        ->timeout(8)
                        ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                            'message' => [
                                'token' => $message['token'],
                                'notification' => [
                                    'title' => $message['title'],
                                    'body' => $message['body'],
                                ],
                                'data' => $message['data'],
                                'android' => [
                                    'priority' => 'HIGH',
                                    'notification' => [
                                        'channel_id' => 'movex_alerts',
                                    ],
                                ],
                            ],
                        ]);
                }
            });

            foreach ($responses as $id => $response) {
                $deviceId = (int) $id;
                if ($response instanceof Throwable) {
                    Log::warning('FCM request failed.', [
                        'device_token_id' => $deviceId,
                        'error' => $response->getMessage(),
                    ]);
                    $results[$deviceId] = FcmDelivery::Failed;

                    continue;
                }

                if (! $response instanceof Response) {
                    $results[$deviceId] = FcmDelivery::Failed;

                    continue;
                }

                if ($response->successful()) {
                    $results[$deviceId] = FcmDelivery::Sent;

                    continue;
                }

                if ($this->isStale($response)) {
                    $results[$deviceId] = FcmDelivery::Stale;

                    continue;
                }

                Log::warning('FCM rejected a message.', [
                    'device_token_id' => $deviceId,
                    'status' => $response->status(),
                    'error' => $response->json('error.status'),
                ]);
                $results[$deviceId] = FcmDelivery::Failed;
            }
        }

        return $results;
    }

    private function accessToken(): ?string
    {
        $cached = Cache::get('firebase.messaging.access_token');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $credentials = $this->credentials();
        if ($credentials === null) {
            return null;
        }

        $jwt = $this->jwt($credentials);
        if ($jwt === null) {
            return null;
        }

        $response = Http::asForm()
            ->connectTimeout(3)
            ->timeout(8)
            ->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            Log::warning('FCM access token request failed.', [
                'status' => $response->status(),
            ]);

            return null;
        }

        Cache::put('firebase.messaging.access_token', $token, now()->addMinutes(50));

        return $token;
    }

    /**
     * @return array{client_email: string, private_key: string}|null
     */
    private function credentials(): ?array
    {
        $path = (string) config('services.firebase.credentials');
        if ($path === '' || ! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded) || ! is_string($decoded['client_email'] ?? null) || ! is_string($decoded['private_key'] ?? null)) {
            Log::warning('FCM credentials file is unreadable.');

            return null;
        }

        return [
            'client_email' => $decoded['client_email'],
            'private_key' => $decoded['private_key'],
        ];
    }

    /**
     * @param  array{client_email: string, private_key: string}  $credentials
     */
    private function jwt(array $credentials): ?string
    {
        $header = $this->base64([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ]);
        $now = time();
        $payload = $this->base64([
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]);
        $unsigned = $header.'.'.$payload;

        $signed = openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256);
        if (! $signed) {
            Log::warning('FCM credentials could not be signed.');

            return null;
        }

        return $unsigned.'.'.$this->base64Raw($signature);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function base64(array $data): string
    {
        return $this->base64Raw((string) json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    private function base64Raw(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function isStale(Response $response): bool
    {
        if ($response->status() === 404) {
            return true;
        }

        $details = $response->json('error.details');
        if (! is_array($details)) {
            return false;
        }

        foreach ($details as $detail) {
            if (is_array($detail) && ($detail['errorCode'] ?? null) === 'UNREGISTERED') {
                return true;
            }
        }

        return false;
    }
}
