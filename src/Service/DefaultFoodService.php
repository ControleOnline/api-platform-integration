<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Address;
use ControleOnline\Entity\ExtraData;
use ControleOnline\Entity\ExtraFields;
use ControleOnline\Service\AddressService;
use ControleOnline\Entity\DeviceConfig;
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
use ControleOnline\Service\Client\Food99Client;
use ControleOnline\Service\Client\WebsocketClient;
use ControleOnline\Service\Client\IfoodClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use ControleOnline\Service\LoggerService;
use DateTime;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Messenger\MessageBusInterface;


class DefaultFoodService
{

    protected static $foodPeople;
    protected static $logger;
    protected static $app;
    protected ?DomainService $domainService = null;


    public function __construct(
        protected EntityManagerInterface $entityManager,
        protected LoggerService $loggerService,
        protected HttpClientInterface $httpClient,
        protected IfoodClient $ifoodClient,
        protected ExtraDataService $extraDataService,
        protected PeopleService $peopleService,
        protected StatusService $statusService,
        protected AddressService $addressService,
        protected ProductService $productService,
        protected WebsocketClient $websocketClient,
        protected ConfigService $configService,
        protected DeviceService $deviceService,
        protected OrderPrintService $orderPrintService,
        protected OrderAutomaticPrintService $orderAutomaticPrintService,
        protected InvoiceService $invoiceService,
        protected WalletService $walletService,
        protected OrderProductService $orderProductService,
        protected ProductGroupService $productGroupService,
        protected ?Food99Client $food99Client = null,
        protected ?IntegrationService $integrationService = null,
        protected ?WhatsAppService $whatsAppService = null,
        protected ?ContainerInterface $container = null,
        protected ?MessageBusInterface $messageBus = null
    ) {}

    protected function resolveIntegrationService(): ?IntegrationService
    {
        if (isset($this->integrationService) && $this->integrationService instanceof IntegrationService) {
            return $this->integrationService;
        }

        if (!isset($this->container) || !$this->container instanceof ContainerInterface || !$this->container->has(IntegrationService::class)) {
            return null;
        }

        $service = $this->container->get(IntegrationService::class);
        if (!$service instanceof IntegrationService) {
            return null;
        }

        $this->integrationService = $service;

        return $this->integrationService;
    }

    protected function resolveWhatsAppService(): ?WhatsAppService
    {
        if (isset($this->whatsAppService) && $this->whatsAppService instanceof WhatsAppService) {
            return $this->whatsAppService;
        }

        if (!$this->container instanceof ContainerInterface || !$this->container->has(WhatsAppService::class)) {
            return null;
        }

        $service = $this->container->get(WhatsAppService::class);
        if (!$service instanceof WhatsAppService) {
            return null;
        }

        $this->whatsAppService = $service;

        return $this->whatsAppService;
    }

    protected function resolveDomainService(): ?DomainService
    {
        if (isset($this->domainService) && $this->domainService instanceof DomainService) {
            return $this->domainService;
        }

        if (!$this->container instanceof ContainerInterface || !$this->container->has(DomainService::class)) {
            return null;
        }

        $service = $this->container->get(DomainService::class);
        if (!$service instanceof DomainService) {
            return null;
        }

        $this->domainService = $service;

        return $this->domainService;
    }

    protected function resolveFood99Client(): ?Food99Client
    {
        if (isset($this->food99Client) && $this->food99Client instanceof Food99Client) {
            return $this->food99Client;
        }

        if (!$this->container instanceof ContainerInterface || !$this->container->has(Food99Client::class)) {
            return null;
        }

        $service = $this->container->get(Food99Client::class);
        if (!$service instanceof Food99Client) {
            return null;
        }

        $this->food99Client = $service;

        return $this->food99Client;
    }

    protected function resolveMarketplaceServiceInstance(string $serviceClass): ?object
    {
        if (isset($this->container) && $this->container instanceof ContainerInterface && $this->container->has($serviceClass)) {
            $service = $this->container->get($serviceClass);
            if (is_object($service)) {
                return $service;
            }
        }

        if (!class_exists($serviceClass)) {
            return null;
        }

        $reflection = new \ReflectionClass($serviceClass);
        $service = $reflection->newInstanceWithoutConstructor();
        $sourceReflection = new \ReflectionObject($this);

        foreach ($sourceReflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            if ($property->getName() === 'eventDispatcher') {
                continue;
            }

            $property->setAccessible(true);
            if (method_exists($property, 'isInitialized') && !$property->isInitialized($this)) {
                continue;
            }

            $property->setValue($service, $property->getValue($this));
        }

        return $service;
    }

    protected function invokeMarketplaceServiceMethod(object $service, string $method, array $arguments = []): mixed
    {
        $reflection = new \ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($service, $arguments);
    }

    protected function decodeEntityOtherInformationsValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            $normalized = json_decode(json_encode($value), true);

            return is_array($normalized) ? $normalized : [];
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return [];
    }

    protected function getDecodedEntityOtherInformations(object $entity): array
    {
        if (!method_exists($entity, 'getOtherInformations')) {
            return [];
        }

        try {
            return $this->decodeEntityOtherInformationsValue($entity->getOtherInformations(true) ?? $entity->getOtherInformations());
        } catch (\Throwable) {
            return [];
        }
    }

    protected function normalizeOtherInformationsValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_bool($value) || is_int($value) || is_float($value) || is_string($value) || $value === null) {
            return $value;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return $this->decodeEntityOtherInformationsValue($value);
        }

        return trim((string) $value);
    }

    protected function mergeEntityOtherInformations(object $entity, string $key, array $fields): void
    {
        if (!method_exists($entity, 'setOtherInformations')) {
            return;
        }

        $otherInformations = $this->getDecodedEntityOtherInformations($entity);
        $currentBlock = $this->decodeEntityOtherInformationsValue($otherInformations[$key] ?? []);

        foreach ($fields as $fieldName => $fieldValue) {
            $currentBlock[$fieldName] = $this->normalizeOtherInformationsValue($fieldValue);
        }

        $otherInformations[$key] = $currentBlock;
        $entity->setOtherInformations($otherInformations);
        $this->entityManager->persist($entity);
    }

    protected function resolveAddressCandidate(mixed $candidate): ?Address
    {
        if ($candidate instanceof Address) {
            return $candidate;
        }

        if (is_object($candidate) && method_exists($candidate, 'getId')) {
            $candidate = $candidate->getId();
        }

        if (is_array($candidate)) {
            $candidate = $candidate['id'] ?? $candidate['@id'] ?? null;
        }

        if (!is_numeric($candidate)) {
            return null;
        }

        $address = $this->entityManager->getRepository(Address::class)->find((int) $candidate);

        return $address instanceof Address ? $address : null;
    }

    protected function printOrder(Order $order)
    {
        try {
            $this->orderAutomaticPrintService->dispatchCompletedOrderPrints(
                $order,
                [
                    'source' => strtolower(trim((string) $order->getApp())) ?: 'marketplace',
                ]
            );
        } catch (\Throwable $exception) {
            self::$logger?->warning('Marketplace order print skipped because spool generation failed', [
                'local_order_id' => $order->getId(),
                'provider_id' => $order->getProvider()?->getId(),
                'app' => $order->getApp(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    protected function broadcastCompanyWebsocketEvents(People $company, array $events): void
    {
        $integrationService = $this->resolveIntegrationService();
        foreach ($events as $event) {
            if (
                $integrationService instanceof IntegrationService &&
                in_array((string) ($event['event'] ?? ''), ['store.opened', 'store.closed'], true)
            ) {
                $integrationService->addManagerPushIntegrations(
                    json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                    $company
                );
            }
        }

        $deviceConfigs = $this->entityManager->getRepository(DeviceConfig::class)->findBy([
            'people' => $company,
        ]);

        if (empty($deviceConfigs)) {
            return;
        }

        $payload = json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return;
        }

        $sentDevices = [];
        foreach ($deviceConfigs as $deviceConfig) {
            if (!$deviceConfig instanceof DeviceConfig) {
                continue;
            }

            $device = $deviceConfig->getDevice();
            $deviceId = $device->getId();

            if (isset($sentDevices[$deviceId])) {
                continue;
            }

            $sentDevices[$deviceId] = true;
            $this->websocketClient->push($device, $payload);
        }
    }

    protected function sendStoreClosingNotifications(
        People $company,
        string $app,
        ?DateTime $referenceDate = null
    ): array {
        if (!$this->container instanceof ContainerInterface || !$this->container->has(MarketplaceOrderFinancialGenerationService::class)) {
            self::$logger?->warning('Store closing summary skipped because the financial generation service is unavailable in the current container');
            return [];
        }

        /** @var MarketplaceOrderFinancialGenerationService $marketplaceOrderFinancialGenerationService */
        $marketplaceOrderFinancialGenerationService = $this->container->get(MarketplaceOrderFinancialGenerationService::class);
        try {
            $summary = $marketplaceOrderFinancialGenerationService->buildStoreClosingSummary(
                $company,
                $app,
                $referenceDate
            );
        } catch (\Throwable $exception) {
            self::$logger?->warning('Store closing summary skipped because summary generation failed', [
                'provider_id' => $company->getId(),
                'app' => $app,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }

        $this->sendStoreClosingWhatsAppNotifications($company, $summary);

        return $summary;
    }

    protected function buildStoreClosingMessage(array $summary, string $statusLabel): string
    {
        $providerName = trim((string) ($summary['provider_name'] ?? ''));
        $marketplaceLabel = trim((string) ($summary['marketplace_label'] ?? ''));
        $dailySales = number_format((float) ($summary['daily_sales_amount'] ?? 0), 2, ',', '.');
        $weeklySettlement = number_format((float) ($summary['weekly_settlement_amount'] ?? 0), 2, ',', '.');
        $weeklyDueDate = trim((string) ($summary['weekly_due_date'] ?? ''));
        $weeklyDueDateLabel = '';
        if ($weeklyDueDate !== '') {
            $parsedWeeklyDueDate = DateTime::createFromFormat('Y-m-d', $weeklyDueDate);
            $weeklyDueDateLabel = $parsedWeeklyDueDate instanceof DateTime
                ? $parsedWeeklyDueDate->format('d/m/Y')
                : $weeklyDueDate;
        }

        $message = "*🔔 FECHAMENTO DE LOJA*\n";
        $message .= trim(sprintf(
            "%s %s\n",
            $providerName !== '' ? $providerName : 'Loja',
            $statusLabel !== '' ? $statusLabel : 'fechada'
        ));

        if ($marketplaceLabel !== '') {
            $message .= "Marketplace: {$marketplaceLabel}\n";
        }

        $message .= "Vendido hoje: R$ {$dailySales}\n";
        $message .= "Fatura da semana: R$ {$weeklySettlement}\n";

        if ($weeklyDueDateLabel !== '') {
            $message .= "Vencimento: {$weeklyDueDateLabel}\n";
        }

        return trim($message);
    }

    protected function sendStoreClosingWhatsAppNotifications(
        People $company,
        array $summary,
        string $statusLabel = 'foi fechada'
    ): void {
        $numbers = $this->configService->getConfig($company, 'store-close-notifications', true);

        if (!is_array($numbers) || $numbers === []) {
            return;
        }

        $whatsAppService = $this->resolveWhatsAppService();
        $integrationService = $this->resolveIntegrationService();
        if (!$whatsAppService instanceof WhatsAppService || !$integrationService instanceof IntegrationService) {
            self::$logger?->warning('Store closing WhatsApp notification skipped because integration dependencies are unavailable');
            return;
        }

        $connection = $whatsAppService->searchConnectionFromPeople($company, 'support', true);
        if (!$connection) {
            return;
        }

        $phone = $connection->getPhone();
        $origin = $phone->getDdi() . $phone->getDdd() . $phone->getPhone();
        $message = $this->buildStoreClosingMessage($summary, $statusLabel);

        foreach ($numbers as $number) {
            $destination = trim((string) $number);
            if ($destination === '') {
                continue;
            }

            $payload = json_encode([
                'action' => 'sendMessage',
                'origin' => $origin,
                'destination' => $destination,
                'message' => $message,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($payload === false) {
                continue;
            }

            $integrationService->addIntegration($payload, 'WhatsApp', null, null, $company);
        }
    }


    protected function discoveryFoodCode(object $entity, string $code, ?string $type = 'code')
    {
        return $this->extraDataService->discoveryExtraData($entity, self::$app, $type, $code, self::$app);
    }

    protected function discoveryFoodCodeByEntity(object $entity)
    {
        $entityId = method_exists($entity, 'getId') ? (int) $entity->getId() : 0;
        if ($entityId <= 0) {
            return null;
        }

        $candidates = [];
        foreach ($this->extraDataService->getExtraDataFromEntity($entity) as $extraData) {
            if (!$extraData instanceof ExtraData) {
                continue;
            }

            $extraFields = $extraData->getExtraFields();
            if (!$extraFields instanceof ExtraFields) {
                continue;
            }

            if (trim((string) $extraFields->getContext()) !== (string) self::$app) {
                continue;
            }

            $fieldName = strtolower(trim((string) $extraFields->getName()));
            if (!in_array($fieldName, ['id', 'code'], true)) {
                continue;
            }

            $value = trim((string) $extraData->getValue());
            if ($value === '') {
                continue;
            }

            $candidates[] = [
                'priority' => $fieldName === 'id' ? 0 : 1,
                'id' => $extraData->getId(),
                'value' => $value,
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static function (array $left, array $right): int {
            return [$left['priority'], -$left['id']] <=> [$right['priority'], -$right['id']];
        });

        $value = trim((string) ($candidates[0]['value'] ?? ''));
        return $value !== '' ? $value : null;
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

    protected function normalizeMarketplaceFreeText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_array($value) || is_object($value)) {
            return '';
        }

        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }

        $text = str_replace(["\r", "\n", "\t"], ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        $text = trim($text);

        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, 255);
        }

        return substr($text, 0, 255);
    }

    protected function syncOrderComments(Order $order, mixed $comments): void
    {
        $normalizedComments = $this->normalizeMarketplaceFreeText($comments);
        if ($normalizedComments === '') {
            return;
        }

        $currentComments = $this->normalizeMarketplaceFreeText($order->getComments());
        if ($currentComments === $normalizedComments || $this->containsMarketplaceFreeText($currentComments, $normalizedComments)) {
            return;
        }

        $mergedComments = $currentComments === ''
            ? $normalizedComments
            : $this->normalizeMarketplaceFreeText($currentComments . ' | ' . $normalizedComments);

        $order->setComments($mergedComments);
        $this->entityManager->persist($order);
    }

    protected function syncOrderProductComment(?OrderProduct $orderProduct, mixed $comment): void
    {
        if (!$orderProduct instanceof OrderProduct) {
            return;
        }

        $normalizedComment = $this->normalizeMarketplaceFreeText($comment);
        if ($normalizedComment === '') {
            return;
        }

        $currentComment = $this->normalizeMarketplaceFreeText($orderProduct->getComment());
        if ($currentComment === $normalizedComment || $this->containsMarketplaceFreeText($currentComment, $normalizedComment)) {
            return;
        }

        $mergedComment = $currentComment === ''
            ? $normalizedComment
            : $this->normalizeMarketplaceFreeText($currentComment . ' | ' . $normalizedComment);

        $orderProduct->setComment($mergedComment);
        $this->entityManager->persist($orderProduct);
    }

    protected function containsMarketplaceFreeText(string $haystack, string $needle): bool
    {
        if ($haystack === '' || $needle === '') {
            return false;
        }

        if (function_exists('mb_strtolower')) {
            return str_contains(
                mb_strtolower($haystack),
                mb_strtolower($needle)
            );
        }

        return str_contains(
            strtolower($haystack),
            strtolower($needle)
        );
    }

    protected function addLog(string $type, $log)
    {
        echo $log;
        self::$logger->$type($log);
    }

    protected function resolvePublicAppDomain(?string $mainDomain = null): string
    {
        $domain = trim((string) ($mainDomain ?? ''));

        if ($domain === '') {
            $domainService = $this->resolveDomainService();
            $domain = trim((string) ($domainService?->getMainDomain() ?? ''));
        }

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

        $domainService = $this->resolveDomainService();
        $mainDomain = trim((string) ($domainService?->getMainDomain() ?? ''));
        if ($mainDomain === '') {
            $mainDomain = 'api.controleonline.com';
        }

        if (!preg_match('#^https?://#i', $mainDomain)) {
            $mainDomain = 'https://' . ltrim($mainDomain, '/');
        }

        return sprintf(
            '%s/files/%s/download?app-domain=%s',
            rtrim($mainDomain, '/'),
            $normalizedFileId,
            rawurlencode($this->resolvePublicAppDomain($mainDomain))
        );
    }
}
