<?php

namespace ControleOnline\Service;

use ControleOnline\Service\AddressService;
use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductGroup;
use ControleOnline\Entity\ProductGroupProduct;
use ControleOnline\Entity\ProductUnity;
use ControleOnline\Entity\Status;
use ControleOnline\Entity\User;
use ControleOnline\Service\Client\WebsocketClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use ControleOnline\Service\LoggerService;
use DateTime;
use Exception;


class DefaultFoodService
{

    protected static $foodPeople;
    protected static $logger;
    protected static $app;


    public function __construct(
        protected EntityManagerInterface $entityManager,
        protected LoggerService $loggerService,
        protected HttpClientInterface $httpClient,
        protected ExtraDataService $extraDataService,
        protected PeopleService $peopleService,
        protected StatusService $statusService,
        protected AddressService $addressService,
        protected ProductService $productService,
        protected WebsocketClient $websocketClient,
        protected ConfigService $configService,
        protected DeviceService $deviceService,
        protected OrderPrintService $orderPrintService,
        protected InvoiceService $invoiceService,
        protected WalletService $walletService,
        protected OrderProductService $orderProductService,
        protected ProductGroupService $productGroupService
    ) {}


    protected function printOrder(Order $order)
    {
        $devices = $this->configService->getConfig($order->getProvider(), $order->getApp() . '-devices', true);

        if ($devices)
            $devices = $this->deviceService->findDevices($devices);

        foreach ($devices as $device)
            $this->orderPrintService->generatePrintData($order, $device, ['sound' => $order->getApp()]);
    }


    protected function discoveryFoodCode(object $entity, string $code, ?string $type = 'code')
    {
        return $this->extraDataService->discoveryExtraData($entity, self::$app, $type, $code);
    }

    protected function discoveryFoodCodeByEntity(object $entity)
    {
        $entityId = method_exists($entity, 'getId') ? (int) $entity->getId() : 0;
        if ($entityId <= 0) {
            return null;
        }

        $entityName = null;
        if (method_exists($entity, 'getEntityName')) {
            $entityName = trim((string) $entity->getEntityName());
        }
        if ($entityName === null || $entityName === '') {
            $entityName = (new \ReflectionClass($entity))->getShortName();
        }

        $sql = <<<SQL
            SELECT ed.data_value
            FROM extra_data ed
            INNER JOIN extra_fields ef ON ef.id = ed.extra_fields_id
            WHERE ef.context = :context
              AND LOWER(ed.entity_name) = LOWER(:entityName)
              AND ed.entity_id = :entityId
              AND ef.field_name IN ('id', 'code')
            ORDER BY CASE ef.field_name WHEN 'id' THEN 0 ELSE 1 END, ed.id DESC
            LIMIT 1
        SQL;

        $value = $this->entityManager->getConnection()->fetchOne($sql, [
            'context' => self::$app,
            'entityName' => $entityName,
            'entityId' => (string) $entityId,
        ]);

        if ($value === false || $value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        return $normalized !== '' ? $normalized : null;
    }


    protected function createOrder(
        People $client,
        People $provider,
        float $price,
        Status $status,
        array $otherInformations
    ): Order {
        $order = new Order();
        $order->setClient($client);
        $order->setProvider($provider);
        $order->setPayer($client);
        $order->setStatus($status);
        $order->setApp(self::$app);
        $order->setOrderType('sale');
        $order->addOtherInformations(self::$app, $otherInformations);

        $order->setPrice($price);

        $this->entityManager->persist($order);
        return $order;
    }

    protected function addLog(string $type, $log)
    {
        echo $log;
        self::$logger->$type($log);
    }

    protected function resolvePublicApiEntrypoint(): string
    {
        $baseUrl = $_ENV['PUBLIC_API_ENTRYPOINT']
            ?? $_ENV['API_ENTRYPOINT']
            ?? $_ENV['API_BASE_URL']
            ?? $_SERVER['PUBLIC_API_ENTRYPOINT']
            ?? $_SERVER['API_ENTRYPOINT']
            ?? $_SERVER['API_BASE_URL']
            ?? getenv('PUBLIC_API_ENTRYPOINT')
            ?? getenv('API_ENTRYPOINT')
            ?? getenv('API_BASE_URL')
            ?: 'https://api.controleonline.com';

        $baseUrl = trim((string) $baseUrl);
        if ($baseUrl === '') {
            $baseUrl = 'https://api.controleonline.com';
        }

        if (!preg_match('#^https?://#i', $baseUrl)) {
            $baseUrl = 'https://' . ltrim($baseUrl, '/');
        }

        return rtrim($baseUrl, '/');
    }

    protected function resolvePublicAppDomain(): string
    {
        $domain = $_ENV['PUBLIC_APP_DOMAIN']
            ?? $_ENV['APP_DOMAIN']
            ?? $_ENV['ADMIN_APP_DOMAIN']
            ?? $_SERVER['PUBLIC_APP_DOMAIN']
            ?? $_SERVER['APP_DOMAIN']
            ?? $_SERVER['ADMIN_APP_DOMAIN']
            ?? getenv('PUBLIC_APP_DOMAIN')
            ?? getenv('APP_DOMAIN')
            ?? getenv('ADMIN_APP_DOMAIN')
            ?: 'admin.controleonline.com';

        $domain = trim((string) $domain);
        if ($domain === '') {
            return 'admin.controleonline.com';
        }

        $host = parse_url($domain, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return $host;
        }

        if (!str_contains($domain, '/')) {
            return $domain;
        }

        return 'admin.controleonline.com';
    }

    protected function buildPublicFileDownloadUrl(mixed $fileId): ?string
    {
        if ($fileId === null || $fileId === '') {
            return null;
        }

        $normalizedFileId = preg_replace('/\D+/', '', (string) $fileId);
        if ($normalizedFileId === '') {
            return null;
        }

        return sprintf(
            '%s/files/%s/download?app-domain=%s',
            $this->resolvePublicApiEntrypoint(),
            $normalizedFileId,
            rawurlencode($this->resolvePublicAppDomain())
        );
    }
}
