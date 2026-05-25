<?php

namespace ControleOnline\Service;

use GuzzleHttp\Client;
use Google\Auth\Credentials\ServiceAccountCredentials;

class FirebaseCloudMessagingService
{
    private const FCM_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private const FCM_ENDPOINT = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';

    private ?string $accessToken = null;
    private int $accessTokenExpiresAt = 0;
    private ?array $serviceAccount = null;

    public function __construct(
        private string $projectId,
        private string $serviceAccountSource,
        private string $projectDir,
    ) {}

    public function sendNotificationToToken(
        string $deviceToken,
        string $title,
        string $body,
        array $data = [],
        array $androidNotification = []
    ): array {
        $normalizedToken = trim($deviceToken);
        if ($normalizedToken === '') {
            throw new \InvalidArgumentException('Device token is required for FCM delivery.');
        }

        $payload = [
            'message' => [
                'token' => $normalizedToken,
                'notification' => [
                    'title' => trim($title),
                    'body' => trim($body),
                ],
                'data' => $this->normalizeDataPayload($data),
                'android' => [
                    'priority' => 'HIGH',
                    'notification' => array_replace(
                        [
                            'channel_id' => 'manager-orders-push-caixa-v1',
                            'sound' => 'caixa',
                            'default_sound' => false,
                        ],
                        array_filter(
                            $androidNotification,
                            static fn($value) => $value !== null && $value !== ''
                        ),
                    ),
                ],
            ],
        ];

        $response = $this->createHttpClient()->request(
            'POST',
            sprintf(self::FCM_ENDPOINT, $this->resolveProjectId()),
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getAccessToken(),
                    'Content-Type' => 'application/json; charset=UTF-8',
                ],
                'json' => $payload,
            ]
        );

        $bodyResponse = json_decode((string) $response->getBody(), true);

        return is_array($bodyResponse) ? $bodyResponse : [];
    }

    private function createHttpClient(): Client
    {
        return new Client([
            'timeout' => 15,
        ]);
    }

    private function getAccessToken(): string
    {
        if (
            $this->accessToken &&
            $this->accessTokenExpiresAt > time()
        ) {
            return $this->accessToken;
        }

        $credentials = new ServiceAccountCredentials(
            self::FCM_SCOPE,
            $this->resolveServiceAccount()
        );

        $tokenData = $credentials->fetchAuthToken();
        $accessToken = trim((string) ($tokenData['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new \RuntimeException('Unable to resolve Firebase access token.');
        }

        $expiresIn = max(60, (int) ($tokenData['expires_in'] ?? 3600));
        $this->accessToken = $accessToken;
        $this->accessTokenExpiresAt = time() + $expiresIn - 60;

        return $this->accessToken;
    }

    private function resolveServiceAccount(): array
    {
        if ($this->serviceAccount !== null) {
            return $this->serviceAccount;
        }

        $source = trim($this->serviceAccountSource);
        if ($source === '') {
            throw new \RuntimeException('FIREBASE_SERVICE_ACCOUNT is not configured.');
        }

        $json = $this->readServiceAccountJson($source);
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid Firebase service account payload.');
        }

        $this->serviceAccount = $decoded;

        return $this->serviceAccount;
    }

    private function readServiceAccountJson(string $source): string
    {
        $candidates = [
            $source,
            $this->projectDir . DIRECTORY_SEPARATOR . ltrim($source, DIRECTORY_SEPARATOR),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && is_file($candidate)) {
                $content = file_get_contents($candidate);
                if (is_string($content) && trim($content) !== '') {
                    return $content;
                }
            }
        }

        if ($source !== '' && ($source[0] === '{' || $source[0] === '[')) {
            return $source;
        }

        if (is_string($source) && is_file($source)) {
            $content = file_get_contents($source);
            if (is_string($content) && trim($content) !== '') {
                return $content;
            }
        }

        throw new \RuntimeException(sprintf(
            'Unable to load Firebase service account from "%s".',
            $source
        ));
    }

    private function resolveProjectId(): string
    {
        $projectId = trim($this->projectId);
        if ($projectId !== '') {
            return $projectId;
        }

        $serviceAccount = $this->resolveServiceAccount();
        $projectId = trim((string) ($serviceAccount['project_id'] ?? ''));
        if ($projectId === '') {
            throw new \RuntimeException('Firebase project id is required.');
        }

        return $projectId;
    }

    private function normalizeDataPayload(array $data): array
    {
        $normalizedData = [];

        foreach ($data as $key => $value) {
            $normalizedKey = trim((string) $key);
            if ($normalizedKey === '') {
                continue;
            }

            if (is_bool($value)) {
                $normalizedData[$normalizedKey] = $value ? 'true' : 'false';
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $normalizedData[$normalizedKey] = trim((string) ($value ?? ''));
                continue;
            }

            $normalizedData[$normalizedKey] = json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        return $normalizedData;
    }
}
