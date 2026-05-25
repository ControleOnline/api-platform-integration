<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Address;
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
use ControleOnline\Message\SendManagerEventPushMessage;
use ControleOnline\Service\Client\WebsocketClient;
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
        protected OrderAutomaticPrintService $orderAutomaticPrintService,
        protected InvoiceService $invoiceService,
        protected WalletService $walletService,
        protected OrderProductService $orderProductService,
        protected ProductGroupService $productGroupService,
        protected ?IntegrationService $integrationService = null,
        protected ?WhatsAppService $whatsAppService = null,
        protected ?ContainerInterface $container = null,
        protected ?MessageBusInterface $messageBus = null
    ) {}

    protected function resolveIntegrationService(): ?IntegrationService
    {
        if ($this->integrationService instanceof IntegrationService) {
            return $this->integrationService;
        }

        if (!$this->container instanceof ContainerInterface || !$this->container->has(IntegrationService::class)) {
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
        if ($this->whatsAppService instanceof WhatsAppService) {
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
        foreach ($events as $event) {
            if (
                isset($this->messageBus) &&
                $this->messageBus instanceof MessageBusInterface &&
                in_array((string) ($event['event'] ?? ''), ['store.opened', 'store.closed'], true)
            ) {
                $this->messageBus->dispatch(
                    new SendManagerEventPushMessage((int) $company->getId(), $event)
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
