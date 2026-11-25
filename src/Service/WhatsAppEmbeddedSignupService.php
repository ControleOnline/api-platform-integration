<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Connection;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Phone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Meta WhatsApp Embedded Signup: the MANAGER opens the Facebook login (FB.login with the
 * Embedded Signup configuration), Meta returns a short-lived code plus the WABA and phone
 * number ids, and this service turns them into a whatsapp_official connection.
 *
 * - "coexistence": a number already used on the WhatsApp Business app keeps working on the
 *   phone; the owner authorizes it by scanning a QR code in the app (no SMS / call code).
 *   Meta forbids calling /register for these numbers; contacts and history are synced instead.
 * - "new number": Meta verifies the number inside the popup; it must then be registered.
 *
 * App id, app secret and configuration id live in the config table of the API domain company
 * (same company as the webhook secrets), env vars are a fallback.
 */
class WhatsAppEmbeddedSignupService
{
    public const CONFIG_APP_ID = 'whatsapp-cloud-app-id';
    public const CONFIG_APP_SECRET = 'whatsapp-cloud-app-secret';
    public const CONFIG_SIGNUP_CONFIG_ID = 'whatsapp-embedded-signup-config-id';
    public const GRAPH_VERSION = 'v22.0';
    private const GRAPH = 'https://graph.facebook.com/';

    public function __construct(
        private EntityManagerInterface $manager,
        private HttpClientInterface $httpClient,
        private ConfigService $configService,
        private DomainService $domainService,
    ) {
    }

    /** Public data the browser needs to open the popup (never the secret). */
    public function getClientConfig(): array
    {
        return [
            'app_id' => $this->setting(self::CONFIG_APP_ID, 'WHATSAPP_CLOUD_APP_ID'),
            'config_id' => $this->setting(self::CONFIG_SIGNUP_CONFIG_ID, 'WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID'),
            'graph_version' => self::GRAPH_VERSION,
        ];
    }

    /**
     * @param array{code?:string,waba_id?:string,phone_number_id?:string,coexistence?:bool,type?:string,connection?:int|string} $payload
     */
    public function complete(People $company, array $payload): Connection
    {
        $code = trim((string) ($payload['code'] ?? ''));
        $wabaId = preg_replace('/\D/', '', (string) ($payload['waba_id'] ?? ''));
        $phoneNumberId = preg_replace('/\D/', '', (string) ($payload['phone_number_id'] ?? ''));
        $coexistence = filter_var($payload['coexistence'] ?? false, FILTER_VALIDATE_BOOL);

        if ($code === '' || $wabaId === '' || $phoneNumberId === '') {
            throw new \InvalidArgumentException('O Meta não devolveu o código, a conta ou o número. Refaça a conexão.');
        }

        $token = $this->exchangeCode($code);
        // Without this subscription Meta does not deliver this WABA's messages to our webhook.
        $this->graph('POST', $wabaId . '/subscribed_apps', $token);
        $number = $this->graph('GET', $phoneNumberId . '?fields=display_phone_number,verified_name', $token);

        $config = [
            'access_token' => $token,
            'phone_number_id' => $phoneNumberId,
            'waba_id' => $wabaId,
            'api_version' => self::GRAPH_VERSION,
            'onboarding' => $coexistence ? 'coexistence' : 'embedded_signup',
        ];

        if ($coexistence) {
            $this->syncBusinessApp($phoneNumberId, $token);
        } else {
            $config['pin'] = (string) random_int(100000, 999999);
            $this->graph('POST', $phoneNumberId . '/register', $token, [
                'messaging_product' => 'whatsapp',
                'pin' => $config['pin'],
            ]);
        }

        $phone = $this->discoveryPhone($company, (string) ($number['display_phone_number'] ?? ''));
        $connection = $this->findConnection($company, $payload['connection'] ?? null, $phone, $phoneNumberId);
        if (!$connection) {
            $connection = (new Connection())->setPeople($company);
            $connection->setName(mb_substr('WhatsApp ' . ($number['verified_name'] ?? $phoneNumberId), 0, 50));
        }

        $connection->setChannel(Connection::CHANNEL_WHATSAPP_OFFICIAL);
        $connection->setChannelConfigArray($config);
        if ($phone) {
            $connection->setPhone($phone);
        }
        if (!empty($payload['type'])) {
            $connection->setType((string) $payload['type']);
        }

        $this->manager->persist($connection);
        $this->manager->flush();

        return $connection;
    }

    private function exchangeCode(string $code): string
    {
        $appId = $this->setting(self::CONFIG_APP_ID, 'WHATSAPP_CLOUD_APP_ID');
        $secret = $this->setting(self::CONFIG_APP_SECRET, 'WHATSAPP_CLOUD_APP_SECRET');
        if ($appId === '' || $secret === '') {
            throw new \RuntimeException('App do Meta não configurado (whatsapp-cloud-app-id / whatsapp-cloud-app-secret).');
        }

        $data = $this->graph('GET', 'oauth/access_token?' . http_build_query([
            'client_id' => $appId,
            'client_secret' => $secret,
            'code' => $code,
        ]));
        $token = (string) ($data['access_token'] ?? '');
        if ($token === '') {
            throw new \RuntimeException('O Meta não devolveu o token de acesso.');
        }

        return $token;
    }

    /** Coexistence: bring the app contacts and chat history into the API (non-fatal, Meta allows it once). */
    private function syncBusinessApp(string $phoneNumberId, string $token): void
    {
        foreach (['smb_app_state_sync', 'history'] as $syncType) {
            try {
                $this->graph('POST', $phoneNumberId . '/smb_app_data', $token, [
                    'messaging_product' => 'whatsapp',
                    'sync_type' => $syncType,
                ]);
            } catch (\Throwable) {
                // The connection works without the sync; only the old history is missing.
            }
        }
    }

    private function findConnection(People $company, mixed $connectionId, ?Phone $phone, string $phoneNumberId): ?Connection
    {
        $repository = $this->manager->getRepository(Connection::class);

        $id = (int) preg_replace('/\D/', '', (string) $connectionId);
        if ($id > 0) {
            $connection = $repository->find($id);
            if (!$connection || (int) $connection->getPeople()?->getId() !== (int) $company->getId()) {
                throw new \InvalidArgumentException('Conexão não encontrada para esta empresa.');
            }

            return $connection;
        }

        foreach ($repository->findBy(['people' => $company, 'channel' => Connection::CHANNEL_WHATSAPP_OFFICIAL]) as $connection) {
            $samePhone = $phone && $connection->getPhone()
                && (int) $connection->getPhone()->getId() === (int) $phone->getId();
            $sameNumberId = ($connection->getChannelConfigArray()['phone_number_id'] ?? null) === $phoneNumberId;
            if ($samePhone || $sameNumberId) {
                return $connection;
            }
        }

        return null;
    }

    private function discoveryPhone(People $company, string $displayPhone): ?Phone
    {
        $digits = preg_replace('/\D/', '', $displayPhone);
        if (strlen($digits) < 12) {
            return null;
        }

        $criteria = [
            'ddi' => (int) substr($digits, 0, 2),
            'ddd' => (int) substr($digits, 2, 2),
            'phone' => (int) substr($digits, 4),
        ];
        $repository = $this->manager->getRepository(Phone::class);
        $phone = $repository->findOneBy($criteria + ['people' => $company]) ?? $repository->findOneBy($criteria);
        if ($phone instanceof Phone) {
            return $phone;
        }

        $phone = new Phone();
        $phone->setDdi($criteria['ddi']);
        $phone->setDdd($criteria['ddd']);
        $phone->setPhone($criteria['phone']);
        $phone->setPeople($company);
        $this->manager->persist($phone);

        return $phone;
    }

    private function graph(string $method, string $path, ?string $token = null, ?array $body = null): array
    {
        $options = [];
        if ($token) {
            $options['headers'] = ['Authorization' => 'Bearer ' . $token];
        }
        if ($body !== null) {
            $options['json'] = $body;
        }

        $response = $this->httpClient->request($method, self::GRAPH . self::GRAPH_VERSION . '/' . $path, $options);
        $data = $response->toArray(false);
        if ($response->getStatusCode() >= 400) {
            $error = $data['error']['error_user_msg'] ?? $data['error']['message'] ?? 'erro desconhecido';
            throw new \RuntimeException('Meta: ' . $error);
        }

        return $data;
    }

    private function setting(string $configKey, string $envKey): string
    {
        try {
            $company = $this->domainService->getPeopleDomain()->getPeople();
            $value = trim(trim((string) $this->configService->getConfig($company, $configKey)), '"');
            if ($value !== '') {
                return $value;
            }
        } catch (\Throwable) {
            // No domain/config available: fall back to the environment.
        }

        return trim((string) ($_ENV[$envKey] ?? $_SERVER[$envKey] ?? getenv($envKey) ?: ''));
    }
}
