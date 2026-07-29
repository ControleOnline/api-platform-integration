<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Category;
use ControleOnline\Entity\Config;
use ControleOnline\Entity\File;
use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Module;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductCategory;
use ControleOnline\Entity\ProductFile;
use ControleOnline\Entity\ProductShowcase;
use ControleOnline\Entity\ProductShowcaseItem;
use ControleOnline\Entity\ProductUnity;
use ControleOnline\Service\Client\MercadoLivreClient;
use ControleOnline\Service\Marketplace\MarketplaceIntegrationHandlerInterface;
use ControleOnline\Service\Marketplace\MarketplaceIntegrationStateProviderInterface;
use Doctrine\ORM\EntityManagerInterface;

class MercadoLivreService implements MarketplaceIntegrationHandlerInterface, MarketplaceIntegrationStateProviderInterface
{
    private const APP_CONTEXT = 'MercadoLivre';
    private const CONFIG_ACCESS_TOKEN = 'mercado-livre-access-token';
    private const CONFIG_REFRESH_TOKEN = 'mercado-livre-refresh-token';
    private const CONFIG_USER_ID = 'mercado-livre-user-id';
    private const CONFIG_SHOP_DOMAIN = 'mercado-livre-shop-domain';
    private const CONFIG_CLIENT_ID = 'OAUTH_MERCADO_LIVRE_CLIENT_ID';
    private const CONFIG_CLIENT_SECRET = 'OAUTH_MERCADO_LIVRE_CLIENT_SECRET';
    private const DEFAULT_SHOP_SOURCE = 'mercado-livre';
    private const CONFIG_MODULE_NAME = 'integration';

    protected static $logger;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MercadoLivreClient $mercadoLivreClient,
        private readonly ConfigService $configService,
        private readonly ExtraDataService $extraDataService,
        private readonly FileService $fileService,
        private readonly PeopleService $peopleService,
        private readonly PeopleRoleService $peopleRoleService,
        private readonly StatusService $statusService,
        private readonly LoggerService $loggerService,
    ) {
        self::$logger = $this->loggerService->getLogger(self::APP_CONTEXT);
    }

    public function getMarketplaceKey(): string
    {
        return self::APP_CONTEXT;
    }

    public function integrate(Integration $integration): ?Order
    {
        $body = $this->decodeJson($integration->getBody());
        $action = strtolower(trim((string) ($body['action'] ?? '')));

        if ($action === 'products_import') {
            $this->handleQueuedProductImport($integration, $body);

            return null;
        }

        return $this->handleWebhookCapture($integration);
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

    public function buildIntegrationDetail(People $provider, string $apiBaseUrl, string $appDomain): array
    {
        $state = $this->getStoredIntegrationState($provider);
        $shopShowcase = $this->findShopShowcase($provider);
        $oauthCallbackUrl = $this->buildOAuthCallbackUrl($apiBaseUrl);
        $notificationUrl = rtrim($apiBaseUrl, '/') . '/' . rawurlencode($appDomain) . '/oauth/mercadolivre/notifications';

        return [
            'provider' => [
                'id' => $provider->getId(),
                'name' => method_exists($provider, 'getName') ? $provider->getName() : null,
            ],
            'integration' => $state,
            'webhook' => [
                'url' => $notificationUrl,
                'oauth_url' => $notificationUrl,
            ],
            'oauth' => [
                'callback_url' => $oauthCallbackUrl,
                'authorization_endpoint' => '/marketplace/integrations/mercadolivre/authorization-page',
                'client_configured' => $this->resolveClientId($provider) !== ''
                    && $this->resolveClientSecret($provider) !== '',
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

    public function buildAuthorizationPage(
        People $provider,
        string $apiBaseUrl,
        ?string $returnUrl = null,
        ?string $appDomain = null,
    ): array
    {
        $clientId = $this->resolveClientId($provider);
        if ($clientId === '') {
            return [
                'success' => false,
                'error' => 'missing_client_id',
                'message' => 'Client ID do Mercado Livre nao configurado.',
            ];
        }

        $appDomain = $this->normalizeOAuthDomain($appDomain);
        if ($appDomain === '') {
            return [
                'success' => false,
                'error' => 'missing_app_domain',
                'message' => 'Dominio da aplicacao nao informado para autorizacao Mercado Livre.',
            ];
        }

        $redirectUri = $this->resolveOAuthRedirectUri($apiBaseUrl, $appDomain);
        $pkce = $this->createPkceState($provider, $redirectUri);
        $state = $this->encodeOAuthState([
            'provider_id' => $provider->getId(),
            'app_domain' => $appDomain,
            'redirect_uri' => $redirectUri,
            'return_url' => $returnUrl,
            'oauth_nonce' => $pkce['nonce'],
            'issued_at' => time(),
        ]);

        return array_merge([
            'success' => true,
            'state' => $state,
        ], $this->mercadoLivreClient->buildAuthorizationUrl($clientId, $redirectUri, $state, [
            'code_challenge' => $pkce['challenge'],
            'code_challenge_method' => 'S256',
        ]));
    }

    public function resolveOAuthAppDomain(string $state, ?string $callbackAppDomain = null): string
    {
        $payload = $this->decodeOAuthState($state);
        if ($payload === null) {
            throw new \InvalidArgumentException('Estado OAuth invalido.');
        }

        $stateAppDomain = $this->normalizeOAuthDomain($payload['app_domain'] ?? null);
        $callbackAppDomain = $this->normalizeOAuthDomain($callbackAppDomain);
        if ($stateAppDomain === '') {
            throw new \InvalidArgumentException('Dominio da aplicacao ausente no estado OAuth.');
        }

        if ($callbackAppDomain !== '' && $callbackAppDomain !== $stateAppDomain) {
            throw new \InvalidArgumentException('Dominio do callback nao confere com o estado OAuth.');
        }

        return $stateAppDomain;
    }

    public function resolveOAuthReturnUrl(string $state): string
    {
        $payload = $this->decodeOAuthState($state);
        if ($payload === null) {
            throw new \InvalidArgumentException('Estado OAuth invalido.');
        }

        return $this->normalizeReturnUrl($payload['return_url'] ?? null);
    }

    public function connectViaOAuthCode(
        string $code,
        string $state,
        string $redirectUri,
        ?string $callbackAppDomain = null,
    ): array
    {
        $payload = $this->decodeOAuthState($state);
        if ($payload === null) {
            return [
                'success' => false,
                'error' => 'invalid_state',
                'message' => 'Estado OAuth invalido.',
            ];
        }

        try {
            $this->resolveOAuthAppDomain($state, $callbackAppDomain);
        } catch (\InvalidArgumentException $exception) {
            return [
                'success' => false,
                'error' => $this->normalizeOAuthDomain($payload['app_domain'] ?? null) === ''
                    ? 'missing_app_domain'
                    : 'app_domain_mismatch',
                'message' => $exception->getMessage(),
                'return_url' => $this->normalizeReturnUrl($payload['return_url'] ?? null),
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

        $clientId = $this->resolveClientId($provider);
        $clientSecret = $this->resolveClientSecret($provider);
        if ($clientId === '' || $clientSecret === '') {
            return [
                'success' => false,
                'error' => 'missing_client_config',
                'message' => 'Client ID/secret do Mercado Livre nao configurado.',
                'return_url' => $this->normalizeReturnUrl($payload['return_url'] ?? null),
            ];
        }

        $exchangeRedirectUri = $this->resolveOAuthExchangeRedirectUri($redirectUri, $payload);
        $codeVerifier = $this->resolvePkceVerifier($provider, $payload, $exchangeRedirectUri);
        if ($codeVerifier === '') {
            return [
                'success' => false,
                'error' => 'missing_code_verifier',
                'message' => 'Autorizacao Mercado Livre expirada. Inicie a conexao novamente.',
                'return_url' => $this->normalizeReturnUrl($payload['return_url'] ?? null),
            ];
        }

        $token = $this->mercadoLivreClient->exchangeAuthorizationCode($clientId, $clientSecret, trim($code), $exchangeRedirectUri, $codeVerifier);
        if (!is_array($token) || trim((string) ($token['access_token'] ?? '')) === '') {
            $exchangeError = $this->formatOAuthExchangeError($token);
            $this->storeProviderState($provider, [
                'last_error_code' => $exchangeError['code'],
                'last_error_message' => $exchangeError['message'],
            ]);

            return [
                'success' => false,
                'error' => 'token_exchange_failed',
                'message' => $exchangeError['message'],
                'provider_error' => $exchangeError['code'],
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
            'oauth_pkce' => null,
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

        $itemIds = $this->listUserItemIds($userId, $provider, $limit);
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

    /**
     * @return string[]
     */
    private function listUserItemIds(string $userId, People $provider, int $limit): array
    {
        $targetLimit = max(1, $limit);
        $itemIds = [];
        $offset = 0;
        $pageSize = min(100, $targetLimit);

        while (count($itemIds) < $targetLimit) {
            $remaining = $targetLimit - count($itemIds);
            $search = $this->mercadoLivreClient->searchUserItems(
                $userId,
                $provider,
                $offset,
                min($pageSize, $remaining)
            );

            $pageIds = is_array($search['results'] ?? null) ? $search['results'] : [];
            if ($pageIds === []) {
                break;
            }

            foreach ($pageIds as $itemId) {
                $itemId = trim((string) $itemId);
                if ($itemId !== '') {
                    $itemIds[$itemId] = $itemId;
                }
            }

            $paging = is_array($search['paging'] ?? null) ? $search['paging'] : [];
            $total = (int) ($paging['total'] ?? 0);
            $offset += count($pageIds);

            if (($total > 0 && $offset >= $total) || count($pageIds) < $pageSize) {
                break;
            }
        }

        return array_values($itemIds);
    }

    private function handleQueuedProductImport(Integration $integration, array $payload): void
    {
        $provider = $integration->getPeople();
        if (!$provider instanceof People) {
            $provider = $this->resolveProviderFromPayload($payload);
        }

        if (!$provider instanceof People) {
            throw new \RuntimeException('Mercado Livre product import requires a provider.');
        }

        $result = $this->importProducts(
            $provider,
            (int) ($payload['limit'] ?? 50),
            $payload['showcase_id'] ?? null
        );

        if (empty($result['success']) && is_array($result)) {
            $this->storeProviderState($provider, [
                'last_error_code' => $result['error'] ?? 'product_import_failed',
                'last_error_message' => $this->formatProductImportError($result['error'] ?? null),
            ]);
        }
    }

    private function formatProductImportError(mixed $error): string
    {
        return match ((string) $error) {
            'missing_showcase' => 'Selecione a vitrine que recebera os produtos importados.',
            'invalid_showcase' => 'A vitrine selecionada nao pertence a empresa informada.',
            'missing_user_id' => 'Conecte a conta do Mercado Livre antes de importar produtos.',
            default => 'Nao foi possivel importar os produtos do Mercado Livre.',
        };
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
        $description = $this->resolveItemDescription($remoteId, $provider);
        $category = $this->resolveMercadoLivreCategory($provider, $item);

        $product->setProduct($title !== '' ? $this->limitText($title, 255) : $remoteId);
        $product->setDescription($description !== '' ? $description : 'Produto importado do Mercado Livre.');
        $product->setPrice($price);
        $product->setActive(!in_array($status, ['closed', 'inactive', 'under_review'], true));

        $this->linkProductCategory($product, $category);
        $this->unlinkGenericMercadoLivreCategory($product, $provider, $category);
        $this->entityManager->flush();

        $sellerId = trim((string) ($item['seller_id'] ?? ''));
        if ($sellerId !== '') {
            $this->materializeProviderSellerId($provider, $sellerId);
        }

        $this->extraDataService->upsertExtraDataValue(self::APP_CONTEXT, 'Product', (int) $product->getId(), 'id', $remoteId, 'text', 'mercadolivre');
        $this->extraDataService->upsertExtraDataValue(self::APP_CONTEXT, 'Product', (int) $product->getId(), 'code', $remoteId, 'text', 'mercadolivre');
        $this->extraDataService->upsertExtraDataValue(self::APP_CONTEXT, 'Product', (int) $product->getId(), 'permalink', $permalink, 'text', 'mercadolivre');
        $this->extraDataService->upsertExtraDataValue(self::APP_CONTEXT, 'Product', (int) $product->getId(), 'seller_id', $sellerId, 'text', 'mercadolivre');
        $this->extraDataService->upsertExtraDataValue(self::APP_CONTEXT, 'Product', (int) $product->getId(), 'category_id', $item['category_id'] ?? '', 'text', 'mercadolivre');
        $this->extraDataService->upsertExtraDataValue(self::APP_CONTEXT, 'Product', (int) $product->getId(), 'listing_type_id', $item['listing_type_id'] ?? '', 'text', 'mercadolivre');
        $this->extraDataService->upsertExtraDataValue(self::APP_CONTEXT, 'Product', (int) $product->getId(), 'catalog_product_id', $item['catalog_product_id'] ?? '', 'text', 'mercadolivre');

        $settings = [
            'source' => 'mercadolivre',
            'remote_id' => $remoteId,
            'status' => $status,
            'currency_id' => $item['currency_id'] ?? null,
            'base_price' => $item['base_price'] ?? null,
            'original_price' => $item['original_price'] ?? null,
            'available_quantity' => $item['available_quantity'] ?? null,
            'sold_quantity' => $item['sold_quantity'] ?? null,
            'thumbnail' => $item['thumbnail'] ?? null,
            'secure_thumbnail' => $item['secure_thumbnail'] ?? null,
            'permalink' => $permalink !== '' ? $permalink : null,
            'category_id' => $item['category_id'] ?? null,
            'category_name' => $category->getName(),
            'condition' => $item['condition'] ?? null,
            'listing_type_id' => $item['listing_type_id'] ?? null,
            'buying_mode' => $item['buying_mode'] ?? null,
            'shipping' => is_array($item['shipping'] ?? null) ? $item['shipping'] : null,
            'attributes' => $this->normalizeMercadoLivreAttributes($item['attributes'] ?? []),
            'sale_terms' => $this->normalizeMercadoLivreAttributes($item['sale_terms'] ?? []),
            'pictures' => $this->normalizeMercadoLivrePictures($item['pictures'] ?? []),
        ];

        $this->syncProductPictures($product, $provider, $item['pictures'] ?? []);

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

    private function resolveMercadoLivreCategory(People $provider, array $item): Category
    {
        $categoryId = trim((string) ($item['category_id'] ?? ''));
        if ($categoryId === '') {
            return $this->discoverCategory($provider, 'Mercado Livre');
        }

        $categoryPayload = $this->mercadoLivreClient->getCategory($categoryId, $provider);
        if (!is_array($categoryPayload)) {
            return $this->discoverCategory($provider, 'Mercado Livre');
        }

        $path = is_array($categoryPayload['path_from_root'] ?? null) ? $categoryPayload['path_from_root'] : [];
        if ($path === []) {
            $path[] = [
                'id' => $categoryPayload['id'] ?? $categoryId,
                'name' => $categoryPayload['name'] ?? 'Mercado Livre',
            ];
        }

        $parent = null;
        $leaf = null;
        foreach ($path as $pathCategory) {
            if (!is_array($pathCategory)) {
                continue;
            }

            $name = trim((string) ($pathCategory['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $leaf = $this->discoverCategoryNode(
                $provider,
                $name,
                $parent,
                trim((string) ($pathCategory['id'] ?? ''))
            );
            $parent = $leaf;
        }

        return $leaf instanceof Category ? $leaf : $this->discoverCategory($provider, 'Mercado Livre');
    }

    private function discoverCategoryNode(People $provider, string $name, ?Category $parent, string $remoteId): Category
    {
        $category = $this->entityManager->getRepository(Category::class)->findOneBy([
            'company' => $provider,
            'context' => 'products',
            'name' => $this->limitText($name, 100),
            'parent' => $parent,
        ]);

        if (!$category instanceof Category) {
            $category = new Category();
            $category->setCompany($provider);
            $category->setContext('products');
            $category->setName($this->limitText($name, 100));
            $category->setParent($parent);
            $category->setIcon('tag');
            $category->setColor('#FFE600');
            $this->entityManager->persist($category);
            $this->entityManager->flush();
        }

        if ($remoteId !== '') {
            $this->extraDataService->upsertExtraDataValue(self::APP_CONTEXT, 'Category', (int) $category->getId(), 'id', $remoteId, 'text', 'mercadolivre');
        }

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

    private function unlinkGenericMercadoLivreCategory(Product $product, People $provider, Category $linkedCategory): void
    {
        $genericCategory = $this->entityManager->getRepository(Category::class)->findOneBy([
            'company' => $provider,
            'context' => 'products',
            'name' => 'Mercado Livre',
        ]);

        if (!$genericCategory instanceof Category || $genericCategory->getId() === $linkedCategory->getId()) {
            return;
        }

        $link = $this->entityManager->getRepository(ProductCategory::class)->findOneBy([
            'product' => $product,
            'category' => $genericCategory,
        ]);

        if ($link instanceof ProductCategory) {
            $this->entityManager->remove($link);
        }
    }

    private function resolveItemDescription(string $remoteId, People $provider): string
    {
        $description = $this->mercadoLivreClient->getItemDescription($remoteId, $provider);
        if (!is_array($description)) {
            return '';
        }

        foreach (['plain_text', 'text'] as $field) {
            $value = trim((string) ($description[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function syncProductPictures(Product $product, People $provider, mixed $pictures): void
    {
        if (!$product->getId() || !is_array($pictures)) {
            return;
        }

        foreach ($pictures as $picture) {
            if (!is_array($picture)) {
                continue;
            }

            $url = trim((string) ($picture['secure_url'] ?? $picture['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $file = $this->resolvePictureFile($provider, $url, trim((string) ($picture['id'] ?? '')));
            if (!$file instanceof File) {
                continue;
            }

            $this->linkProductFile($product, $file);
        }
    }

    private function resolvePictureFile(People $provider, string $url, string $pictureId): ?File
    {
        $file = $this->extraDataService->getEntityByExtraData(self::APP_CONTEXT, 'source_url', $url, File::class);
        if ($file instanceof File) {
            return $file;
        }

        $download = $this->mercadoLivreClient->downloadPublicFile($url);
        if (!is_array($download)) {
            return null;
        }

        $contentType = strtolower(trim((string) ($download['content_type'] ?? 'application/octet-stream')));
        if (!str_starts_with($contentType, 'image/')) {
            return null;
        }

        $extension = $this->extensionFromContentType($contentType);
        $fileName = $this->limitText(($pictureId !== '' ? $pictureId : hash('sha256', $url)) . '.' . $extension, 255);
        $file = $this->fileService->addFile(
            $provider,
            (string) $download['content'],
            'product',
            $fileName,
            'image',
            $extension,
            true
        );

        $this->extraDataService->upsertExtraDataValue(self::APP_CONTEXT, 'File', (int) $file->getId(), 'source_url', $url, 'text', 'mercadolivre');
        if ($pictureId !== '') {
            $this->extraDataService->upsertExtraDataValue(self::APP_CONTEXT, 'File', (int) $file->getId(), 'id', $pictureId, 'text', 'mercadolivre');
        }

        return $file;
    }

    private function linkProductFile(Product $product, File $file): void
    {
        $link = $this->entityManager->getRepository(ProductFile::class)->findOneBy([
            'product' => $product,
            'file' => $file,
        ]);

        if ($link instanceof ProductFile) {
            return;
        }

        $link = new ProductFile();
        $link->setProduct($product);
        $link->setFile($file);
        $this->entityManager->persist($link);
    }

    private function extensionFromContentType(string $contentType): string
    {
        $type = strtolower(trim(explode(';', $contentType)[0]));

        return match ($type) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };
    }

    private function normalizeMercadoLivreAttributes(mixed $attributes): array
    {
        if (!is_array($attributes)) {
            return [];
        }

        return array_values(array_filter(array_map(static function (mixed $attribute): ?array {
            if (!is_array($attribute)) {
                return null;
            }

            $id = trim((string) ($attribute['id'] ?? ''));
            $name = trim((string) ($attribute['name'] ?? ''));
            $value = trim((string) ($attribute['value_name'] ?? $attribute['value_id'] ?? ''));

            if ($id === '' && $name === '' && $value === '') {
                return null;
            }

            return array_filter([
                'id' => $id !== '' ? $id : null,
                'name' => $name !== '' ? $name : null,
                'value' => $value !== '' ? $value : null,
            ], static fn(mixed $field): bool => $field !== null && $field !== '');
        }, $attributes)));
    }

    private function normalizeMercadoLivrePictures(mixed $pictures): array
    {
        if (!is_array($pictures)) {
            return [];
        }

        return array_values(array_filter(array_map(static function (mixed $picture): ?array {
            if (!is_array($picture)) {
                return null;
            }

            $url = trim((string) ($picture['secure_url'] ?? $picture['url'] ?? ''));
            if ($url === '') {
                return null;
            }

            return array_filter([
                'id' => trim((string) ($picture['id'] ?? '')) ?: null,
                'url' => $url,
                'size' => trim((string) ($picture['size'] ?? '')) ?: null,
                'max_size' => trim((string) ($picture['max_size'] ?? '')) ?: null,
            ], static fn(mixed $field): bool => $field !== null && $field !== '');
        }, $pictures)));
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

        if (!$config->getModule() instanceof Module) {
            $config->setModule($this->resolveConfigModule());
        }

        $config->setConfigValue($value);
        $config->setVisibility('private');
        $this->entityManager->persist($config);
        $this->entityManager->flush();
    }

    private function resolveConfigModule(): Module
    {
        return $this->configService->discoveryModule(
            self::CONFIG_MODULE_NAME,
            '$primary',
            'link',
            'Integracoes'
        );
    }

    private function buildOAuthCallbackUrl(string $apiBaseUrl): string
    {
        return rtrim($apiBaseUrl, '/') . '/oauth/mercadolivre/return';
    }

    private function resolveOAuthRedirectUri(string $apiBaseUrl, string $appDomain): string
    {
        return $this->buildOAuthCallbackUrl($apiBaseUrl);
    }

    private function resolveOAuthExchangeRedirectUri(string $redirectUri, array $payload): string
    {
        $stateRedirectUri = trim((string) ($payload['redirect_uri'] ?? ''));

        if ($stateRedirectUri !== '') {
            return $stateRedirectUri;
        }

        return trim($redirectUri);
    }

    private function createPkceState(People $provider, string $redirectUri): array
    {
        $verifier = $this->base64UrlEncode(random_bytes(48));
        $nonce = $this->base64UrlEncode(random_bytes(24));
        $challenge = $this->base64UrlEncode(hash('sha256', $verifier, true));

        $this->storeProviderState($provider, [
            'oauth_pkce' => [
                'nonce' => $nonce,
                'code_verifier' => $verifier,
                'redirect_uri' => $redirectUri,
                'issued_at' => time(),
            ],
        ]);

        return [
            'nonce' => $nonce,
            'challenge' => $challenge,
        ];
    }

    private function resolvePkceVerifier(People $provider, array $payload, string $redirectUri): string
    {
        $nonce = trim((string) ($payload['oauth_nonce'] ?? ''));
        if ($nonce === '') {
            return '';
        }

        $pkce = $this->getProviderState($provider)['oauth_pkce'] ?? null;
        if (!is_array($pkce)) {
            return '';
        }

        if (!hash_equals(trim((string) ($pkce['nonce'] ?? '')), $nonce)) {
            return '';
        }

        if (trim((string) ($pkce['redirect_uri'] ?? '')) !== trim($redirectUri)) {
            return '';
        }

        $issuedAt = (int) ($pkce['issued_at'] ?? 0);
        if ($issuedAt <= 0 || $issuedAt < (time() - 3600)) {
            return '';
        }

        return trim((string) ($pkce['code_verifier'] ?? ''));
    }

    private function formatOAuthExchangeError(?array $token): array
    {
        if (!is_array($token)) {
            return [
                'code' => 'empty_token_response',
                'message' => 'Mercado Livre nao retornou o token de acesso.',
            ];
        }

        $code = trim((string) (
            $token['error']
            ?? $token['code']
            ?? $token['status']
            ?? 'token_exchange_failed'
        ));

        $message = trim((string) (
            $token['message']
            ?? $token['error_description']
            ?? $token['description']
            ?? ''
        ));

        if ($message === '' && is_array($token['cause'] ?? null)) {
            foreach ($token['cause'] as $cause) {
                if (is_array($cause)) {
                    $message = trim((string) ($cause['message'] ?? $cause['description'] ?? ''));
                    if ($message !== '') {
                        break;
                    }
                }
            }
        }

        return [
            'code' => $code !== '' ? substr($code, 0, 80) : 'token_exchange_failed',
            'message' => $message !== '' ? substr($message, 0, 180) : 'Nao foi possivel concluir a autorizacao no Mercado Livre.',
        ];
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

    private function normalizeOAuthDomain(mixed $domain): string
    {
        if (!is_string($domain)) {
            return '';
        }

        $domain = strtolower(trim($domain));
        if ($domain === '' || in_array($domain, ['undefined', 'null', 'false'], true)) {
            return '';
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $domain)) {
            $host = parse_url($domain, PHP_URL_HOST);
            $domain = is_string($host) ? $host : '';
        }

        $domain = preg_replace('/[\/?#].*$/', '', $domain) ?? '';
        $domain = preg_replace('/[^a-z0-9.:-]/', '', $domain) ?? '';

        return $domain;
    }

    private function resolveClientId(?People $provider = null): string
    {
        return $this->resolveConfiguredValue(
            $provider,
            [self::CONFIG_CLIENT_ID, 'OAUTH_MERCADO_LIVRE_APP_ID', 'mercado_livre_app_id', 'mercadolivre_app_id'],
            ['OAUTH_MERCADO_LIVRE_CLIENT_ID', 'OAUTH_MERCADO_LIVRE_APP_ID']
        );
    }

    private function resolveClientSecret(?People $provider = null): string
    {
        return $this->resolveConfiguredValue(
            $provider,
            [self::CONFIG_CLIENT_SECRET, 'OAUTH_MERCADO_LIVRE_APP_SECRET', 'mercado_livre_app_secret', 'mercadolivre_app_secret'],
            ['OAUTH_MERCADO_LIVRE_CLIENT_SECRET', 'OAUTH_MERCADO_LIVRE_APP_SECRET']
        );
    }

    private function resolveConfiguredValue(?People $provider, array $configKeys, array $environmentKeys): string
    {
        foreach ($this->resolveConfigCompanies($provider) as $company) {
            foreach ($configKeys as $configKey) {
                $configuredValue = $this->readConfigValue($company, $configKey);
                if ($configuredValue !== '') {
                    return $configuredValue;
                }
            }
        }

        foreach ($environmentKeys as $environmentKey) {
            $environmentValue = trim((string) (
                $_ENV[$environmentKey]
                ?? $_SERVER[$environmentKey]
                ?? getenv($environmentKey)
                ?: ''
            ));

            if ($environmentValue !== '') {
                return $environmentValue;
            }
        }

        return '';
    }

    /**
     * Integration app credentials belong to the domain main company; seller tokens
     * still remain scoped to the selected provider.
     */
    private function resolveConfigCompanies(?People $provider): array
    {
        $companies = [];

        if ($provider instanceof People) {
            $companies[] = $provider;
        }

        try {
            $mainCompany = $this->peopleRoleService->getMainCompany();
            if ($mainCompany instanceof People) {
                foreach ($companies as $company) {
                    if ((int) $company->getId() === (int) $mainCompany->getId()) {
                        return $companies;
                    }
                }

                $companies[] = $mainCompany;
            }
        } catch (\Throwable) {
        }

        $tenantMainCompany = $this->entityManager->getRepository(People::class)->find(1);
        if ($tenantMainCompany instanceof People) {
            foreach ($companies as $company) {
                if ((int) $company->getId() === (int) $tenantMainCompany->getId()) {
                    return $companies;
                }
            }

            $companies[] = $tenantMainCompany;
        }

        return $companies;
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
