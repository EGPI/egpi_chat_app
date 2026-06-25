<?php

namespace App\Services;

use App\Models\UserDevice;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class FirebaseCloudMessagingService
{
    public function send(UserDevice $device, array $message): bool
    {
        if (! $this->isConfigured()) {
            Log::info('Skipping FCM push because Firebase is not configured.');

            return false;
        }

        try {
            $response = Http::withToken($this->accessToken())
                ->acceptJson()
                ->post($this->sendUrl(), [
                    'message' => array_merge($message, [
                        'token' => $device->fcm_token,
                    ]),
                ]);
        } catch (Throwable $exception) {
            Log::warning('Failed to send FCM push notification.', [
                'user_device_id' => $device->id,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }

        if ($response->successful()) {
            return true;
        }

        if ($this->isInvalidTokenResponse($response)) {
            $device->update([
                'is_active' => false,
                'revoked_at' => now(),
            ]);
        }

        Log::warning('FCM push notification was rejected.', [
            'user_device_id' => $device->id,
            'status' => $response->status(),
            'body' => $response->json(),
        ]);

        return false;
    }

    private function isConfigured(): bool
    {
        return filled(config('services.firebase.credentials'))
            && filled($this->projectId());
    }

    private function accessToken(): string
    {
        return Cache::remember('firebase.access_token', now()->addMinutes(50), function (): string {
            $credentials = $this->credentials();
            $tokenUri = $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token';
            $now = time();

            $jwt = $this->encodeJwt(
                ['alg' => 'RS256', 'typ' => 'JWT'],
                [
                    'iss' => $credentials['client_email'],
                    'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                    'aud' => $tokenUri,
                    'iat' => $now,
                    'exp' => $now + 3600,
                ],
                $credentials['private_key']
            );

            $response = Http::asForm()->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if (! $response->successful() || ! $response->json('access_token')) {
                throw new RuntimeException('Unable to fetch Firebase access token.');
            }

            return $response->json('access_token');
        });
    }

    private function credentials(): array
    {
        $path = config('services.firebase.credentials');

        if (! is_string($path) || ! is_file($path)) {
            throw new RuntimeException('Firebase credentials file was not found.');
        }

        $credentials = json_decode((string) file_get_contents($path), true);

        if (! is_array($credentials)
            || empty($credentials['client_email'])
            || empty($credentials['private_key'])
        ) {
            throw new RuntimeException('Firebase credentials file is invalid.');
        }

        return $credentials;
    }

    private function sendUrl(): string
    {
        return 'https://fcm.googleapis.com/v1/projects/'.$this->projectId().'/messages:send';
    }

    private function projectId(): ?string
    {
        return config('services.firebase.project_id')
            ?: ($this->safeCredentialProjectId());
    }

    private function safeCredentialProjectId(): ?string
    {
        try {
            return $this->credentials()['project_id'] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    private function encodeJwt(array $header, array $claims, string $privateKey): string
    {
        $segments = [
            $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR)),
            $this->base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR)),
        ];

        $signingInput = implode('.', $segments);

        if (! openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign Firebase JWT.');
        }

        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function isInvalidTokenResponse(Response $response): bool
    {
        $status = $response->json('error.status');
        $message = $response->json('error.message');

        return in_array($status, ['NOT_FOUND', 'INVALID_ARGUMENT'], true)
            || (is_string($message) && str_contains($message, 'registration token'));
    }
}
