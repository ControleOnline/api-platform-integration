<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Category;
use ControleOnline\Entity\Config;
use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductCategory;
use ControleOnline\Entity\ProductShowcase;
use ControleOnline\Entity\ProductShowcaseItem;
use ControleOnline\Entity\ProductUnity;
use ControleOnline\Service\Client\MercadoLivreClient;
use ControleOnline\Service\Marketplace\MarketplaceIntegrationStateProviderInterface;
use Doctrine\ORM\EntityManagerInterface;

class MercadoLivreService implements MarketplaceIntegrationStateProviderInterface
{
    private const APP_CONTEXT = 'MercadoLivre';
    private const CONFIG_ACCESS_TOKEN = 'mercado-livre-access-token';
    private const CONFIG_REFRESH_TOKEN = 'mercado-livre-refresh-token';
    private const CONFIG_USER_ID = 'mercado-livre-user-id';
    private const CONFIG_SHOP_DOMAIN = 'mercado-livre-shop-domain';
    private const DEFAULT_SHOP_SOURCE = 'mercado-livre';

    protected static $logger;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MercadoLivreClient $mercadoLivreClient,
        private readonly ConfigService $configService,
        private readonly ExtraDataService $extraDataService,
        private readonly PeopleService $peopleService,
        private readonly StatusService $statusService,
        private readonly LoggerService $loggerService,
    ) {
        self::$logger = $this->loggerService->getLogger(self::APP_CONTEXT);
    }

    public function getMarketplaceKey(): string
    {
        return self::APP_CONTEXT;
    }

    public function getStoredIntegrationState(People $provider): array
    {
        $accessToken = $this->readConfigValue($provider, self::CONFIG_ACCESS_TOKEN);
        $userId = $this->readConfigValue($provider, self::CONFIG_USER_ID);
        $state = $this->getProviderState($provider);

        return array_merge([
            'key' => 'mercadolivre',
            'label' => 'Mercado Livre',
            'connected' => $accessToken !== '' && $userId !== '',
            'remote_connected' => $accessToken !== '' && $userId !== '',
            'auth_available' => $accessToken !== '',
            'user_id' => $userId !== '' ? $userId : null,
            'seller_id' => $userId !== '' ? $userId : null,
            'imported_product_count' => $this->countImportedProducts($provider),
            'last_sync_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
        ], $state);
    }

    public function getCatalogSyncStatus(People $provider): array
    {
        $state = $this->getStoredIntegrationState($provider);

        return [
            'key' => 'mercadolivre',
            'label' => 'Mercado Livre',
            'connected' => (bool) ($state['connected'] ?? false),
            'synced_product_count' => (int) ($state['imported_product_count'] ?? 0),
            'last_sync_at' => $state['last_sync_at'] ?? null,
            'last_error_code' => $state['last_error_code'] ?? null,
            'last_error_message' => $state['last_error_message'] ?? null,
        ];
    }

    public function buildIntegrationDetail(People $provider, string $apiBaseUrl): array
    {
        $state = $this->getStoredIntegrationState($provider);
        $shopShowcase = $this->findShopShowcase($provider);
        $oauthCallbackUrl = $this->buildOAuthCallbackUrl($apiBaseUrl);

        return [
            'provider' => [
                'id' => $provider->getId(),
                'name' => method_exists($provider, 'getName') ? $provider->getName() : null,
            ],
            'integration' => $state,
            'webhook' => [
                'url' => rtrim($apiBaseUrl, '/') . '/webhook/mercadolivre',
                'oauth_url' => rtrim($apiBaseUrl, '/') . '/oauth/mercadolivre/notifications',
            ],
            'oauth' => [
                'callback_url' => $oauthCallbackUrl,
                'authorization_endpoint' => '/marketplace/integrations/mercadolivre/authorization-page',
                'client_configured' => $this->resolveClientId() !== '',
            ],
            'configs' => [
                self::CONFIG_USER_ID => $this->readConfigValue($provider, self::CONFIG_USER_ID),
                self::CONFIG_REFRESH_TOKEN => $this->readConfigValue($provider, self::CONFIG_REFRESH_TOKEN),
                self::CONFIG_ACCESS_TOKEN => $this->maskValue($this->readConfigValue($provider, self::CONFIG_ACCESS_TOKEN)),
                self::CONFIG_SHOP_DOMAIN => $this->readConfigValue($provider, self::CONFIG_SHOP_DOMAIN),
            ],
            'shop_showcase' => $shopShowcase instanceof ProductShowcase ? [
                'id' => $shopShowcase->getId(),
                'name' => $shopShowcase->getName(),
                'domain' => $shopShowcase->getPeopleDomain()?->getDomain(),
                'active' => $shopShowcase->isActive(),
            ] : null,
            'showcases' => $this->listImportShowcases($provider),
        ];
    }

    public function buildAuthorizationPage(People $provider, string $apiBaseUrl, ?string $returnUrl = null): array
    {
        $clientId = $this->resolveClientId();
        if ($clientId === '') {
            return [
                'success' => false,
                'error' => 'missing_client_id',
                'message' => 'Client ID do Mercado Livre nao configurado.',
            ];
        }

        $redirectUri = $this->buildOAuthCallbackUrl($apiBaseUrl);
        $state = $this->encodeOAuthState([
            'provider_id' => $provider->getId(),
            'return_url' => $returnUrl,
            'issued_at' => time(),
        ]);

        return array_merge([
            'success' => true,
            'state' => $state,
        ], $this->mercadoLivreClient->buildAuthorizationUrl($clientId, $redirectUri, $state));
    }

    public function connectViaOAuthCode(string $code, string $state, string $redirectUri): array
    {
        $payload = $this->decodeOAuthState($state);
        if ($payload === null) {
            return [
                'success' => false,
                'error' => 'invalid_state',
                'message' => 'Estado OAuth invalido.',
            ];
        }

        $provider = $this->entityManager->getRepository(People::class)->find((int) ($payload['provider_id'] ?? 0));
        if (!$provider instanceof People) {
            return [
                'success' => false,
                'error' => 'invalid_provider',
                'message' => 'Empresa da autorizacao nao encontrada.',
            ];
        }

        $clientId = $this->resolveClientId();
        $clientSecret = $this->resolveClientSecret();
        if ($clientId === '' || $clientSecret === '') {
            return [
                'success' => false,
                'error' => 'missing_client_config',
                'message' => 'Client ID/secret do Mercado Livre nao configurado.',
                'return_url' => $this->normalizeReturnUrl($payload['return_url'] ?? null),
            ];
        }

        $token = $this->mercadoLivreClient->exchangeAuthorizationCode($clientId, $clientSecret, trim($code), $redirectUri);
        if (!is_array($token) || trim((string) ($token['access_token'] ?? '')) === '') {
            return [
                'success' => false,
                'error' => 'token_exchange_failed',
                'message' => 'Nao foi possivel concluir a autorizacao no Mercado Livre.',
                'return_url' => $this->normalizeReturnUrl($payload['return_url'] ?? null),
            ];
        }

        $userId = trim((string) ($token['user_id'] ?? $token['seller_id'] ?? ''));
        if ($userId === '') {
            $currentUser = $this->mercadoLivreClient->requestApi('GET', '/users/me', [
                'headers' => [
                    'Authorization' => 'Bearer ' . trim((string) $token['access_token']),
                ],
            ]);
            $userId = trim((string) ($currentUser['id'] ?? ''));
        }

        $this->persistProviderConfig($provider, self::CONFIG_ACCESS_TOKEN, trim((string) $token['access_token']));
        $this->persistProviderConfig($provider, self::CONFIG_REFRESH_TOKEN, trim((string) ($token['refresh_token'] ?? '')));
        if ($userId !== '') {
            $this->persistProviderConfig($provider, self::CONFIG_USER_ID, $userId);
            $this->materializeProviderSellerId($provider, $userId);
        }

        $this->storeProviderState($provider, [
            'connected_at' => date('Y-m-d H:i:s'),
            'expires_in' => $token['expires_in'] ?? null,
            'last_error_code' => null,
            'last_error_message' => null,
        ]);

        return [
            'success' => true,
            'return_url' => $this->normalizeReturnUrl($payload['return_url'] ?? null),
            'user_id' => $userId !== '' ? $userId : null,
        ];
    }

    public function importProducts(People $provider, int $limit = 50, mixed $showcaseId = null): array
    {
        if ($showcaseId === null || trim((string) $showcaseId) === '') {
            return [
                'success' => false,
                'imported_count' => 0,
                'updated_count' => 0,
                'skipped_count' => 0,
                'error' => 'missing_showcase',
            ];
        }

        $targetShowcase = $this->resolveShowcaseForImport($provider, $showcaseId);
        if (!$targetShowcase instanceof ProductShowcase) {
            return [
                'success' => false,
                'imported_count' => 0,
                'updated_count' => 0,
                'skipped_count' => 0,
                'error' => 'invalid_showcase',
            ];
        }

        $userId = $this->resolveProviderUserId($provider);
        if ($userId === '') {
            $this->storeProviderState($provider, [
                'last_error_code' => 'missing_user_id',
                'last_error_message' => 'Configure o user id do Mercado Livre antes de importar produtos.',
            ]);

            return [
                'success' => false,
                'imported_count' => 0,
                'updated_count' => 0,
                'skipped_count' => 0,
                'error' => 'missing_user_id',
            ];
        }

        $search = $this->mercadoLivreClient->searchUserItems($userId, $provider, 0, $limit);
        $itemIds = is_array($search['results'] ?? null) ? $search['results'] : [];
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $products = [];

        foreach ($itemIds as $itemId) {
            $itemId = trim((string) $itemId);
            if ($itemId === '') {
                continue;
            }

            $existing = $this->findProductByRemoteId($itemId);
            $item = $this->mercadoLivreClient->getItem($itemId, $provider);
            if (!is_array($item)) {
                $skipped++;
                continue;
            }

            $product = $this->importItemPayload($item, $provider, $targetShowcase);
            if (!$product instanceof Product) {
                $skipped++;
                continue;
            }

            $products[] = [
                'id' => $product->getId(),
                'remote_id' => $itemId,
                'name' => $product->getProduct(),
            ];

            $existing instanceof Product ? $updated++ : $imported++;
        }

        $this->storeProviderState($provider, [
            'last_sync_at' => date('Y-m-d H:i:s'),
            'last_product_import_count' => $imported + $updated,
            'last_product_import_skipped_count' => $skipped,
            'last_error_code' => null,
            'last_error_message' => null,
        ]);

        return [
            'success' => true,
            'imported_count' => $imported,
            'updated_count' => $updated,
            'skipped_count' => $skipped,
            'products' => $products,
        ];
    }

    public function handleWebhookCapture(Integration $integration): ?Order
    {
        $body = $this->decodeJson($integration->getBody());
        $payload = is_array($body['payload'] ?? null) ? $body['payload'] : $body;
        $webhook = is_array($body['__webhook'] ?? null) ? $body['__webhook'] : [];
        $topic = strtolower(trim((string) ($payload['topic'] ?? $webhook['event_type'] ?? '')));
        $resource = trim((string) ($payload['resource'] ?? $webhook['resource'] ?? ''));

        if ($resource === '') {
            self::$logger?->warning('Mercado Livre webhook ignored without resource', [
                'integration_id' => $integration->getId(),
                'topic' => $topic,
            ]);

            return null;
        }

        if (str_contains($topic, 'item') || str_starts_with($resource, '/items/')) {
            $provider = $this->resolveProviderFromPayload($payload);
            $itemId = $this->extractResourceId($resource);
            $item = $this->mercadoLivreClient->getItem($itemId, $provider);
            if (!is_array($item)) {
                return null;
            }

            $provider ??= $this->resolveProviderFromSellerId($item['seller_id'] ?? null);
            if (!$provider instanceof People) {
                self::$logger?->warning('Mercado Livre item webhook ignored because seller was not configured', [
                    'integration_id' => $integration->getId(),
                    'item_id' => $itemId,
                    'seller_id' => $item['seller_id'] ?? null,
                ]);

                return null;
            }

            $this->importItemPayload($item, $provider);
            $this->storeWebhookState($provider, $webhook, $payload, [
                'last_webhook_product_id' => $itemId,
            ]);

            return null;
        }

        if (str_contains($topic, 'order') || str_starts_with($resource, '/orders/')) {
            $provider = $this->resolveProviderFromPayload($payload);
            $orderId = $this->extractResourceId($resource);
            $orderPayload = $this->mercadoLivreClient->getOrder($orderId, $provider);
            if (!is_array($orderPayload)) {
                return null;
            }

            $provider ??= $this->resolveProviderFromSellerId($orderPayload['seller']['id'] ?? $orderPayload['seller_id'] ?? null);
            if (!$provider instanceof People) {
                self::$logger?->warning('Mercado Livre order webhook ignored because seller was not configured', [
                    'integration_id' => $integration->getId(),
                    'order_id' => $orderId,
                    'seller_id' => $orderPayload['seller']['id'] ?? $orderPayload['seller_id'] ?? null,
                ]);

                return null;
            }

            $order = $this->importOrderPayload($orderPayload, $provider);
            $this->storeWebhookState($provider, $webhook, $payload, [
                'last_webhook_order_id' => $orderId,
                'last_imported_order_id' => $order?->getId(),
            ]);

            return $order;
        }

        self::$logger?->info('Mercado Livre webhook archived without importer', [
            'integration_id' => $integration->getId(),
            'topic' => $topic,
            'resource' => $resource,
        ]);

        return null;
    }

    public function importItemPayload(array $item, People $provider, ?ProductShowcase $targetShowcase = null): ?Product
    {
        $remoteId = trim((string) ($item['id'] ?? ''));
        if ($remoteId === '') {
            return null;
        }

        $product = $this->findProductByRemoteId($remoteId);
        if (!$product instanceof Product) {
            $product = new Product();
            $product->setCompany($provider);
            $product->setSku($this->buildSku($remoteId));
            $product->setType('product');
            $product->setProductCondition('new');
            $product->setProductUnit($this->discoverProductUnity());
            $this->entityManager->persist($product);
        }

        $title = trim((string) ($item['title'] ?? $remoteId));
        $price = (float) ($item['price'] ?? 0);
        $status = strtolower(trim((string) ($item['status'] ?? '')));
        $permalink = trim((string) ($item['permalink'] ?? ''));

        $product->setProduct($title !== '' ? $this->limitText($title, 255) : $remoteId);
        $product->setDescription($permalink !== '' ? $permalink : 'Produto importado do Mercado Livre.');
        $product->setPrice($price);
        $product->setActive(!in_array($status, ['closed', 'inactive', 'under_review'], true));

        $category = $this->discoverCategory($provider, 'Mercado Livre');
        $this->linkProductCategory($product, $category);
        $this->entityManager->flush();

        $sellerId = trim((string) ($item['seller_id'] ?? ''));
        if ($sellerId !== '') {
            $this->materializeProviderSellerId($provider, $sellerId);
        }

        $this->extraDataService->upsertExtraDataValue(self::APP_CONTEXT, 'Product', (int) $product->getId(), 'id', $remoteId, 'text', 'mercadolivre');
        $this->extraDataService->upsertExtraDataValue(self::APP_CONTEXT, 'Product', (int) $product->getId(), 'code', $remoteId, 'text', 'mercadolivre');
        $this->extraDataService->upsertExtraDataValue(self::APP_CONTEXT, 'Product', (int) $product->getId(), 'permalink', $permalink, 'text', 'mercadolivre');
        $this->extraDataService->upsertExtraDataValue(self::APP_CONTEXT, 'Product', (int) $product->getId(), 'seller_id', $sellerId, 'text', 'mercadolivre');

        $settings = [
            'source' => 'mercadolivre',
            'remote_id' => $remoteId,
            'status' => $status,
            'currency_id' => $item['currency_id'] ?? null,
            'available_quantity' => $item['available_quantity'] ?? null,
            'thumbnail' => $item['thumbnail'] ?? null,
            'permalink' => $permalink !== '' ? $permalink : null,
            'category_id' => $item['category_id'] ?? null,
        ];

        if ($targetShowcase instanceof ProductShowcase) {
            $this->upsertShowcaseItem($targetShowcase, $product, $remoteId, $price, $settings, $product->isActive());
        }

        $this->entityManager->flush();

        return $product;
    }

    public function importOrderPayload(array $payload, People $provider): ?Order
    {
        $remoteId = trim((string) ($payload['id'] ?? $payload['order_id'] ?? ''));
        if ($remoteId === '') {
            return null;
        }

        $order = $this->findOrderByRemoteId($remoteId);
        if (!$order instanceof Order) {
            $order = new Order();
            $order->setProvider($provider);
            $order->setClient($this->resolveBuyer($payload));
            $order->setPayer($order->getClient());
            $order->setApp(Order::APP_MERCADO_LIVRE);
            $order->setOrderType(Order::ORDER_TYPE_SALE);
            $order->setExternalCode($remoteId);
            $order->setOrderDate($this->parseDate($payload['date_created'] ?? null) ?? new \DateTimeImmutable());
            $this->entityManager->persist($order);
        }

        $status = $this->resolveOrderStatus(trim((string) ($payload['status'] ?? 'pending')));
        $total = (float) ($payload['total_amount'] ?? $payload['paid_amount'] ?? 0);

        $order->setStatus($status);
        $order->setPrice($total);
        $order->setAlterDate(new \DateTimeImmutable());
        $order->setComments('Mercado Livre #' . $remoteId);
        $order->addOtherInformations(self::APP_CONTEXT, [
            'id' => $remoteId,
            'pack_id' => $payload['pack_id'] ?? null,
            'status' => $payload['status'] ?? null,
            'date_created' => $payload['date_created'] ?? null,
            'last_synced_at' => date('Y-m-d H:i:s'),
        ]);

        $this->entityManager->flush();

        $this->extraDataService->upsertExtraDataValue(self::APP_CONTEXT, 'Order', (int) $order->getId(), 'id', $remoteId, 'text', 'mercadolivre');
        $this->extraDataService->upsertExtraDataValue(self::APP_CONTEXT, 'Order', (int) $order->getId(), 'code', $remoteId, 'text', 'mercadolivre');
        $this->extraDataService->upsertExtraDataValue(self::APP_CONTEXT, 'Order', (int) $order->getId(), 'pack_id', $payload['pack_id'] ?? '', 'text', 'mercadolivre');

        if ($order->getOrderProducts()->count() === 0) {
            $this->addOrderProducts($order, $payload['order_items'] ?? []);
        }

        $this->entityManager->flush();

        return $order;
    }

    private function addOrderProducts(Order $order, mixed $items): void
    {
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemProduct = is_array($item['item'] ?? null) ? $item['item'] : [];
            $remoteItemId = trim((string) ($itemProduct['id'] ?? ''));
            $product = $remoteItemId !== '' ? $this->findProductByRemoteId($remoteItemId) : null;

            if (!$product instanceof Product && $remoteItemId !== '') {
                $remoteItem = $this->mercadoLivreClient->getItem($remoteItemId, $order->getProvider());
                if (is_array($remoteItem)) {
                    $product = $this->importItemPayload($remoteItem, $order->getProvider());
                }
            }

            if (!$product instanceof Product) {
                continue;
            }

            $quantity = (float) ($item['quantity'] ?? 1);
            $unitPrice = (float) ($item['unit_price'] ?? $item['full_unit_price'] ?? $product->getPrice());

            $orderProduct = new OrderProduct();
            $orderProduct->setOrder($order);
            $orderProduct->setProduct($product);
            $orderProduct->setProductShowcaseItem($this->findShowcaseItemByRemoteCode($remoteItemId, $order->getProvider()));
            $orderProduct->setStatus($this->statusService->discoveryStatus('open', 'open', 'order_product'));
            $orderProduct->setQuantity($quantity);
            $orderProduct->setPrice($unitPrice);
            $orderProduct->setTotal($quantity * $unitPrice);
            $orderProduct->setOutInventory($product->getDefaultOutInventory());
            $order->addOrderProduct($orderProduct);
            $this->entityManager->persist($orderProduct);
        }
    }

    private function resolveBuyer(array $payload): People
    {
        $buyer = is_array($payload['buyer'] ?? null) ? $payload['buyer'] : [];
        $document = preg_replace('/\D+/', '', (string) ($buyer['billing_info']['doc_number'] ?? ''));
        $email = trim((string) ($buyer['email'] ?? ''));
        $nickname = trim((string) ($buyer['nickname'] ?? ''));
        $name = trim((string) (($buyer['first_name'] ?? '') . ' ' . ($buyer['last_name'] ?? '')));
        $name = $name !== '' ? $name : ($nickname !== '' ? $nickname : 'Cliente Mercado Livre');

        return $this->peopleService->discoveryPeople(
            $document !== '' ? $document : null,
            $email !== '' ? $email : null,
            [],
            $name,
            'F',
        );
    }

    private function resolveOrderStatus(string $remoteStatus): \ControleOnline\Entity\Status
    {
        $normalized = strtolower($remoteStatus);

        return match ($normalized) {
            'paid', 'confirmed' => $this->statusService->discoveryStatus('open', 'open', 'order'),
            'cancelled', 'canceled' => $this->statusService->discoveryStatus('canceled', 'canceled', 'order'),
            'delivered', 'completed' => $this->statusService->discoveryStatus('closed', 'closed', 'order'),
            default => $this->statusService->discoveryStatus('pending', 'waiting payment', 'order'),
        };
    }

    private function resolveProviderUserId(People $provider): string
    {
        $userId = $this->readConfigValue($provider, self::CONFIG_USER_ID);
        if ($userId !== '') {
            return $userId;
        }

        $currentUser = $this->mercadoLivreClient->getCurrentUser($provider);
        $userId = trim((string) ($currentUser['id'] ?? ''));
        if ($userId !== '') {
            $this->materializeProviderSellerId($provider, $userId);
        }

        return $userId;
    }

    private function resolveProviderFromPayload(array $payload): ?People
    {
        $sellerId = $payload['user_id'] ?? $payload['seller_id'] ?? $payload['application_id'] ?? null;

        return $this->resolveProviderFromSellerId($sellerId);
    }

    private function resolveProviderFromSellerId(mixed $sellerId): ?People
    {
        $normalized = trim((string) $sellerId);
        if ($normalized === '') {
            return null;
        }

        $provider = $this->extraDataService->getEntityByExtraData(self::APP_CONTEXT, 'seller_id', $normalized, People::class);
        if ($provider instanceof People) {
            return $provider;
        }

        $config = $this->entityManager->createQueryBuilder()
            ->select('config')
            ->from(Config::class, 'config')
            ->andWhere('config.configKey = :configKey')
            ->andWhere('config.configValue IN (:values)')
            ->setParameter('configKey', self::CONFIG_USER_ID)
            ->setParameter('values', [$normalized, json_encode($normalized)])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $config instanceof Config ? $config->getPeople() : null;
    }

    private function materializeProviderSellerId(People $provider, string $sellerId): void
    {
        $this->extraDataService->upsertExtraDataValue(self::APP_CONTEXT, 'People', (int) $provider->getId(), 'seller_id', $sellerId, 'text', 'mercadolivre');
        $this->extraDataService->upsertExtraDataValue(self::APP_CONTEXT, 'People', (int) $provider->getId(), 'user_id', $sellerId, 'text', 'mercadolivre');
    }

    private function findProductByRemoteId(string $remoteId): ?Product
    {
        $product = $this->extraDataService->getEntityByExtraData(self::APP_CONTEXT, 'id', $remoteId, Product::class);

        return $product instanceof Product ? $product : null;
    }

    private function findOrderByRemoteId(string $remoteId): ?Order
    {
        $order = $this->extraDataService->getEntityByExtraData(self::APP_CONTEXT, 'id', $remoteId, Order::class);
        if ($order instanceof Order) {
            return $order;
        }

        $order = $this->entityManager->getRepository(Order::class)->findOneBy([
            'app' => Order::APP_MERCADO_LIVRE,
            'externalCode' => $remoteId,
        ]);

        return $order instanceof Order ? $order : null;
    }

    private function discoverProductUnity(): ProductUnity
    {
        $unity = $this->entityManager->getRepository(ProductUnity::class)->findOneBy([
            'productUnit' => 'UN',
        ]);

        if ($unity instanceof ProductUnity) {
            return $unity;
        }

        $unity = new ProductUnity();
        $unity->setProductUnit('UN');
        $unity->setUnitType('I');
        $unity->setDescription('Unidade');
        $this->entityManager->persist($unity);
        $this->entityManager->flush();

        return $unity;
    }

    private function discoverCategory(People $provider, string $name): Category
    {
        $category = $this->entityManager->getRepository(Category::class)->findOneBy([
            'company' => $provider,
            'context' => 'products',
            'name' => $name,
        ]);

        if ($category instanceof Category) {
            return $category;
        }

        $category = new Category();
        $category->setCompany($provider);
        $category->setContext('products');
        $category->setName($name);
        $category->setIcon('shopping-bag');
        $category->setColor('#FFE600');
        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return $category;
    }

    private function linkProductCategory(Product $product, Category $category): void
    {
        $link = $this->entityManager->getRepository(ProductCategory::class)->findOneBy([
            'product' => $product,
            'category' => $category,
        ]);

        if ($link instanceof ProductCategory) {
            return;
        }

        $link = new ProductCategory();
        $link->setProduct($product);
        $link->setCategory($category);
        $this->entityManager->persist($link);
    }

    private function findShopShowcase(People $provider): ?ProductShowcase
    {
        $shopDomain = $this->readConfigValue($provider, self::CONFIG_SHOP_DOMAIN);
        $repository = $this->entityManager->getRepository(ProductShowcase::class);

        if ($shopDomain !== '') {
            $showcase = $this->entityManager->createQueryBuilder()
                ->select('showcase')
                ->from(ProductShowcase::class, 'showcase')
                ->innerJoin('showcase.peopleDomain', 'peopleDomain')
                ->andWhere('showcase.company = :provider')
                ->andWhere('showcase.integrationKey = :integrationKey')
                ->andWhere('peopleDomain.domain = :domain')
                ->setParameter('provider', $provider)
                ->setParameter('integrationKey', 'shop')
                ->setParameter('domain', $shopDomain)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($showcase instanceof ProductShowcase) {
                return $showcase;
            }
        }

        $showcase = $repository->findOneBy([
            'company' => $provider,
            'integrationKey' => 'shop',
            'externalStoreCode' => self::DEFAULT_SHOP_SOURCE,
        ]);

        if ($showcase instanceof ProductShowcase) {
            return $showcase;
        }

        $showcase = $this->entityManager->createQueryBuilder()
            ->select('showcase')
            ->from(ProductShowcase::class, 'showcase')
            ->leftJoin('showcase.peopleDomain', 'peopleDomain')
            ->andWhere('showcase.company = :provider')
            ->andWhere('showcase.integrationKey = :integrationKey')
            ->andWhere('showcase.active = :active')
            ->andWhere('(peopleDomain.domain LIKE :loja OR showcase.name LIKE :lojaName)')
            ->setParameter('provider', $provider)
            ->setParameter('integrationKey', 'shop')
            ->setParameter('active', true)
            ->setParameter('loja', 'loja.%')
            ->setParameter('lojaName', '%Loja%')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $showcase instanceof ProductShowcase ? $showcase : null;
    }

    private function resolveShowcaseForImport(People $provider, mixed $showcaseId): ?ProductShowcase
    {
        $normalizedId = (int) preg_replace('/\D+/', '', (string) $showcaseId);
        if ($normalizedId <= 0) {
            return null;
        }

        $showcase = $this->entityManager->getRepository(ProductShowcase::class)->find($normalizedId);
        if (!$showcase instanceof ProductShowcase || $showcase->getCompany()->getId() !== $provider->getId()) {
            return null;
        }

        return $showcase;
    }

    private function listImportShowcases(People $provider): array
    {
        $showcases = $this->entityManager->getRepository(ProductShowcase::class)->findBy([
            'company' => $provider,
            'active' => true,
        ], ['name' => 'ASC']);

        return array_map(static fn(ProductShowcase $showcase): array => [
            'id' => $showcase->getId(),
            'name' => $showcase->getName(),
            'integration_key' => $showcase->getIntegrationKey(),
            'external_store_code' => $showcase->getExternalStoreCode(),
            'domain' => $showcase->getPeopleDomain()?->getDomain(),
        ], $showcases);
    }

    private function upsertShowcaseItem(
        ProductShowcase $showcase,
        Product $product,
        string $externalCode,
        float $price,
        array $settings,
        bool $active
    ): ProductShowcaseItem {
        $item = $this->entityManager->getRepository(ProductShowcaseItem::class)->findOneBy([
            'showcase' => $showcase,
            'product' => $product,
        ]);

        if (!$item instanceof ProductShowcaseItem) {
            $item = new ProductShowcaseItem();
            $item->setShowcase($showcase);
            $item->setProduct($product);
            $this->entityManager->persist($item);
        }

        $settings = array_filter($settings, static fn(mixed $value): bool => $value !== null && $value !== '');
        $item->setExternalCode($externalCode);
        $item->setPrice($price);
        $item->setActive($active);
        $item->setPublished($active);
        $item->setSyncHash(hash('sha256', json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)));
        $item->setSyncSyncedAt(new \DateTimeImmutable());
        $item->setSettings(array_merge($item->getSettings(), $settings));

        return $item;
    }

    private function findShowcaseItemByRemoteCode(string $remoteCode, People $provider): ?ProductShowcaseItem
    {
        if ($remoteCode === '') {
            return null;
        }

        $item = $this->entityManager->createQueryBuilder()
            ->select('item')
            ->from(ProductShowcaseItem::class, 'item')
            ->innerJoin('item.showcase', 'showcase')
            ->andWhere('showcase.company = :provider')
            ->andWhere('item.externalCode = :remoteCode')
            ->setParameter('provider', $provider)
            ->setParameter('remoteCode', $remoteCode)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $item instanceof ProductShowcaseItem ? $item : null;
    }

    private function countImportedProducts(People $provider): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            "SELECT COUNT(DISTINCT ed.entity_id)
               FROM extra_data ed
               INNER JOIN extra_fields ef ON ef.id = ed.extra_fields_id
               INNER JOIN product p ON p.id = ed.entity_id
              WHERE ef.context = :context
                AND ef.field_name = 'id'
                AND LOWER(ed.entity_name) = 'product'
                AND p.company_id = :provider_id",
            [
                'context' => self::APP_CONTEXT,
                'provider_id' => $provider->getId(),
            ]
        );
    }

    private function getProviderState(People $provider): array
    {
        $otherInformations = $provider->getOtherInformations(true);
        $state = is_object($otherInformations) && isset($otherInformations->{self::APP_CONTEXT})
            ? $otherInformations->{self::APP_CONTEXT}
            : [];

        return json_decode(json_encode($state), true) ?: [];
    }

    private function storeProviderState(People $provider, array $state): void
    {
        $currentState = $this->getProviderState($provider);
        $provider->addOtherInformations(self::APP_CONTEXT, array_merge($currentState, $state));
        $this->entityManager->persist($provider);
        $this->entityManager->flush();
    }

    private function storeWebhookState(People $provider, array $webhook, array $payload, array $extra = []): void
    {
        $this->storeProviderState($provider, array_merge([
            'last_webhook_event_id' => $webhook['event_id'] ?? null,
            'last_webhook_event_type' => $webhook['event_type'] ?? $payload['topic'] ?? null,
            'last_webhook_resource' => $webhook['resource'] ?? $payload['resource'] ?? null,
            'last_webhook_received_at' => $webhook['received_at'] ?? date('Y-m-d H:i:s'),
            'last_webhook_processed_at' => date('Y-m-d H:i:s'),
        ], $extra));
    }

    private function readConfigValue(People $provider, string $key): string
    {
        $value = trim((string) ($this->configService->getConfig($provider, $key) ?? ''));
        if ($value === '') {
            return '';
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            if (is_scalar($decoded)) {
                return trim((string) $decoded);
            }
        } catch (\JsonException) {
        }

        return $value;
    }

    private function persistProviderConfig(People $provider, string $key, string $value): void
    {
        $config = $this->configService->discoveryConfig($provider, $key);
        if (!$config instanceof Config) {
            return;
        }

        $config->setConfigValue($value);
        $config->setVisibility('private');
        $this->entityManager->persist($config);
        $this->entityManager->flush();
    }

    private function buildOAuthCallbackUrl(string $apiBaseUrl): string
    {
        return rtrim($apiBaseUrl, '/') . '/oauth/mercadolivre/callback';
    }

    private function encodeOAuthState(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
        $encodedPayload = $this->base64UrlEncode($json);
        $signature = hash_hmac('sha256', $encodedPayload, $this->resolveStateSecret());

        return $this->base64UrlEncode(json_encode([
            'payload' => $encodedPayload,
            'signature' => $signature,
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}');
    }

    private function decodeOAuthState(string $state): ?array
    {
        $decoded = json_decode($this->base64UrlDecode($state), true);
        if (!is_array($decoded)) {
            return null;
        }

        $payload = trim((string) ($decoded['payload'] ?? ''));
        $signature = trim((string) ($decoded['signature'] ?? ''));
        if ($payload === '' || $signature === '') {
            return null;
        }

        $expectedSignature = hash_hmac('sha256', $payload, $this->resolveStateSecret());
        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        $payloadData = json_decode($this->base64UrlDecode($payload), true);
        if (!is_array($payloadData)) {
            return null;
        }

        $issuedAt = (int) ($payloadData['issued_at'] ?? 0);
        if ($issuedAt <= 0 || $issuedAt < (time() - 3600)) {
            return null;
        }

        return $payloadData;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return base64_decode(strtr($value, '-_', '+/'), true) ?: '';
    }

    private function resolveClientId(): string
    {
        return trim((string) (
            $_ENV['OAUTH_MERCADO_LIVRE_CLIENT_ID']
            ?? $_SERVER['OAUTH_MERCADO_LIVRE_CLIENT_ID']
            ?? $_ENV['OAUTH_MERCADO_LIVRE_APP_ID']
            ?? $_SERVER['OAUTH_MERCADO_LIVRE_APP_ID']
            ?? ''
        ));
    }

    private function resolveClientSecret(): string
    {
        return trim((string) (
            $_ENV['OAUTH_MERCADO_LIVRE_CLIENT_SECRET']
            ?? $_SERVER['OAUTH_MERCADO_LIVRE_CLIENT_SECRET']
            ?? $_ENV['OAUTH_MERCADO_LIVRE_APP_SECRET']
            ?? $_SERVER['OAUTH_MERCADO_LIVRE_APP_SECRET']
            ?? ''
        ));
    }

    private function resolveStateSecret(): string
    {
        return trim((string) (
            $_ENV['OAUTH_MERCADO_LIVRE_STATE_SECRET']
            ?? $_SERVER['OAUTH_MERCADO_LIVRE_STATE_SECRET']
            ?? $_ENV['APP_SECRET']
            ?? $_SERVER['APP_SECRET']
            ?? 'mercadolivre'
        ));
    }

    private function normalizeReturnUrl(mixed $returnUrl): string
    {
        $returnUrl = trim((string) $returnUrl);
        if ($returnUrl === '') {
            return '/integrations-page';
        }

        return $returnUrl;
    }

    private function decodeJson(?string $json): array
    {
        if (!is_string($json) || trim($json) === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function extractResourceId(string $resource): string
    {
        $resource = trim($resource);
        $parts = array_values(array_filter(explode('/', $resource), static fn(string $part): bool => $part !== ''));

        return (string) end($parts);
    }

    private function buildSku(string $remoteId): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9]/', '', $remoteId);

        return substr('ML' . $normalized, 0, 32);
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($normalized);
        } catch (\Throwable) {
            return null;
        }
    }

    private function maskValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return str_repeat('*', max(8, min(16, strlen($value))));
    }

    private function limitText(string $value, int $limit): string
    {
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $limit, 'UTF-8')
            : substr($value, 0, $limit);
    }
}
