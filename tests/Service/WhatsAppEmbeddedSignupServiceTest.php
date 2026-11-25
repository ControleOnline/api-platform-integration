<?php

namespace ControleOnline\Integration\Tests\Service;

use ControleOnline\Entity\Connection;
use ControleOnline\Entity\People;
use ControleOnline\Entity\PeopleDomain;
use ControleOnline\Entity\Phone;
use ControleOnline\Service\ConfigService;
use ControleOnline\Service\DomainService;
use ControleOnline\Service\WhatsAppEmbeddedSignupService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WhatsAppEmbeddedSignupServiceTest extends TestCase
{
    private array $requests = [];
    private array $persisted = [];

    private function service(array $responses, array $existingConnections = []): WhatsAppEmbeddedSignupService
    {
        $this->requests = [];
        $this->persisted = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses) {
            $this->requests[] = [$method, $url, $options['body'] ?? null];

            return array_shift($responses);
        });

        $connections = $this->createMock(EntityRepository::class);
        $connections->method('findBy')->willReturn($existingConnections);
        $phones = $this->createMock(EntityRepository::class);
        $phones->method('findOneBy')->willReturn(null);

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getRepository')->willReturnCallback(
            fn(string $class) => $class === Phone::class ? $phones : $connections
        );
        $manager->method('persist')->willReturnCallback(function ($entity) {
            $this->persisted[] = $entity;
        });

        $config = $this->createMock(ConfigService::class);
        $config->method('getConfig')->willReturnCallback(fn($people, string $key) => match ($key) {
            WhatsAppEmbeddedSignupService::CONFIG_APP_ID => '1107825551894271',
            WhatsAppEmbeddedSignupService::CONFIG_APP_SECRET => 'secret',
            WhatsAppEmbeddedSignupService::CONFIG_SIGNUP_CONFIG_ID => '1470173301907627',
            default => null,
        });
        $peopleDomain = $this->createMock(PeopleDomain::class);
        $peopleDomain->method('getPeople')->willReturn(new People());
        $domain = $this->createMock(DomainService::class);
        $domain->method('getPeopleDomain')->willReturn($peopleDomain);

        return new WhatsAppEmbeddedSignupService($manager, $http, $config, $domain);
    }

    private static function json(array $data, int $code = 200): MockResponse
    {
        return new MockResponse(json_encode($data), ['http_code' => $code]);
    }

    public function testClientConfigNeverExposesTheSecret(): void
    {
        $config = $this->service([])->getClientConfig();

        self::assertSame('1107825551894271', $config['app_id']);
        self::assertSame('1470173301907627', $config['config_id']);
        self::assertArrayNotHasKey('app_secret', $config);
    }

    public function testCoexistenceCreatesConnectionWithoutRegisteringTheNumber(): void
    {
        $service = $this->service([
            self::json(['access_token' => 'business-token']),
            self::json(['success' => true]),
            self::json(['display_phone_number' => '+55 11 5555-5159', 'verified_name' => 'Controle Online']),
            self::json(['success' => true]),
            self::json(['success' => true]),
        ]);

        $connection = $service->complete(new People(), [
            'code' => 'abc', 'waba_id' => '111', 'phone_number_id' => '222', 'coexistence' => true, 'type' => 'relationship',
        ]);

        $paths = array_map(fn($r) => $r[0] . ' ' . parse_url($r[1], PHP_URL_PATH), $this->requests);
        self::assertSame([
            'GET /v22.0/oauth/access_token',
            'POST /v22.0/111/subscribed_apps',
            'GET /v22.0/222',
            'POST /v22.0/222/smb_app_data',
            'POST /v22.0/222/smb_app_data',
        ], $paths);

        $config = $connection->getChannelConfigArray();
        self::assertSame('business-token', $config['access_token']);
        self::assertSame('222', $config['phone_number_id']);
        self::assertSame('coexistence', $config['onboarding']);
        self::assertSame(Connection::CHANNEL_WHATSAPP_OFFICIAL, $connection->getChannel());
        self::assertSame('relationship', $connection->getType());
        self::assertSame(55555159, $connection->getPhone()->getPhone());
    }

    public function testNewNumberIsRegisteredWithAPin(): void
    {
        $service = $this->service([
            self::json(['access_token' => 'business-token']),
            self::json(['success' => true]),
            self::json(['display_phone_number' => '+55 11 3136-0353', 'verified_name' => 'Controle Online']),
            self::json(['success' => true]),
        ]);

        $connection = $service->complete(new People(), ['code' => 'abc', 'waba_id' => '111', 'phone_number_id' => '333']);

        self::assertSame('/v22.0/333/register', parse_url($this->requests[3][1], PHP_URL_PATH));
        self::assertMatchesRegularExpression('/^\d{6}$/', $connection->getChannelConfigArray()['pin']);
    }

    public function testExistingConnectionOfTheSameNumberIsReused(): void
    {
        $existing = (new Connection())->setChannelConfigArray(['phone_number_id' => '222']);
        $existing->setChannel(Connection::CHANNEL_WHATSAPP_OFFICIAL);
        $existing->setName('Comercial');

        $service = $this->service([
            self::json(['access_token' => 'new-token']),
            self::json(['success' => true]),
            self::json(['display_phone_number' => '+55 11 5555-5159']),
            self::json(['success' => true]),
            self::json(['success' => true]),
        ], [$existing]);

        $connection = $service->complete(new People(), [
            'code' => 'abc', 'waba_id' => '111', 'phone_number_id' => '222', 'coexistence' => 'true',
        ]);

        self::assertSame($existing, $connection);
        self::assertSame('Comercial', $connection->getName());
        self::assertSame('new-token', $connection->getChannelConfigArray()['access_token']);
    }

    public function testMetaErrorsAreReported(): void
    {
        $service = $this->service([
            self::json(['error' => ['message' => 'Invalid verification code format.']], 400),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Meta: Invalid verification code format.');
        $service->complete(new People(), ['code' => 'bad', 'waba_id' => '111', 'phone_number_id' => '222']);
    }

    public function testMissingIdsAreRejectedBeforeCallingMeta(): void
    {
        $service = $this->service([]);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $service->complete(new People(), ['code' => 'abc']);
        } finally {
            self::assertSame([], $this->requests);
        }
    }
}
