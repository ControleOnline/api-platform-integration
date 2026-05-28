<?php

namespace ControleOnline\Service\Marketplace;

use ControleOnline\Service\AddressService;
use ControleOnline\Entity\Address;
use ControleOnline\Entity\Category;
use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Invoice;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\OrderProductQueue;
use ControleOnline\Entity\PaymentType;
use ControleOnline\Entity\ExtraData;
use ControleOnline\Entity\File;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductGroup;
use ControleOnline\Entity\ProductGroupProduct;
use ControleOnline\Entity\ProductUnity;
use ControleOnline\Entity\Status;
use ControleOnline\Entity\User;
use ControleOnline\Entity\Wallet;
use ControleOnline\Entity\WalletPaymentType;
use ControleOnline\Service\iFoodService;
use ControleOnline\Service\Marketplace\AbstractMarketplaceService;
use ControleOnline\Service\Client\WebsocketClient;
use ControleOnline\Service\Marketplace\MarketplaceIntegrationHandlerInterface;
use ControleOnline\Service\Marketplace\MarketplaceIntegrationStateProviderInterface;
use ControleOnline\Service\Marketplace\MarketplaceLogisticsQuoteProviderInterface;
use ControleOnline\Service\Marketplace\MarketplaceOrderSnapshotProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use ControleOnline\Service\LoggerService;
use DateTime;
use ControleOnline\Event\EntityChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class IfoodCatalogOperationsService extends AbstractMarketplaceService
{
    private const APP_CONTEXT = Order::APP_IFOOD;
    private const MAX_IMAGE_UPLOAD_BYTES = 5242880;
    private const IMAGE_UPLOAD_PAYLOAD_MARGIN_BYTES = 512;
    private const IMAGE_UPLOAD_MAX_DIMENSION = 3000;
    private const CATALOG_CONCURRENT_RETRY_DELAYS_US = [500000, 1500000, 3000000, 5000000];

    protected function getMarketplaceApp(): string
    {
        return self::APP_CONTEXT;
    }

    private function callIfoodStoreServiceMethod(string $method, array $arguments = []): mixed
    {
        $service = $this->resolveMarketplaceServiceInstance(IfoodStoreOperationsService::class);
        if (!is_object($service)) {
            return null;
        }

        return $this->invokeMarketplaceServiceMethod($service, $method, $arguments);
    }

    private function getIfoodExtraDataValue(string $entityName, int $entityId, string $fieldName = 'code'): ?string
    {
        return $this->extraDataService->getExtraDataValue(
            Order::APP_IFOOD,
            $entityName,
            $entityId,
            $fieldName
        );
    }

    private function upsertIfoodExtraDataValue(string $entityName, int $entityId, string $fieldName, mixed $value): void
    {
        $this->extraDataService->upsertExtraDataValue(
            Order::APP_IFOOD,
            $entityName,
            $entityId,
            $fieldName,
            $value,
            'text',
            self::APP_CONTEXT
        );
    }

    private function getAccessToken(): ?string
    {
        return $this->ifoodClient->getAccessToken();
    }

    /* Catalog v2                                                          */
    /* ------------------------------------------------------------------ */

    private const CATALOG_V2_BASE = 'https://merchant-api.ifood.com.br/catalog/v2.0/merchants/';

    private function resolveIfoodStoreOperationsService(): ?IfoodStoreOperationsService
    {
        $service = $this->resolveMarketplaceServiceInstance(IfoodStoreOperationsService::class);

        return $service instanceof IfoodStoreOperationsService ? $service : null;
    }

    public function getStoredIntegrationState(People $provider, bool $includeAuthCheck = false): array
    {
        $storeService = $this->resolveIfoodStoreOperationsService();

        return $storeService instanceof IfoodStoreOperationsService
            ? $storeService->getStoredIntegrationState($provider, $includeAuthCheck)
            : [];
    }

    public function listSelectableMenuProducts(People $provider): array
    {
        $this->init();

        $state      = $this->getStoredIntegrationState($provider);
        $merchantId = $this->normalizeString($state['merchant_id'] ?? null);

        $rows = $this->fetchCatalogProducts($provider);

        /* mapa de externalCodes já publicados no catálogo iFood */
        $remoteByEc = [];
        if ($merchantId !== '') {
            $remoteByEc = $this->buildIfoodCatalogItemExternalCodeIndex(
                $merchantId,
                $this->fetchIfoodCatalogItemsV2($merchantId),
                true
            );
        }

        $products = array_map(
            fn(array $row) => $this->buildIfoodMenuProductView($row, $remoteByEc),
            $rows
        );

        return [
            'provider_id'           => $provider->getId(),
            'minimum_required_items' => 1,
            'eligible_product_count' => count(array_filter($products, fn(array $p) => $p['eligible'])),
            'products'              => $products,
        ];
    }

    private function buildIfoodMenuProductView(array $row, array $remoteByEc): array
    {
        $productId    = (int) ($row['id'] ?? 0);
        $name         = trim((string) ($row['name'] ?? ''));
        $price        = round((float) ($row['price'] ?? 0), 2);
        $type         = strtolower(trim((string) ($row['type'] ?? '')));
        $categoryId   = isset($row['category_id']) && $row['category_id'] !== null ? (int) $row['category_id'] : null;
        $categoryName = $categoryId !== null ? trim((string) ($row['category_name'] ?? '')) : null;
        $modifierGroups = is_array($row['modifier_groups'] ?? null) ? $row['modifier_groups'] : [];
        $modifierGroups = $this->enrichIfoodModifierGroupsWithRemoteOptions($modifierGroups, $remoteByEc);
        $localSyncMarker = $this->getIfoodExtraDataValue('Product', $productId, 'sync_synced_at') !== null;
        $hasRequiredModifiers = !empty(array_filter($modifierGroups, static function (array $group): bool {
            return !empty($group['required']) || (int) ($group['minimum'] ?? 0) >= 1;
        }));
        $allowZeroPriceByGroups = $type === 'custom' && $hasRequiredModifiers;

        $blockers = [];
        if ($name === '')    $blockers[] = 'Produto sem nome';
        if (!$categoryId)    $blockers[] = 'Produto sem categoria';
        if ($price <= 0 && !$allowZeroPriceByGroups) $blockers[] = 'Produto com preco invalido';

        $ec = (string) $productId;

        $remoteEntry = $remoteByEc[$ec] ?? null;

        $view = [
            'id'               => $productId,
            'name'             => $name,
            'description'      => trim((string) ($row['description'] ?? '')),
            'price'            => $price,
            'type'             => (string) ($row['type'] ?? ''),
            'category'         => $categoryId !== null ? ['id' => $categoryId, 'name' => $categoryName] : null,
            'has_required_modifiers' => $hasRequiredModifiers,
            'modifier_groups'  => $modifierGroups,
            'options'          => $this->flattenIfoodModifierOptions($modifierGroups),
            'active'           => (int) ($row['product_active'] ?? 1) === 1,
            'eligible'         => empty($blockers),
            'blockers'         => $blockers,
            'published_remotely' => $remoteEntry !== null || $localSyncMarker,
            'ifood_item_id'    => !empty($remoteEntry['option_id']) ? null : ($remoteEntry['item_id'] ?? null),
            'ifood_option_id'  => $remoteEntry['option_id'] ?? null,
            'ifood_status'     => $remoteEntry['status'] ?? null,
            'ifood_match_source' => $remoteEntry['match_source'] ?? null,
            'cover_image_url'  => $this->buildPublicFileDownloadUrl($row['cover_file_id'] ?? null),
        ];

        $view['sync'] = $this->buildIfoodProductSyncState($view, $remoteEntry !== null || $localSyncMarker);

        return $view;
    }

    private function enrichIfoodModifierGroupsWithRemoteOptions(array $modifierGroups, array $remoteByEc): array
    {
        return array_map(function (array $group) use ($remoteByEc): array {
            $options = is_array($group['options'] ?? null) ? $group['options'] : [];
            $group['options'] = array_map(function (array $option) use ($remoteByEc): array {
                $relationId = (int) ($option['id'] ?? 0);
                $remoteEntry = null;

                foreach ([(string) $relationId] as $candidate) {
                    if ($candidate !== '' && !empty($remoteByEc[$candidate]['option_id'])) {
                        $remoteEntry = $remoteByEc[$candidate];
                        break;
                    }
                }

                if ($remoteEntry) {
                    $option['ifood_option_id'] = $remoteEntry['option_id'];
                    $option['ifood_status'] = $remoteEntry['status'] ?? null;
                    $option['ifood_item_id'] = $remoteEntry['item_id'] ?? null;
                    $option['ifood_match_source'] = $remoteEntry['match_source'] ?? null;
                }

                return $option;
            }, $options);

            return $group;
        }, $modifierGroups);
    }

    private function flattenIfoodModifierOptions(array $modifierGroups): array
    {
        $options = [];
        foreach ($modifierGroups as $group) {
            foreach (($group['options'] ?? []) as $option) {
                if (is_array($option)) {
                    $options[] = $option;
                }
            }
        }

        return $options;
    }

    private function normalizeCatalogSyncHashPayload(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeCatalogSyncHashPayload($item);
            }

            if (!array_is_list($normalized)) {
                ksort($normalized);
            }

            return $normalized;
        }

        if (is_float($value)) {
            return round($value, 4);
        }

        if (is_bool($value) || is_int($value) || is_string($value) || $value === null) {
            return $value;
        }

        return (string) $value;
    }

    private function buildCatalogSyncHash(array $payload): string
    {
        $json = json_encode(
            $this->normalizeCatalogSyncHashPayload($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return hash('sha256', is_string($json) ? $json : '');
    }

    private function buildIfoodProductSyncHash(array $product): string
    {
        return $this->buildCatalogSyncHash([
            'id' => (int) ($product['id'] ?? 0),
            'name' => trim((string) ($product['name'] ?? '')),
            'description' => trim((string) ($product['description'] ?? '')),
            'price' => round((float) ($product['price'] ?? 0), 2),
            'type' => trim((string) ($product['type'] ?? '')),
            'active' => !array_key_exists('active', $product) || (bool) $product['active'],
            'category_id' => (int) ($product['category']['id'] ?? 0),
            'category_name' => trim((string) ($product['category']['name'] ?? '')),
            'has_required_modifiers' => !empty($product['has_required_modifiers']),
            'modifier_groups' => $product['modifier_groups'] ?? [],
            'cover_image_url' => trim((string) ($product['cover_image_url'] ?? '')),
        ]);
    }

    private function buildIfoodCategorySyncHash(array $category): string
    {
        return $this->buildCatalogSyncHash([
            'id' => (int) ($category['id'] ?? 0),
            'name' => trim((string) ($category['name'] ?? '')),
            'color' => trim((string) ($category['color'] ?? '')),
            'icon' => trim((string) ($category['icon'] ?? '')),
            'parent_id' => (int) ($category['parent_id'] ?? 0),
        ]);
    }

    private function buildIfoodProductSyncState(array $product, ?bool $publishedOverride = null): array
    {
        $productId = (int) ($product['id'] ?? 0);
        $published = $publishedOverride ?? !empty($product['published_remotely']);
        $currentHash = $this->buildIfoodProductSyncHash($product);
        $storedHash = $this->getIfoodExtraDataValue('Product', $productId, 'sync_hash') ?? '';
        $hasStoredHash = $storedHash !== '';
        $dirty = $published && (!$hasStoredHash || !hash_equals($storedHash, $currentHash));
        $remoteId = trim((string) ($product['ifood_item_id'] ?? ($product['ifood_option_id'] ?? '')));

        return [
            'platform' => 'ifood',
            'remote_id' => $remoteId !== '' ? $remoteId : null,
            'published' => (bool) $published,
            'eligible' => !empty($product['eligible']),
            'synced' => (bool) ($published && !$dirty),
            'dirty' => (bool) $dirty,
            'last_synced_at' => $this->getIfoodExtraDataValue('Product', $productId, 'sync_synced_at'),
            'status' => !$published ? 'not_synced' : ($dirty ? 'dirty' : 'synced'),
        ];
    }

    private function buildIfoodCategorySyncState(array $category, array $productIds, bool $published, bool $eligible): array
    {
        $categoryId = (int) ($category['id'] ?? 0);
        $currentHash = $this->buildIfoodCategorySyncHash($category);
        $storedHash = $this->getIfoodExtraDataValue('Category', $categoryId, 'sync_hash') ?? '';
        $hasStoredHash = $storedHash !== '';
        $dirty = $published && (!$hasStoredHash || !hash_equals($storedHash, $currentHash));
        $remoteId = $this->getIfoodExtraDataValue('Category', $categoryId, 'code');

        return [
            'platform' => 'ifood',
            'remote_id' => $remoteId,
            'published' => (bool) $published,
            'eligible' => $eligible,
            'synced' => (bool) ($published && !$dirty),
            'dirty' => (bool) $dirty,
            'last_synced_at' => $this->getIfoodExtraDataValue('Category', $categoryId, 'sync_synced_at'),
            'status' => !$published ? 'not_synced' : ($dirty ? 'dirty' : 'synced'),
            'product_ids' => array_values(array_unique(array_map('intval', $productIds))),
        ];
    }

    private function fetchCatalogCategories(People $provider): array
    {
        $sql = <<<SQL
            SELECT
                c.id,
                c.name,
                c.color,
                c.icon,
                c.parent_id
            FROM category c
            WHERE c.company_id = :providerId
              AND c.context = 'products'
            ORDER BY c.name ASC
        SQL;

        return $this->entityManager->getConnection()->fetchAllAssociative($sql, [
            'providerId' => (int) $provider->getId(),
        ]);
    }

    public function getCatalogSyncStatus(People $provider): array
    {
        $this->init();

        $storedState = $this->getStoredIntegrationState($provider);
        $productsResponse = $this->listSelectableMenuProducts($provider);
        $products = is_array($productsResponse['products'] ?? null) ? $productsResponse['products'] : [];
        $categories = $this->fetchCatalogCategories($provider);
        $categoryProductIds = [];
        $categoryEligibleProductIds = [];
        $categoryPublished = [];
        $eligibleProductIds = [];
        $publishedProductIds = [];

        $productStatuses = array_map(function (array $product) use (&$categoryProductIds, &$categoryEligibleProductIds, &$categoryPublished, &$eligibleProductIds, &$publishedProductIds): array {
            $productId = (int) ($product['id'] ?? 0);
            $categoryId = (int) ($product['category']['id'] ?? 0);
            $sync = is_array($product['sync'] ?? null) ? $product['sync'] : $this->buildIfoodProductSyncState($product);

            if ($categoryId > 0 && $productId > 0) {
                $categoryProductIds[$categoryId][] = $productId;
            }

            if (!empty($sync['eligible']) && $productId > 0) {
                $eligibleProductIds[] = $productId;
                if ($categoryId > 0) {
                    $categoryEligibleProductIds[$categoryId][] = $productId;
                }
            }

            if (!empty($sync['published']) && $productId > 0) {
                $publishedProductIds[] = $productId;
                if ($categoryId > 0) {
                    $categoryPublished[$categoryId] = true;
                }
            }

            return array_merge($sync, [
                'id' => $productId,
                'name' => $product['name'] ?? null,
                'category_id' => $categoryId ?: null,
                'blockers' => $product['blockers'] ?? [],
            ]);
        }, $products);

        $categoryStatuses = array_map(function (array $category) use ($categoryProductIds, $categoryEligibleProductIds, $categoryPublished): array {
            $categoryId = (int) ($category['id'] ?? 0);
            $productIds = $categoryProductIds[$categoryId] ?? [];
            $eligibleIds = $categoryEligibleProductIds[$categoryId] ?? [];
            $sync = $this->buildIfoodCategorySyncState(
                $category,
                $productIds,
                !empty($categoryPublished[$categoryId]) || $this->getIfoodExtraDataValue('Category', $categoryId, 'code') !== null,
                !empty($eligibleIds)
            );

            return array_merge($sync, [
                'id' => $categoryId,
                'name' => $category['name'] ?? null,
                'eligible_product_ids' => array_values(array_unique(array_map('intval', $eligibleIds))),
            ]);
        }, $categories);

        return [
            'platform' => [
                'key' => 'ifood',
                'label' => 'iFood',
                'active' => !empty($storedState['connected']) || !empty($storedState['remote_connected']),
                'connected' => !empty($storedState['connected']),
                'remote_connected' => !empty($storedState['remote_connected']),
                'store_code' => $storedState['ifood_code'] ?? $storedState['merchant_id'] ?? null,
                'last_sync_at' => $storedState['last_sync_at'] ?? null,
                'last_error_message' => $storedState['last_error_message'] ?? null,
            ],
            'products' => $productStatuses,
            'categories' => $categoryStatuses,
            'eligible_product_ids' => array_values(array_unique(array_map('intval', $eligibleProductIds))),
            'published_product_ids' => array_values(array_unique(array_map('intval', $publishedProductIds))),
            'minimum_required_items' => (int) ($productsResponse['minimum_required_items'] ?? 1),
        ];
    }

    private function markCategoriesCatalogSynced(People $provider, array $categoryIds): void
    {
        $categoryIdSet = array_flip(array_map('intval', $categoryIds));
        if (empty($categoryIdSet)) {
            return;
        }

        foreach ($this->fetchCatalogCategories($provider) as $category) {
            $categoryId = (int) ($category['id'] ?? 0);
            if ($categoryId <= 0 || !isset($categoryIdSet[$categoryId])) {
                continue;
            }

            $this->upsertIfoodExtraDataValue('Category', $categoryId, 'sync_hash', $this->buildIfoodCategorySyncHash($category));
            $this->upsertIfoodExtraDataValue('Category', $categoryId, 'sync_synced_at', date('Y-m-d H:i:s'));
        }
    }

    public function markProductsCatalogSynced(People $provider, array $productIds): void
    {
        $this->init();

        $rows = $this->fetchCatalogProducts($provider);
        $rows = $this->expandCatalogProductsWithModifierDescendants($rows, $this->normalizeProductIds($productIds));
        if (empty($rows)) {
            return;
        }

        $categoryIds = [];
        foreach ($rows as $row) {
            $product = $this->buildIfoodMenuProductView($row, []);
            $productId = (int) ($product['id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $this->upsertIfoodExtraDataValue('Product', $productId, 'sync_hash', $this->buildIfoodProductSyncHash($product));
            $this->upsertIfoodExtraDataValue('Product', $productId, 'sync_synced_at', date('Y-m-d H:i:s'));

            $categoryId = (int) ($product['category']['id'] ?? 0);
            if ($categoryId > 0) {
                $categoryIds[] = $categoryId;
            }
        }

        $this->markCategoriesCatalogSynced($provider, $categoryIds);
    }

    public function publishMenu(People $provider, array $productIds = []): array
    {
        $this->init();

        $state      = $this->getStoredIntegrationState($provider);
        $merchantId = $this->normalizeString($state['merchant_id'] ?? null);
        if ($merchantId === '') {
            return ['errno' => 10002, 'errmsg' => 'Loja iFood nao conectada. Vincule o merchant_id antes de publicar o cardapio.'];
        }

        /* resolve catalogId */
        $catalogId  = $this->fetchIfoodDefaultCatalogId($merchantId);
        if ($catalogId === null) {
            return ['errno' => 10003, 'errmsg' => 'Nao foi possivel obter o catalogo iFood da loja.'];
        }

        /* produtos locais */
        $allProducts = $this->fetchCatalogProducts($provider);
        if (!empty($productIds)) {
            $idSet = array_flip(array_map('strval', $productIds));
            $allProducts = array_values(array_filter(
                $allProducts,
                fn(array $p) => isset($idSet[(string) $p['id']])
            ));
        }
        if (empty($allProducts)) {
            return ['errno' => 10002, 'errmsg' => 'Nenhum produto selecionado para publicar no iFood.'];
        }

        $remoteCategories = $this->fetchIfoodCatalogCategoriesV2($merchantId, $catalogId);
        $remoteCategoriesByName = [];
        foreach ($remoteCategories as $remoteCategory) {
            $remoteCategoryName = $this->normalizeString($remoteCategory['name'] ?? null);
            $remoteCategoryId   = $this->normalizeString($remoteCategory['id'] ?? null);
            if ($remoteCategoryName !== '' && $remoteCategoryId !== '') {
                $remoteCategoriesByName[$this->normalizeText($remoteCategoryName)] = $remoteCategoryId;
            }
        }

        $groupedProducts = [];
        foreach ($allProducts as $prod) {
            $groupCategoryId   = $this->normalizeString($prod['category_id'] ?? null);
            $groupCategoryName = $this->normalizeString($prod['category_name'] ?? null);
            $groupIfoodCode    = $this->normalizeString($prod['ifood_category_code'] ?? null);
            $groupKey          = $groupCategoryId !== '' ? $groupCategoryId : ($groupCategoryName !== '' ? $groupCategoryName : '__default__');

            if (!isset($groupedProducts[$groupKey])) {
                $groupedProducts[$groupKey] = [
                    'category_id'        => $groupCategoryId,
                    'category_name'      => $groupCategoryName !== '' ? $groupCategoryName : 'Produtos',
                    'ifood_category_code' => $groupIfoodCode,
                    'products'           => [],
                ];
            }

            $groupedProducts[$groupKey]['products'][] = $prod;
        }

        /* mapa de itens existentes por externalCode */
        $remoteItems = $this->fetchIfoodCatalogItemsV2($merchantId);
        $byEc        = $this->buildIfoodCatalogItemExternalCodeIndex($merchantId, $remoteItems, true);

        $pushed = 0;
        $errors = [];
        $pushedProductIds = [];
        $sequence = 0;
        foreach ($groupedProducts as $group) {
            $sequence++;
            $resolvedCategoryId = $this->resolveIfoodCatalogCategoryId(
                $merchantId,
                $catalogId,
                $group['category_name'],
                $remoteCategoriesByName,
                $sequence,
                (int) ($group['category_id'] ?: 0),
                $group['ifood_category_code'] ?? ''
            );

            if ($resolvedCategoryId === null) {
                $errors[] = [
                    'product_id'   => null,
                    'product_name' => $group['category_name'],
                    'http_status'  => null,
                    'ifood_body'   => null,
                    'error'        => 'Nao foi possivel obter ou criar a categoria no iFood.',
                    'sent_payload' => null,
                ];
                continue;
            }

            foreach ($group['products'] as $prod) {
                $ec       = (string) $prod['id'];
                $existing = $byEc[$ec] ?? null;
                if ($existing === null) {
                    $fallbackRemoteItem = $this->findIfoodCatalogRemoteItemByProductFallback($remoteItems, $prod);
                    if (is_array($fallbackRemoteItem)) {
                        $existing = [
                            'item_id' => $this->normalizeString($fallbackRemoteItem['id'] ?? null),
                            'product_id' => $this->normalizeString($fallbackRemoteItem['productId'] ?? null),
                            'category_id' => $this->normalizeString($fallbackRemoteItem['_categoryId'] ?? null),
                        ];
                    }
                }

                $existingFlat = null;
                if (!empty($existing['item_id'])) {
                    $existingFlat = $this->fetchIfoodCatalogItemFlatV2($merchantId, $existing['item_id']);
                }

                $result = $this->upsertIfoodCatalogItemV2($merchantId, $prod, $existing, $resolvedCategoryId, $existingFlat);
                if ($result['ok']) {
                    $pushed++;
                    $pushedProductIds[] = (int) ($prod['id'] ?? 0);
                } else {
                    $errors[] = [
                        'product_id'   => $prod['id'],
                        'product_name' => $prod['name'] ?? '',
                        'http_status'  => $result['http_status'],
                        'ifood_body'   => $result['ifood_body'],
                        'error'        => $result['error'],
                        'sent_payload' => $result['sent_payload'] ?? null,
                    ];
                    self::$logger->warning('iFood catalog v2 upsert failed', [
                        'product_id'  => $prod['id'],
                        'http_status' => $result['http_status'],
                        'ifood_body'  => $result['ifood_body'],
                        'error'       => $result['error'],
                    ]);
                }
            }
        }

        /* atualiza published_remotely nos produtos locais */
        if ($pushed > 0) {
            $this->syncCatalogFromIfood($provider);
            $this->markProductsCatalogSynced($provider, $pushedProductIds);
        }

        $errno  = $pushed > 0 ? 0 : 1;
        $errmsg = $errno === 0 ? 'ok' : 'Nenhum produto publicado com sucesso.';

        return [
            'errno'  => $errno,
            'errmsg' => $errmsg,
            'data'   => [
                'merchant_id'    => $merchantId,
                'pushed_count'   => $pushed,
                'total_products' => count($allProducts),
                'error_count'    => count($errors),
                'errors'         => $errors,
            ],
        ];
    }

    private function expandCatalogProductsWithModifierDescendants(array $products, array $selectedProductIds): array
    {
        if (empty($selectedProductIds) || empty($products)) {
            return $products;
        }

        $productsById = [];
        foreach ($products as $product) {
            $productId = (int) ($product['id'] ?? 0);
            if ($productId > 0) {
                $productsById[$productId] = $product;
            }
        }

        if (empty($productsById)) {
            return [];
        }

        $includedProductIds = [];
        $queue = [];
        foreach ($selectedProductIds as $selectedProductId) {
            $productId = (int) $selectedProductId;
            if ($productId <= 0 || !isset($productsById[$productId]) || isset($includedProductIds[$productId])) {
                continue;
            }

            $includedProductIds[$productId] = true;
            $queue[] = $productId;
        }

        while (!empty($queue)) {
            $currentProductId = array_shift($queue);
            $currentProduct = $productsById[$currentProductId] ?? null;
            if (!is_array($currentProduct)) {
                continue;
            }

            $modifierGroups = is_array($currentProduct['modifier_groups'] ?? null) ? $currentProduct['modifier_groups'] : [];
            foreach ($modifierGroups as $group) {
                $options = is_array($group['options'] ?? null) ? $group['options'] : [];
                foreach ($options as $option) {
                    $childProductId = (int) ($option['child_product_id'] ?? 0);
                    if ($childProductId <= 0 || !isset($productsById[$childProductId]) || isset($includedProductIds[$childProductId])) {
                        continue;
                    }

                    $includedProductIds[$childProductId] = true;
                    $queue[] = $childProductId;
                }
            }
        }

        return array_values(array_filter(
            $products,
            static fn(array $product): bool => isset($includedProductIds[(int) ($product['id'] ?? 0)])
        ));
    }

    public function syncCatalogFromIfood(People $provider): array
    {
        $this->init();

        $state      = $this->getStoredIntegrationState($provider);
        $merchantId = $this->normalizeString($state['merchant_id'] ?? null);
        if ($merchantId === '') {
            return ['errno' => 10002, 'errmsg' => 'Loja iFood nao conectada.'];
        }

        $items = $this->fetchIfoodCatalogItemsV2($merchantId);
        if (empty($items)) {
            return ['errno' => 0, 'errmsg' => 'Nenhum item encontrado no catalogo iFood.', 'data' => ['synced' => 0, 'total' => 0]];
        }

        $remoteByEc = $this->buildIfoodCatalogItemExternalCodeIndex($merchantId, $items, true);

        $synced = 0;
        $syncedProductIds = [];
        $syncedProductIdSet = [];
        $syncedItemIds = [];
        foreach ($remoteByEc as $externalCode => $remoteEntry) {
            $itemId = $this->normalizeString($remoteEntry['item_id'] ?? null);
            $optionId = $this->normalizeString($remoteEntry['option_id'] ?? null);
            if ($itemId === '' || !ctype_digit((string) $externalCode)) {
                continue;
            }

            $product = $this->entityManager->getRepository(Product::class)->findOneBy([
                'company' => $provider,
                'id'      => (int) $externalCode,
            ]);

            if ($product) {
                $productId = (int) $product->getId();
                if (isset($syncedProductIdSet[$productId])) {
                    continue;
                }
                if ($optionId === '' && !isset($syncedItemIds[$itemId])) {
                    $this->discoveryFoodCode($product, $itemId);
                    $syncedItemIds[$itemId] = true;
                }
                $synced++;
                $syncedProductIdSet[$productId] = true;
                $syncedProductIds[] = $productId;
            }
        }

        foreach ($items as $item) {
            $itemId       = $this->normalizeString($item['id'] ?? null);
            $itemName     = $this->normalizeString($item['name'] ?? null);
            $itemSku      = $this->normalizeString($item['ean'] ?? null);
            if ($itemId === '' || isset($syncedItemIds[$itemId])) continue;

            $product = null;
            $matchedByFallback = false;
            if (!$product && $itemSku !== '') {
                $product = $this->entityManager->getRepository(Product::class)->findOneBy([
                    'company' => $provider,
                    'sku' => $itemSku,
                ]);
                $matchedByFallback = $product instanceof Product;
            }

            if (!$product && $itemName !== '') {
                $product = $this->entityManager->getRepository(Product::class)->findOneBy([
                    'company' => $provider,
                    'product' => $itemName,
                ]);
                $matchedByFallback = $product instanceof Product;
            }

            if ($product) {
                $productId = (int) $product->getId();
                if (isset($syncedProductIdSet[$productId])) {
                    continue;
                }
                $this->discoveryFoodCode($product, $itemId);
                $synced++;
                $syncedItemIds[$itemId] = true;
                $syncedProductIdSet[$productId] = true;
                $syncedProductIds[] = $productId;

                if ($matchedByFallback) {
                    $productRows = $this->fetchCatalogProducts($provider, [$productId]);
                    $productRow = is_array($productRows[0] ?? null) ? $productRows[0] : null;
                    if (is_array($productRow)) {
                        $existingItemFlat = $this->fetchIfoodCatalogItemFlatV2($merchantId, $itemId);
                        $repairResult = $this->upsertIfoodCatalogItemV2(
                            $merchantId,
                            $productRow,
                            [
                                'item_id' => $itemId,
                                'product_id' => $this->normalizeString($item['productId'] ?? null),
                                'category_id' => $this->normalizeString($item['_categoryId'] ?? null),
                            ],
                            '',
                            $existingItemFlat
                        );

                        if (empty($repairResult['ok'])) {
                            self::$logger->warning('iFood catalog v2 sync republish failed while correcting externalCode', [
                                'merchant_id' => $merchantId,
                                'product_id' => $productId,
                                'item_id' => $itemId,
                                'http_status' => $repairResult['http_status'] ?? null,
                                'error' => $repairResult['error'] ?? null,
                            ]);
                        }
                    }
                }
            }
        }

        if ($synced > 0) {
            $this->entityManager->flush();
            $this->markProductsCatalogSynced($provider, $syncedProductIds);
        }

        return [
            'errno'  => 0,
            'errmsg' => 'ok',
            'data'   => ['total' => count($items), 'synced' => $synced],
        ];
    }

    private function findIfoodCatalogRemoteItemByProductFallback(array $remoteItems, array $product): ?array
    {
        $localSku = $this->normalizeString($product['sku'] ?? null);
        $localName = $this->normalizeText($this->normalizeString($product['name'] ?? null));
        $nameMatch = null;

        foreach ($remoteItems as $remoteItem) {
            if (!is_array($remoteItem)) {
                continue;
            }

            $remoteItemId = $this->normalizeString($remoteItem['id'] ?? null);
            if ($remoteItemId === '') {
                continue;
            }

            if ($localSku !== '' && $this->normalizeString($remoteItem['ean'] ?? null) === $localSku) {
                return $remoteItem;
            }

            if ($nameMatch === null && $localName !== '') {
                $remoteName = $this->normalizeText($this->normalizeString($remoteItem['name'] ?? null));
                if ($remoteName !== '' && $remoteName === $localName) {
                    $nameMatch = $remoteItem;
                }
            }
        }

        return $nameMatch;
    }

    private function fetchCatalogProducts(People $provider, array $productIds = []): array
    {
        $connection = $this->entityManager->getConnection();
        $params     = ['providerId' => (int) $provider->getId()];
        $sql = <<<SQL
            SELECT
                p.id,
                p.product AS name,
                p.sku,
                p.description,
                p.price,
                p.type,
                p.active AS product_active,
                pf.file_id AS cover_file_id,
                c.id   AS category_id,
                c.name AS category_name,
                (
                    SELECT ed.data_value
                    FROM extra_data ed
                    INNER JOIN extra_fields ef ON ef.id = ed.extra_fields_id
                    WHERE ef.context  = 'iFood'
                      AND ef.field_name = 'code'
                      AND LOWER(ed.entity_name) = 'category'
                      AND ed.entity_id = c.id
                    ORDER BY ed.id DESC
                    LIMIT 1
                ) AS ifood_category_code
            FROM product p
            LEFT JOIN product_category pc ON pc.id = (
                SELECT MIN(pc2.id)
                FROM product_category pc2
                INNER JOIN category c2 ON c2.id = pc2.category_id
                WHERE pc2.product_id = p.id
            )
            LEFT JOIN category c ON c.id = pc.category_id
            LEFT JOIN product_file pf ON pf.id = (
                SELECT MIN(pf2.id)
                FROM product_file pf2
                WHERE pf2.product_id = p.id
            )
            WHERE p.company_id = :providerId
              AND (
                  p.active = 1
                  OR EXISTS (
                      SELECT 1
                      FROM extra_data ed_product
                      INNER JOIN extra_fields ef_product ON ef_product.id = ed_product.extra_fields_id
                      WHERE ef_product.context = 'iFood'
                        AND ef_product.field_name = 'code'
                        AND LOWER(ed_product.entity_name) = 'product'
                        AND ed_product.entity_id = p.id
                  )
              )
              AND p.type IN ('manufactured', 'custom', 'product')
        SQL;

        if (!empty($productIds)) {
            $placeholders = [];
            foreach ($productIds as $i => $pid) {
                $key = 'pid' . $i;
                $placeholders[]  = ':' . $key;
                $params[$key]    = (int) $pid;
            }
            $sql .= ' AND p.id IN (' . implode(', ', $placeholders) . ')';
        }

        $sql .= ' ORDER BY p.product ASC';

        $rows = $connection->fetchAllAssociative($sql, $params);

        return $this->attachCatalogModifierGroups($provider, $rows);
    }

    private function normalizeProductIds(array $productIds): array
    {
        $normalized = [];

        foreach ($productIds as $productId) {
            if ($productId === null || $productId === '') {
                continue;
            }

            $digits = preg_replace('/\D+/', '', (string) $productId);
            if ($digits === '') {
                continue;
            }

            $normalized[] = (int) $digits;
        }

        return array_values(array_unique(array_filter($normalized)));
    }

    private function fetchCatalogModifierRows(People $provider, array $productIds = []): array
    {
        $parentIds = $this->normalizeProductIds($productIds);
        if (empty($parentIds)) {
            return [];
        }

        $connection = $this->entityManager->getConnection();
        $params = [
            'providerId' => $provider->getId(),
        ];

        $placeholders = [];
        foreach ($parentIds as $index => $productId) {
            $key = 'parentProductId' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $productId;
        }

        $sql = <<<SQL
            SELECT
                group_parent.parent_product_id AS parent_product_id,
                pg.id AS product_group_id,
                pg.product_group AS product_group_name,
                pg.required AS group_required,
                pg.minimum AS group_minimum,
                pg.maximum AS group_maximum,
                pg.group_order AS group_order,
                pg.active AS group_active,
                pgp.id AS product_group_product_id,
                pgp.quantity AS child_quantity,
                pgp.price AS child_relation_price,
                pgp.active AS relation_active,
                child.id AS child_product_id,
                child.product AS child_product_name,
                child.description AS child_description,
                child.price AS child_base_price,
                child.sku AS child_sku,
                child.active AS child_active,
                child_pf.file_id AS child_cover_file_id
            FROM product_group pg
            INNER JOIN product_group_parent group_parent
                ON group_parent.product_group_id = pg.id
               AND group_parent.active = 1
            INNER JOIN product_group_product pgp
                ON %s
            INNER JOIN product parent
                ON parent.id = group_parent.parent_product_id
            INNER JOIN product child
                ON child.id = pgp.product_child_id
            LEFT JOIN product_file child_pf ON child_pf.id = (
                SELECT MIN(pf2.id)
                FROM product_file pf2
                WHERE pf2.product_id = child.id
            )
            WHERE parent.company_id = :providerId
              AND parent.active = 1
              AND pgp.product_type IN ('component', 'package')
              AND group_parent.parent_product_id IN (%s)
            ORDER BY
                group_parent.parent_product_id ASC,
                COALESCE(pg.group_order, 0) ASC,
                pg.id ASC,
                pgp.id ASC,
                child.product ASC,
                child.id ASC
        SQL;

        $sql = sprintf(
            $sql,
            'pgp.product_group_id = pg.id',
            implode(', ', $placeholders)
        );

        return $connection->fetchAllAssociative($sql, $params);
    }

    private function attachCatalogModifierGroups(People $provider, array $products): array
    {
        if (empty($products)) {
            return [];
        }

        $productIds = array_map(
            static fn(array $product) => (int) ($product['id'] ?? 0),
            $products
        );
        $modifierRows = $this->fetchCatalogModifierRows($provider, $productIds);
        if (empty($modifierRows)) {
            return array_map(static function (array $product): array {
                $product['modifier_groups'] = [];
                return $product;
            }, $products);
        }

        $groupsByParentProduct = [];
        foreach ($modifierRows as $modifierRow) {
            $parentProductId = (int) ($modifierRow['parent_product_id'] ?? 0);
            $groupId = (int) ($modifierRow['product_group_id'] ?? 0);
            if ($parentProductId <= 0 || $groupId <= 0) {
                continue;
            }

            if (!isset($groupsByParentProduct[$parentProductId][$groupId])) {
                $isRequired = (int) ($modifierRow['group_required'] ?? 0) === 1;
                $minimum = max(0, (int) round((float) ($modifierRow['group_minimum'] ?? 0)));
                $maximum = max(0, (int) round((float) ($modifierRow['group_maximum'] ?? 0)));

                if ($isRequired && $minimum === 0) {
                    $minimum = 1;
                }

                $groupsByParentProduct[$parentProductId][$groupId] = [
                    'id' => $groupId,
                    'name' => trim((string) ($modifierRow['product_group_name'] ?? 'Grupo')),
                    'required' => $isRequired,
                    'minimum' => $minimum,
                    'maximum' => $maximum,
                    'group_order' => max(0, (int) ($modifierRow['group_order'] ?? 0)),
                    'active' => (int) ($modifierRow['group_active'] ?? 0) === 1,
                    'options' => [],
                ];
            }

            $relationId = (int) ($modifierRow['product_group_product_id'] ?? 0);
            $childProductId = (int) ($modifierRow['child_product_id'] ?? 0);
            $childName = trim((string) ($modifierRow['child_product_name'] ?? ''));
            if ($relationId <= 0 || $childProductId <= 0 || $childName === '') {
                continue;
            }

            $rawChildPrice = $modifierRow['child_relation_price'] ?? null;
            $childPrice = ($rawChildPrice === null || $rawChildPrice === '')
                ? (float) ($modifierRow['child_base_price'] ?? 0)
                : (float) $rawChildPrice;

            $groupsByParentProduct[$parentProductId][$groupId]['options'][] = [
                'id' => $relationId,
                'child_product_id' => $childProductId,
                'name' => $childName,
                'description' => trim((string) ($modifierRow['child_description'] ?? '')),
                'sku' => trim((string) ($modifierRow['child_sku'] ?? '')),
                'cover_file_id' => $modifierRow['child_cover_file_id'] ?? null,
                'quantity' => (float) ($modifierRow['child_quantity'] ?? 0),
                'price' => round($childPrice, 2),
                'active' => (int) ($modifierRow['relation_active'] ?? 0) === 1
                    && (int) ($modifierRow['child_active'] ?? 0) === 1,
            ];
        }

        return array_map(static function (array $product) use ($groupsByParentProduct): array {
            $parentProductId = (int) ($product['id'] ?? 0);
            $modifierGroups = array_values($groupsByParentProduct[$parentProductId] ?? []);

            $modifierGroups = array_values(array_filter(array_map(static function (array $group): ?array {
                $optionCount = count($group['options'] ?? []);
                if ($optionCount === 0) {
                    return null;
                }

                $minimum = max(0, (int) ($group['minimum'] ?? 0));
                $maximum = max(0, (int) ($group['maximum'] ?? 0));

                if ($maximum <= 0) {
                    $maximum = $optionCount;
                }

                if ($minimum > $optionCount) {
                    $minimum = $optionCount;
                }

                if ($maximum > $optionCount) {
                    $maximum = $optionCount;
                }

                if ($maximum < $minimum) {
                    $maximum = $minimum;
                }

                $group['minimum'] = $minimum;
                $group['maximum'] = $maximum;
                return $group;
            }, $modifierGroups)));

            $product['modifier_groups'] = $modifierGroups;
            return $product;
        }, $products);
    }

    /* --- catálogo v2 helpers ------------------------------------------ */

    private function fetchIfoodDefaultCatalogId(string $merchantId): ?string
    {
        $token = $this->getAccessToken();
        if (!$token) return null;
        try {
            $response = $this->ifoodClient->request('GET',
                self::CATALOG_V2_BASE . rawurlencode($merchantId) . '/catalogs',
                ['headers' => ['Authorization' => 'Bearer ' . $token]]
            );
            if ($response->getStatusCode() !== 200) return null;
            $catalogs = $response->toArray(false);
            if (!is_array($catalogs) || empty($catalogs)) return null;
            $id = $this->normalizeString($catalogs[0]['catalogId'] ?? $catalogs[0]['id'] ?? null);
            return $id !== '' ? $id : null;
        } catch (\Throwable $e) {
            self::$logger->error('iFood catalog v2 list failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function fetchIfoodCatalogItemsV2(string $merchantId): array
    {
        $token = $this->getAccessToken();
        if (!$token) return [];
        try {
            $catalogId = $this->fetchIfoodDefaultCatalogId($merchantId);
            if ($catalogId === null) return [];

            $response = $this->ifoodClient->request('GET',
                self::CATALOG_V2_BASE . rawurlencode($merchantId) . '/catalogs/' . rawurlencode($catalogId) . '/categories',
                [
                    'headers' => ['Authorization' => 'Bearer ' . $token],
                    'query'   => ['includeItems' => 'true'],
                ]
            );
            if ($response->getStatusCode() !== 200) return [];
            $categories = $response->toArray(false);
            if (!is_array($categories)) return [];

            $allItems = [];
            foreach ($categories as $cat) {
                if (!is_array($cat['items'] ?? null)) continue;
                foreach ($cat['items'] as $item) {
                    $item['_categoryId']   = $cat['id']   ?? null;
                    $item['_categoryName'] = $cat['name'] ?? null;
                    $allItems[]            = $item;
                }
            }
            return $allItems;
        } catch (\Throwable $e) {
            self::$logger->error('iFood catalog v2 items fetch failed', ['merchant_id' => $merchantId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    private function fetchIfoodCatalogItemFlatV2(string $merchantId, string $itemId): ?array
    {
        $token = $this->getAccessToken();
        $normalizedItemId = $this->normalizeString($itemId);
        if (!$token || $normalizedItemId === '') return null;

        try {
            $response = $this->ifoodClient->request('GET',
                self::CATALOG_V2_BASE . rawurlencode($merchantId) . '/items/' . rawurlencode($normalizedItemId) . '/flat',
                ['headers' => ['Authorization' => 'Bearer ' . $token]]
            );

            if ($response->getStatusCode() !== 200) return null;
            $item = $response->toArray(false);

            return is_array($item) ? $item : null;
        } catch (\Throwable $e) {
            self::$logger->warning('iFood catalog v2 flat item fetch failed', [
                'merchant_id' => $merchantId,
                'item_id' => $normalizedItemId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function buildIfoodCatalogItemExternalCodeIndex(string $merchantId, array $items, bool $includeFlatRootProduct = false): array
    {
        $remoteByEc = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemId = $this->normalizeString($item['id'] ?? null);
            if ($itemId === '') {
                continue;
            }

            $status = $this->normalizeString($item['status'] ?? 'AVAILABLE');
            $itemProductId = $this->normalizeString($item['productId'] ?? null);
            $categoryId = $this->normalizeString($item['_categoryId'] ?? null);
            $this->registerIfoodCatalogExternalCode(
                $remoteByEc,
                $item['externalCode'] ?? null,
                $itemId,
                $status,
                'item',
                $itemProductId,
                $categoryId
            );

            if (!$includeFlatRootProduct) {
                continue;
            }

            $itemExternalCode = $this->normalizeString($item['externalCode'] ?? null);
            if ($itemExternalCode !== '' && ctype_digit($itemExternalCode)) {
                continue;
            }

            $flat = $this->fetchIfoodCatalogItemFlatV2($merchantId, $itemId);
            if (!is_array($flat)) {
                continue;
            }

            $flatItem = is_array($flat['item'] ?? null) ? $flat['item'] : $flat;
            $rootProductId = $itemProductId !== '' ? $itemProductId : $this->normalizeString($flatItem['productId'] ?? null);
            $this->registerIfoodCatalogExternalCode(
                $remoteByEc,
                $flatItem['externalCode'] ?? null,
                $itemId,
                $status,
                'item',
                $rootProductId,
                $categoryId
            );

            $products = is_array($flat['products'] ?? null) ? $flat['products'] : [];
            $optionsByProductId = $this->mapIfoodFlatOptionsByProductId($flat);

            foreach ($products as $product) {
                if (!is_array($product)) {
                    continue;
                }

                $productId = $this->normalizeString($product['id'] ?? null);
                if ($rootProductId !== '' && $productId !== $rootProductId) {
                    continue;
                }
                if ($rootProductId === '' && count($products) !== 1) {
                    continue;
                }

                $this->registerIfoodCatalogExternalCode(
                    $remoteByEc,
                    $product['externalCode'] ?? null,
                    $itemId,
                    $status,
                    'root_product',
                    $rootProductId !== '' ? $rootProductId : $productId,
                    $categoryId
                );
            }

            foreach ($products as $product) {
                if (!is_array($product)) {
                    continue;
                }

                $productId = $this->normalizeString($product['id'] ?? null);
                if ($productId === '' || $productId === $rootProductId || empty($optionsByProductId[$productId])) {
                    continue;
                }

                foreach ($optionsByProductId[$productId] as $option) {
                    $optionId = $this->normalizeString($option['id'] ?? null);
                    if ($optionId === '') {
                        continue;
                    }

                    $optionStatus = $this->normalizeString($option['status'] ?? $status);
                    $this->registerIfoodCatalogExternalCode(
                        $remoteByEc,
                        $product['externalCode'] ?? null,
                        $itemId,
                        $optionStatus !== '' ? $optionStatus : $status,
                        'option_product',
                        $productId,
                        $categoryId,
                        $optionId
                    );
                    $this->registerIfoodCatalogExternalCode(
                        $remoteByEc,
                        $option['externalCode'] ?? null,
                        $itemId,
                        $optionStatus !== '' ? $optionStatus : $status,
                        'option',
                        $productId,
                        $categoryId,
                        $optionId
                    );
                }
            }
        }

        return $remoteByEc;
    }

    private function mapIfoodFlatOptionsByProductId(array $flat): array
    {
        $mapped = [];
        $options = is_array($flat['options'] ?? null) ? $flat['options'] : [];

        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }

            $productId = $this->normalizeString($option['productId'] ?? null);
            if ($productId === '') {
                continue;
            }

            $mapped[$productId][] = $option;
        }

        return $mapped;
    }

    private function registerIfoodCatalogExternalCode(
        array &$remoteByEc,
        mixed $externalCode,
        string $itemId,
        string $status,
        string $source,
        string $productId = '',
        string $categoryId = '',
        string $optionId = ''
    ): void
    {
        $candidates = $this->normalizeIfoodCatalogExternalCodeCandidates($externalCode);
        if (empty($candidates) || $itemId === '') {
            return;
        }

        $priority = 0;
        if ($source === 'item') {
            $priority = 3;
        } elseif ($source === 'root_product') {
            $priority = 2;
        } elseif ($source === 'option') {
            $priority = 1;
        }

        foreach ($candidates as $ec) {
            $currentPriority = (int) ($remoteByEc[$ec]['match_priority'] ?? -1);
            if ($currentPriority > $priority) {
                continue;
            }

            $remoteByEc[$ec] = [
                'item_id' => $itemId,
                'product_id' => $productId,
                'category_id' => $categoryId,
                'option_id' => $optionId !== '' ? $optionId : null,
                'status'  => $status !== '' ? $status : 'AVAILABLE',
                'match_source' => $source,
                'match_priority' => $priority,
            ];
        }
    }

    private function normalizeIfoodCatalogExternalCodeCandidates(mixed $externalCode): array
    {
        $ec = $this->normalizeString($externalCode);
        if ($ec === '') {
            return [];
        }

        return [$ec];
    }

    private function getOrCreateDefaultCatalogCategory(string $merchantId, string $catalogId): ?string
    {
        return $this->resolveIfoodCatalogCategoryId($merchantId, $catalogId, 'Produtos', [], 0);
    }

    private function fetchIfoodCatalogCategoriesV2(string $merchantId, string $catalogId): array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return [];
        }

        try {
            $response = $this->ifoodClient->request('GET',
                self::CATALOG_V2_BASE . rawurlencode($merchantId) . '/catalogs/' . rawurlencode($catalogId) . '/categories',
                ['headers' => ['Authorization' => 'Bearer ' . $token]]
            );
            if ($response->getStatusCode() !== 200) {
                return [];
            }

            $cats = $response->toArray(false);
            return is_array($cats) ? $cats : [];
        } catch (\Throwable $e) {
            self::$logger->error('iFood catalog v2 categories fetch failed', [
                'merchant_id' => $merchantId,
                'catalog_id' => $catalogId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function normalizeText(string $text): string
    {
        $lower = mb_strtolower(trim($text));
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);
        return is_string($ascii) ? trim($ascii) : $lower;
    }

    private function patchIfoodCatalogCategoryV2(
        string $merchantId,
        string $catalogId,
        string $categoryId,
        array $categoryBody,
        string $token
    ): array {
        try {
            $response = $this->ifoodClient->request('PATCH',
                self::CATALOG_V2_BASE . rawurlencode($merchantId) . '/catalogs/' . rawurlencode($catalogId) . '/categories/' . rawurlencode($categoryId),
                [
                    'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
                    'json'    => $categoryBody,
                ]
            );

            $statusCode = $response->getStatusCode();
            $body = $statusCode >= 200 && $statusCode < 300 ? '' : substr($response->getContent(false), 0, 2000);

            return [
                'ok' => $statusCode >= 200 && $statusCode < 300,
                'status' => $statusCode,
                'body' => $body,
                'error' => null,
                'not_found' => $this->isIfoodCatalogCategoryNotFound($statusCode, $body),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'status' => null,
                'body' => null,
                'error' => $e->getMessage(),
                'not_found' => $this->isIfoodCatalogCategoryNotFound(null, null, $e->getMessage()),
            ];
        }
    }

    private function isIfoodCatalogCategoryNotFound(?int $status, ?string $body = null, ?string $error = null): bool
    {
        if ($status === 404) {
            return true;
        }

        $message = strtolower((string) ($body ?: $error));
        return str_contains($message, 'notfound') || str_contains($message, 'not found');
    }

    private function resolveIfoodCatalogCategoryId(
        string $merchantId,
        string $catalogId,
        string $categoryName,
        array &$remoteCategoriesByName,
        int $sequence = 0,
        int $localCategoryId = 0,
        string $storedIfoodId = ''
    ): ?string {
        $token = $this->getAccessToken();
        if (!$token) {
            return null;
        }

        $effectiveName  = trim($categoryName) !== '' ? trim($categoryName) : 'Produtos';
        $normalizedName = $this->normalizeText($effectiveName);
        $categoryBody   = [
            'name'     => $effectiveName,
            'status'   => 'AVAILABLE',
            'template' => 'DEFAULT',
            'sequence' => max(0, $sequence),
        ];
        $knownRemoteCategoryIds = array_values(array_unique(array_filter(array_map(
            fn($id) => $this->normalizeString($id),
            array_values($remoteCategoriesByName)
        ))));

        // Prioridade 1: ID remoto ja armazenado localmente — atualiza via PATCH
        if ($storedIfoodId !== '') {
            $storedIdKnownRemotely = in_array($storedIfoodId, $knownRemoteCategoryIds, true);
            if ($storedIdKnownRemotely || empty($knownRemoteCategoryIds)) {
                $patchResult = $this->patchIfoodCatalogCategoryV2($merchantId, $catalogId, $storedIfoodId, $categoryBody, $token);
                if ($patchResult['ok']) {
                    $remoteCategoriesByName[$normalizedName] = $storedIfoodId;
                    return $storedIfoodId;
                }

                self::$logger->warning('iFood PATCH category failed', [
                    'merchant_id'       => $merchantId,
                    'catalog_id'        => $catalogId,
                    'ifood_category_id' => $storedIfoodId,
                    'local_category_id' => $localCategoryId,
                    'category_name'     => $effectiveName,
                    'status_code'       => $patchResult['status'],
                    'body'              => $patchResult['body'],
                    'error'             => $patchResult['error'],
                ]);

                if (!$patchResult['not_found']) {
                    return $storedIfoodId;
                }
            } else {
                self::$logger->warning('iFood stored category id not found in remote category list', [
                    'merchant_id'       => $merchantId,
                    'catalog_id'        => $catalogId,
                    'ifood_category_id' => $storedIfoodId,
                    'local_category_id' => $localCategoryId,
                    'category_name'     => $effectiveName,
                ]);
            }
        }

        // Prioridade 2: Sem ID armazenado — busca por nome para evitar duplicatas
        if ($normalizedName !== '' && isset($remoteCategoriesByName[$normalizedName])) {
            $existingId = $this->normalizeString($remoteCategoriesByName[$normalizedName]);
            // Persiste vinculo local → remoto para proximas publicacoes
            if ($existingId !== '') {
                $patchResult = $this->patchIfoodCatalogCategoryV2($merchantId, $catalogId, $existingId, $categoryBody, $token);
                if ($patchResult['ok'] || !$patchResult['not_found']) {
                    $this->persistIfoodCategoryCode($localCategoryId, $existingId);
                    return $existingId;
                }

                self::$logger->warning('iFood PATCH category (por nome) failed', [
                    'merchant_id'        => $merchantId,
                    'catalog_id'         => $catalogId,
                    'ifood_category_id'  => $existingId,
                    'local_category_id'  => $localCategoryId,
                    'category_name'      => $effectiveName,
                    'status_code'        => $patchResult['status'],
                    'body'               => $patchResult['body'],
                    'error'              => $patchResult['error'],
                ]);
                unset($remoteCategoriesByName[$normalizedName]);
            }
        }

        // Prioridade 3: Categoria nova — cria via POST e armazena vinculo
        try {
            $response = $this->ifoodClient->request('POST',
                self::CATALOG_V2_BASE . rawurlencode($merchantId) . '/catalogs/' . rawurlencode($catalogId) . '/categories',
                [
                    'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
                    'json'    => $categoryBody,
                ]
            );

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                self::$logger->error('iFood create category failed', [
                    'merchant_id'       => $merchantId,
                    'catalog_id'        => $catalogId,
                    'local_category_id' => $localCategoryId,
                    'category_name'     => $effectiveName,
                    'status_code'       => $statusCode,
                    'body'              => substr($response->getContent(false), 0, 2000),
                ]);
                return null;
            }

            $data = $response->toArray(false);
            $id   = $this->normalizeString($data['id'] ?? null);
            if ($id !== '') {
                $remoteCategoriesByName[$normalizedName] = $id;
                $this->persistIfoodCategoryCode($localCategoryId, $id);
                return $id;
            }
        } catch (\Throwable $e) {
            self::$logger->error('iFood create category failed', [
                'merchant_id'       => $merchantId,
                'catalog_id'        => $catalogId,
                'local_category_id' => $localCategoryId,
                'category_name'     => $effectiveName,
                'error'             => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function persistIfoodCategoryCode(int $localCategoryId, string $ifoodCategoryId): void
    {
        if ($localCategoryId <= 0 || $ifoodCategoryId === '') {
            return;
        }
        try {
            $category = $this->entityManager->getRepository(Category::class)->find($localCategoryId);
            if ($category instanceof Category) {
                $this->discoveryFoodCode($category, $ifoodCategoryId);
                $this->entityManager->flush();
            }
        } catch (\Throwable $e) {
            self::$logger->warning('iFood persistIfoodCategoryCode failed', [
                'local_category_id'  => $localCategoryId,
                'ifood_category_id'  => $ifoodCategoryId,
                'error'              => $e->getMessage(),
            ]);
        }
    }

    private function generateUuidV4(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    private function generateStableUuidFromSeed(string $seed): string
    {
        $hash = md5(self::APP_CONTEXT . '|' . $seed);
        $timeHiAndVersion = (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x4000;
        $clockSeq = (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000;

        return sprintf(
            '%s-%s-%04x-%04x-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            $timeHiAndVersion,
            $clockSeq,
            substr($hash, 20, 12)
        );
    }

    private function mapIfoodOptionIdsByExternalCode(?array $existingItemFlat): array
    {
        if (!is_array($existingItemFlat)) {
            return [];
        }

        $optionsByExternalCode = [];
        $existingOptions = is_array($existingItemFlat['options'] ?? null) ? $existingItemFlat['options'] : [];
        foreach ($existingOptions as $option) {
            if (!is_array($option)) {
                continue;
            }

            $optionId = $this->normalizeString($option['id'] ?? null);
            if ($optionId !== '') {
                $externalCode = $this->normalizeString($option['externalCode'] ?? null);
                if ($externalCode !== '') {
                    $optionsByExternalCode[$externalCode] = $optionId;
                }
            }
        }

        return $optionsByExternalCode;
    }

    private function buildIfoodCatalogModifierPayload(string $merchantId, array $product, ?array $existingItemFlat = null, ?array $catalogProductsById = null): array
    {
        $modifierGroups = is_array($product['modifier_groups'] ?? null) ? $product['modifier_groups'] : [];
        if (empty($modifierGroups)) {
            return [
                'product_option_groups' => [],
                'products' => [],
                'option_groups' => [],
                'options' => [],
            ];
        }

        $productOptionGroups = [];
        $productsById = [];
        $optionGroups = [];
        $options = [];
        $existingOptionIds = $this->mapIfoodOptionIdsByExternalCode($existingItemFlat);
        $buildingProductIds = [];
        $processedProductIds = [];

        foreach ($modifierGroups as $groupIndex => $group) {
            $groupId = (int) ($group['id'] ?? 0);
            $groupName = trim((string) ($group['name'] ?? ''));
            $groupOptions = is_array($group['options'] ?? null) ? $group['options'] : [];
            if ($groupId <= 0 || $groupName === '' || empty($groupOptions)) {
                continue;
            }

            $groupUuid = $this->generateStableUuidFromSeed('catalog:group:' . $groupId);
            $optionIds = [];

            foreach ($groupOptions as $optionIndex => $option) {
                $relationId = (int) ($option['id'] ?? 0);
                $childProductId = (int) ($option['child_product_id'] ?? 0);
                $childName = trim((string) ($option['name'] ?? ''));
                if ($relationId <= 0 || $childProductId <= 0 || $childName === '') {
                    continue;
                }

                $childProductRow = is_array($catalogProductsById[$childProductId] ?? null)
                    ? $catalogProductsById[$childProductId]
                    : [];
                $childProductUuid = $this->appendIfoodCatalogModifierProductBranch(
                    $merchantId,
                    $childProductRow,
                    $option,
                    $catalogProductsById,
                    $productsById,
                    $optionGroups,
                    $options,
                    $existingOptionIds,
                    $buildingProductIds,
                    $processedProductIds
                );
                if ($childProductUuid === '') {
                    continue;
                }

                $optionExternalCode = (string) $relationId;
                $optionUuid = $existingOptionIds[$optionExternalCode]
                    ?? $this->generateStableUuidFromSeed('catalog:option:' . $relationId);
                $optionIds[] = $optionUuid;
                $optionPrice = round((float) ($option['price'] ?? 0), 2);

                $options[] = [
                    'id' => $optionUuid,
                    'status' => !empty($option['active']) ? 'AVAILABLE' : 'UNAVAILABLE',
                    'index' => $optionIndex,
                    'productId' => $childProductUuid,
                    'price' => [
                        'value' => $optionPrice,
                        'originalValue' => $optionPrice,
                    ],
                    'externalCode' => $optionExternalCode,
                ];
            }

            if (empty($optionIds)) {
                continue;
            }

            $productOptionGroups[] = [
                'id' => $groupUuid,
                'min' => max(0, (int) ($group['minimum'] ?? 0)),
                'max' => max(0, (int) ($group['maximum'] ?? 0)),
            ];

            $optionGroups[] = [
                'id' => $groupUuid,
                'name' => $groupName,
                'externalCode' => (string) $groupId,
                'status' => !empty($group['active']) ? 'AVAILABLE' : 'UNAVAILABLE',
                'index' => max(0, (int) ($group['group_order'] ?? $groupIndex)),
                'optionGroupType' => 'DEFAULT',
                'optionIds' => $optionIds,
            ];
        }

        return [
            'product_option_groups' => array_values($productOptionGroups),
            'products' => array_values($productsById),
            'option_groups' => $optionGroups,
            'options' => $options,
        ];
    }

    private function appendIfoodCatalogModifierProductBranch(
        string $merchantId,
        array $productRow,
        array $fallbackOption,
        ?array $catalogProductsById,
        array &$productsById,
        array &$optionGroups,
        array &$options,
        array &$existingOptionIds,
        array &$buildingProductIds,
        array &$processedProductIds
    ): string {
        $childProductId = (int) ($productRow['id'] ?? ($fallbackOption['child_product_id'] ?? 0));
        if ($childProductId <= 0) {
            return '';
        }

        $childProductUuid = $this->generateStableUuidFromSeed('catalog:option-product:' . $childProductId);
        if (isset($processedProductIds[$childProductId])) {
            return $childProductUuid;
        }

        if (isset($buildingProductIds[$childProductId])) {
            return $childProductUuid;
        }

        $buildingProductIds[$childProductId] = true;

        $childName = trim((string) ($productRow['name'] ?? $fallbackOption['name'] ?? ''));
        if ($childName === '') {
            $childName = 'Produto';
        }

        $childDescription = trim((string) ($productRow['description'] ?? $fallbackOption['description'] ?? ''));
        $childSku = trim((string) ($productRow['sku'] ?? $fallbackOption['sku'] ?? ''));
        $childQuantity = (float) ($fallbackOption['quantity'] ?? $productRow['quantity'] ?? 0);
        $childCoverFileId = $productRow['cover_file_id'] ?? $fallbackOption['cover_file_id'] ?? null;

        if (!isset($productsById[$childProductUuid])) {
            $childProductBody = [
                'id' => $childProductUuid,
                'externalCode' => (string) $childProductId,
                'name' => $childName,
                'description' => $childDescription,
                'serving' => 'SERVES_1',
                'optionGroups' => [],
            ];

            if ($childSku !== '') {
                $childProductBody['ean'] = $childSku;
            }

            if ($childQuantity > 0) {
                $childProductBody['quantity'] = $childQuantity;
            }

            $childSourceImageUrl = $this->buildPublicFileDownloadUrl($childCoverFileId);
            if ($childSourceImageUrl) {
                $uploadedChildImagePath = $this->uploadIfoodCatalogImageAndResolvePath($merchantId, $childCoverFileId, $childSourceImageUrl);
                if ($uploadedChildImagePath) {
                    $childProductBody['imagePath'] = $uploadedChildImagePath;
                } else {
                    self::$logger->warning('iFood catalog child image upload skipped, proceeding without imagePath', [
                        'merchant_id' => $merchantId,
                        'product_id' => $fallbackOption['product_id'] ?? null,
                        'child_product_id' => $childProductId,
                        'image_url' => $childSourceImageUrl,
                    ]);
                }
            }

            $productsById[$childProductUuid] = $childProductBody;
        }

        $childModifierGroups = is_array($productRow['modifier_groups'] ?? null) ? $productRow['modifier_groups'] : [];
        foreach ($childModifierGroups as $groupIndex => $group) {
            $groupId = (int) ($group['id'] ?? 0);
            $groupName = trim((string) ($group['name'] ?? ''));
            $groupOptions = is_array($group['options'] ?? null) ? $group['options'] : [];
            if ($groupId <= 0 || $groupName === '' || empty($groupOptions)) {
                continue;
            }

            $groupUuid = $this->generateStableUuidFromSeed('catalog:group:' . $groupId);
            $optionIds = [];

            foreach ($groupOptions as $optionIndex => $option) {
                $relationId = (int) ($option['id'] ?? 0);
                $grandChildProductId = (int) ($option['child_product_id'] ?? 0);
                $grandChildName = trim((string) ($option['name'] ?? ''));
                if ($relationId <= 0 || $grandChildProductId <= 0 || $grandChildName === '') {
                    continue;
                }

                $grandChildRow = is_array($catalogProductsById[$grandChildProductId] ?? null)
                    ? $catalogProductsById[$grandChildProductId]
                    : [];
                $grandChildUuid = $this->appendIfoodCatalogModifierProductBranch(
                    $merchantId,
                    $grandChildRow,
                    $option,
                    $catalogProductsById,
                    $productsById,
                    $optionGroups,
                    $options,
                    $existingOptionIds,
                    $buildingProductIds,
                    $processedProductIds
                );

                if ($grandChildUuid === '') {
                    continue;
                }

                $optionExternalCode = (string) $relationId;
                $optionUuid = $existingOptionIds[$optionExternalCode]
                    ?? $this->generateStableUuidFromSeed('catalog:option:' . $relationId);
                $optionIds[] = $optionUuid;
                $optionPrice = round((float) ($option['price'] ?? 0), 2);

                $options[] = [
                    'id' => $optionUuid,
                    'status' => !empty($option['active']) ? 'AVAILABLE' : 'UNAVAILABLE',
                    'index' => $optionIndex,
                    'productId' => $grandChildUuid,
                    'price' => [
                        'value' => $optionPrice,
                        'originalValue' => $optionPrice,
                    ],
                    'externalCode' => $optionExternalCode,
                ];
            }

            if (empty($optionIds)) {
                continue;
            }

            $childProductOptionGroups = is_array($productsById[$childProductUuid]['optionGroups'] ?? null)
                ? $productsById[$childProductUuid]['optionGroups']
                : [];
            $childOptionGroupIds = [];
            foreach ($childProductOptionGroups as $childOptionGroup) {
                if (!is_array($childOptionGroup)) {
                    continue;
                }

                $existingGroupId = $this->normalizeString($childOptionGroup['id'] ?? null);
                if ($existingGroupId !== '') {
                    $childOptionGroupIds[$existingGroupId] = true;
                }
            }

            if (!isset($childOptionGroupIds[$groupUuid])) {
                $childProductOptionGroups[] = [
                    'id' => $groupUuid,
                    'min' => max(0, (int) ($group['minimum'] ?? 0)),
                    'max' => max(0, (int) ($group['maximum'] ?? 0)),
                ];
                $productsById[$childProductUuid]['optionGroups'] = array_values($childProductOptionGroups);
            }

            $optionGroups[] = [
                'id' => $groupUuid,
                'name' => $groupName,
                'externalCode' => (string) $groupId,
                'status' => !empty($group['active']) ? 'AVAILABLE' : 'UNAVAILABLE',
                'index' => max(0, (int) ($group['group_order'] ?? $groupIndex)),
                'optionGroupType' => 'DEFAULT',
                'optionIds' => $optionIds,
            ];
        }

        $processedProductIds[$childProductId] = true;
        unset($buildingProductIds[$childProductId]);

        return $childProductUuid;
    }

    private function indexCatalogProductsById(array $products): array
    {
        $indexed = [];
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }

            $productId = (int) ($product['id'] ?? 0);
            if ($productId > 0) {
                $indexed[$productId] = $product;
            }
        }

        return $indexed;
    }

    private function normalizeImageMimeType(?string $contentType): ?string
    {
        $normalized = strtolower(trim((string) $contentType));
        if ($normalized === '') {
            return null;
        }

        $normalized = explode(';', $normalized)[0] ?? $normalized;
        $normalized = trim($normalized);

        if ($normalized === 'image/jpg' || $normalized === 'image/pjpeg') {
            return 'image/jpeg';
        }

        if ($normalized === 'image/x-png') {
            return 'image/png';
        }

        return in_array($normalized, ['image/jpeg', 'image/png'], true) ? $normalized : null;
    }

    private function detectIfoodImageMimeType(string $binary, ?string $declaredMimeType): ?string
    {
        $detectedMimeType = null;

        if ($binary !== '' && class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detected = $finfo->buffer($binary);
            $detectedMimeType = is_string($detected) ? $detected : null;
        }

        if (!$detectedMimeType && function_exists('getimagesizefromstring')) {
            $info = @getimagesizefromstring($binary);
            $detectedMimeType = is_array($info) ? ($info['mime'] ?? null) : null;
        }

        $normalizedDetectedMimeType = $this->normalizeImageMimeType($detectedMimeType);
        if ($normalizedDetectedMimeType) {
            return $normalizedDetectedMimeType;
        }

        $detectedMimeType = strtolower(trim((string) $detectedMimeType));
        if ($detectedMimeType !== '' && $detectedMimeType !== 'application/octet-stream') {
            return null;
        }

        return $this->normalizeImageMimeType($declaredMimeType);
    }

    private function buildIfoodImageDataUri(string $binary, string $mimeType): string
    {
        return 'data:' . $mimeType . ';base64,' . base64_encode($binary);
    }

    private function isIfoodUploadImageWithinLimits(string $binary, string $mimeType): bool
    {
        $sizeBytes = strlen($binary);
        if ($sizeBytes <= 0 || $sizeBytes > self::MAX_IMAGE_UPLOAD_BYTES) {
            return false;
        }

        $dataUriBytes = strlen('data:' . $mimeType . ';base64,') + (4 * (int) ceil($sizeBytes / 3));

        return ($dataUriBytes + self::IMAGE_UPLOAD_PAYLOAD_MARGIN_BYTES) <= self::MAX_IMAGE_UPLOAD_BYTES;
    }

    private function resizeIfoodGdImageToUploadCanvas(mixed $image): ?\GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width <= 0 || $height <= 0) {
            return null;
        }

        $scale = min(1, self::IMAGE_UPLOAD_MAX_DIMENSION / $width, self::IMAGE_UPLOAD_MAX_DIMENSION / $height);
        $targetWidth = (int) max(1, floor($width * $scale));
        $targetHeight = (int) max(1, floor($height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if (!$canvas) {
            return null;
        }

        imagealphablending($canvas, true);
        imagesavealpha($canvas, false);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $white);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $canvas;
    }

    private function encodeIfoodGdImageAsJpeg(mixed $image): ?string
    {
        $canvas = $this->resizeIfoodGdImageToUploadCanvas($image);
        if (!$canvas) {
            return null;
        }

        $jpeg = null;
        for ($quality = 90; $quality >= 60; $quality -= 5) {
            ob_start();
            $saved = imagejpeg($canvas, null, $quality);
            $candidate = ob_get_clean();
            if ($saved && is_string($candidate) && $this->isIfoodUploadImageWithinLimits($candidate, 'image/jpeg')) {
                $jpeg = $candidate;
                break;
            }
        }

        imagedestroy($canvas);

        return $jpeg;
    }

    private function convertIfoodImageToJpegWithGd(string $binary): ?string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            return null;
        }

        $image = @imagecreatefromstring($binary);
        if (!$image) {
            return null;
        }

        $jpeg = $this->encodeIfoodGdImageAsJpeg($image);
        imagedestroy($image);

        return $jpeg;
    }

    private function convertIfoodImageToJpegWithImagick(string $binary): ?string
    {
        if (!class_exists('Imagick')) {
            return null;
        }

        try {
            $imagick = new \Imagick();
            $imagick->setResolution(150, 150);
            $imagick->readImageBlob($binary);
            $imagick->setImageBackgroundColor(new \ImagickPixel('white'));

            if (defined('Imagick::LAYERMETHOD_FLATTEN')) {
                $flattened = $imagick->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
                if ($flattened instanceof \Imagick) {
                    $imagick->clear();
                    $imagick = $flattened;
                }
            }

            $width = $imagick->getImageWidth();
            $height = $imagick->getImageHeight();
            if ($width <= 0 || $height <= 0) {
                $imagick->clear();
                return null;
            }

            $scale = min(1, self::IMAGE_UPLOAD_MAX_DIMENSION / $width, self::IMAGE_UPLOAD_MAX_DIMENSION / $height);
            if ($scale < 1) {
                $imagick->resizeImage(
                    (int) max(1, floor($width * $scale)),
                    (int) max(1, floor($height * $scale)),
                    \Imagick::FILTER_LANCZOS,
                    1
                );
            }

            $imagick->setImageFormat('jpeg');
            $jpeg = null;
            for ($quality = 90; $quality >= 60; $quality -= 5) {
                $imagick->setImageCompressionQuality($quality);
                $candidate = $imagick->getImageBlob();
                if (is_string($candidate) && $this->isIfoodUploadImageWithinLimits($candidate, 'image/jpeg')) {
                    $jpeg = $candidate;
                    break;
                }
            }

            $imagick->clear();

            return $jpeg;
        } catch (\Throwable) {
            return null;
        }
    }

    private function convertIfoodImageToJpeg(string $binary): ?string
    {
        return $this->convertIfoodImageToJpegWithGd($binary)
            ?: $this->convertIfoodImageToJpegWithImagick($binary);
    }

    private function buildIfoodUploadImageDataUriFromBinary(string $binary, ?string $contentType, array $logContext): ?string
    {
        $mimeType = $this->detectIfoodImageMimeType($binary, $contentType);
        if ($mimeType && $this->isIfoodUploadImageWithinLimits($binary, $mimeType)) {
            return $this->buildIfoodImageDataUri($binary, $mimeType);
        }

        $jpeg = $this->convertIfoodImageToJpeg($binary);
        if ($jpeg && $this->isIfoodUploadImageWithinLimits($jpeg, 'image/jpeg')) {
            return $this->buildIfoodImageDataUri($jpeg, 'image/jpeg');
        }

        if (self::$logger) {
            self::$logger->warning('iFood image could not be normalized to upload requirements', $logContext + [
                'content_type' => $contentType,
                'detected_mime_type' => $mimeType,
                'size_bytes' => strlen($binary),
                'max_request_bytes' => self::MAX_IMAGE_UPLOAD_BYTES,
            ]);
        }

        return null;
    }

    private function fetchIfoodLocalImageData(mixed $fileId): ?array
    {
        if ($fileId === null || $fileId === '') {
            return null;
        }

        $normalizedFileId = preg_replace('/\D+/', '', (string) $fileId);
        if ($normalizedFileId === '') {
            return null;
        }

        $file = $this->entityManager->getRepository(File::class)->find((int) $normalizedFileId);
        if (!$file instanceof File) {
            return null;
        }

        $fileType = trim((string) $file->getFileType());
        $extension = strtolower(ltrim(trim((string) $file->getExtension()), '.'));
        $contentType = $fileType !== '' && $extension !== '' ? $fileType . '/' . $extension : null;

        return [
            'binary' => $file->getContent(true),
            'content_type' => $contentType,
            'log_context' => [
                'file_id' => (int) $normalizedFileId,
                'source' => 'local_file',
            ],
        ];
    }

    private function fetchIfoodRemoteImageData(string $imageUrl): ?array
    {
        try {
            $response = $this->ifoodClient->request('GET', $imageUrl);
            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                self::$logger->warning('iFood image fetch failed before upload', [
                    'image_url' => $imageUrl,
                    'status_code' => $statusCode,
                ]);
                return null;
            }

            $headers = $response->getHeaders(false);
            $contentType = $headers['content-type'][0] ?? null;
            $binary = $response->getContent(false);
            if ($binary === '') {
                self::$logger->warning('iFood image fetch returned invalid size for upload', [
                    'image_url' => $imageUrl,
                    'size_bytes' => 0,
                    'max_bytes' => self::MAX_IMAGE_UPLOAD_BYTES,
                ]);
                return null;
            }

            return [
                'binary' => $binary,
                'content_type' => $contentType,
                'log_context' => [
                    'image_url' => $imageUrl,
                    'source' => 'remote_url',
                ],
            ];
        } catch (\Throwable $e) {
            self::$logger->warning('iFood image fetch exception before upload', [
                'image_url' => $imageUrl,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function buildIfoodUploadImageDataUri(mixed $fileId, ?string $imageUrl = null): ?string
    {
        $imageData = $this->fetchIfoodLocalImageData($fileId);
        if (!$imageData && $imageUrl) {
            $imageData = $this->fetchIfoodRemoteImageData($imageUrl);
        }

        if (!$imageData) {
            return null;
        }

        return $this->buildIfoodUploadImageDataUriFromBinary(
            (string) ($imageData['binary'] ?? ''),
            $imageData['content_type'] ?? null,
            is_array($imageData['log_context'] ?? null) ? $imageData['log_context'] : []
        );
    }

    private function resolveIfoodUploadedImagePath(mixed $responseBody): ?string
    {
        if (!is_array($responseBody)) {
            return null;
        }

        $candidates = [
            $responseBody['imagePath'] ?? null,
            $responseBody['path'] ?? null,
            $responseBody['url'] ?? null,
            is_array($responseBody['data'] ?? null) ? ($responseBody['data']['imagePath'] ?? null) : null,
            is_array($responseBody['data'] ?? null) ? ($responseBody['data']['path'] ?? null) : null,
            is_array($responseBody['data'] ?? null) ? ($responseBody['data']['url'] ?? null) : null,
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeString($candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function uploadIfoodCatalogImageAndResolvePath(string $merchantId, mixed $fileId, string $sourceImageUrl): ?string
    {
        $cacheKey = $merchantId . '|' . $sourceImageUrl;
        if (array_key_exists($cacheKey, self::$catalogImagePathCache)) {
            return self::$catalogImagePathCache[$cacheKey] ?: null;
        }

        $token = $this->getAccessToken();
        if (!$token) {
            self::$catalogImagePathCache[$cacheKey] = '';
            return null;
        }

        $dataUri = $this->buildIfoodUploadImageDataUri($fileId, $sourceImageUrl);
        if (!$dataUri) {
            self::$catalogImagePathCache[$cacheKey] = '';
            return null;
        }

        try {
            $response = $this->ifoodClient->request('POST',
                self::CATALOG_V2_BASE . rawurlencode($merchantId) . '/image/upload',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'image' => $dataUri,
                    ],
                ]
            );

            $statusCode = $response->getStatusCode();
            $body = $response->toArray(false);
            $imagePath = $this->resolveIfoodUploadedImagePath($body);

            if ($statusCode >= 200 && $statusCode < 300 && $imagePath) {
                self::$catalogImagePathCache[$cacheKey] = $imagePath;
                return $imagePath;
            }

            self::$logger->warning('iFood catalog image upload failed', [
                'merchant_id' => $merchantId,
                'image_url' => $sourceImageUrl,
                'status_code' => $statusCode,
                'response' => is_array($body) ? $body : [],
            ]);
        } catch (\Throwable $e) {
            self::$logger->warning('iFood catalog image upload exception', [
                'merchant_id' => $merchantId,
                'image_url' => $sourceImageUrl,
                'error' => $e->getMessage(),
            ]);
        }

        self::$catalogImagePathCache[$cacheKey] = '';
        return null;
    }

    private function isIfoodCatalogConcurrentModification(?int $status, ?string $body, ?string $error = null): bool
    {
        if ($status !== 400) {
            return false;
        }

        $message = strtolower((string) ($body ?: $error));
        return str_contains($message, 'concurrently modified');
    }

    private function upsertIfoodCatalogItemV2(string $merchantId, array $product, ?array $existing, string $categoryId, ?array $existingItemFlat = null, ?array $catalogProductsById = null): array
    {
        $token = $this->getAccessToken();
        if (!$token) return ['ok' => false, 'http_status' => null, 'ifood_body' => null, 'error' => 'Token indisponivel'];

        $ec                = (string) $product['id'];
        $existingItemId    = $this->normalizeString($existing['item_id']    ?? null);
        $existingProductId = $this->normalizeString($existing['product_id'] ?? null);
        $usedCategoryId    = $this->normalizeString($categoryId) !== ''
            ? $categoryId
            : $this->normalizeString($existing['category_id'] ?? null);

        /* Para itens novos geramos UUIDs para ligar item ↔ produto */
        $productUuid = $existingProductId !== '' ? $existingProductId : $this->generateUuidV4();
        $itemUuid    = $existingItemId    !== '' ? $existingItemId    : $this->generateUuidV4();

        $itemBody = [
            'id'           => $itemUuid,
            'type'         => 'DEFAULT',
            'categoryId'   => $usedCategoryId,
            'status'       => (int) ($product['product_active'] ?? 1) === 1 ? 'AVAILABLE' : 'UNAVAILABLE',
            'price'        => [
                'value'         => (float) $product['price'],
                'originalValue' => (float) $product['price'],
            ],
            'externalCode' => $ec,
            'productId'    => $productUuid,
            'index'        => 0,
        ];

        $productBody = [
            'id'           => $productUuid,
            'name'         => $product['name'],
            'description'  => $product['description'] ?? '',
            'externalCode' => $ec,
            'serving'      => 'SERVES_1',
        ];
        $modifierPayload = $this->buildIfoodCatalogModifierPayload($merchantId, $product, $existingItemFlat, $catalogProductsById);
        $productBody['optionGroups'] = $modifierPayload['product_option_groups'];
        $coverFileId = $product['cover_file_id'] ?? null;
        $sourceImageUrl = $this->buildPublicFileDownloadUrl($coverFileId);
        if ($sourceImageUrl) {
            $uploadedImagePath = $this->uploadIfoodCatalogImageAndResolvePath($merchantId, $coverFileId, $sourceImageUrl);
            if ($uploadedImagePath) {
                $productBody['imagePath'] = $uploadedImagePath;
            } else {
                self::$logger->warning('iFood catalog image upload skipped, proceeding without imagePath', [
                    'merchant_id' => $merchantId,
                    'product_id' => $product['id'] ?? null,
                    'image_url' => $sourceImageUrl,
                ]);
            }
        }

        $payload = [
            'item'         => $itemBody,
            'products'     => array_merge([$productBody], $modifierPayload['products']),
            'optionGroups' => $modifierPayload['option_groups'],
            'options'      => $modifierPayload['options'],
        ];

        $attempts = [$payload];
        if (isset($payload['products'][0]['imagePath'])) {
            $fallbackPayload = $payload;
            unset($fallbackPayload['products'][0]['imagePath']);
            $attempts[] = $fallbackPayload;
        }

        $lastStatus = null;
        $lastBody = '';
        $lastError = null;
        $lastPayload = null;

        foreach ($attempts as $attemptIndex => $attemptPayload) {
            foreach (array_merge([0], self::CATALOG_CONCURRENT_RETRY_DELAYS_US) as $retryIndex => $retryDelayUs) {
                if ($retryDelayUs > 0) {
                    usleep($retryDelayUs);
                }

                $lastPayload = $attemptPayload;
                try {
                    $response = $this->ifoodClient->request('PUT',
                        self::CATALOG_V2_BASE . rawurlencode($merchantId) . '/items',
                        [
                            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
                            'json'    => $attemptPayload,
                        ]
                    );
                    $status = $response->getStatusCode();
                    $body = substr($response->getContent(false), 0, 2000);
                    if ($status >= 200 && $status < 300) {
                        return ['ok' => true, 'http_status' => $status, 'ifood_body' => null, 'error' => null];
                    }

                    $lastStatus = $status;
                    $lastBody = $body;
                    $lastError = null;
                    $isConcurrentModification = $this->isIfoodCatalogConcurrentModification($status, $body);
                    if ($isConcurrentModification && $retryIndex < count(self::CATALOG_CONCURRENT_RETRY_DELAYS_US)) {
                        self::$logger->warning('iFood catalog v2 upsert concurrently modified, retrying same payload', [
                            'product_id' => $product['id'],
                            'status'     => $status,
                            'retry'      => $retryIndex + 1,
                        ]);
                        continue;
                    }

                    if (!$isConcurrentModification && $attemptIndex === 0 && count($attempts) > 1) {
                        self::$logger->warning('iFood catalog v2 upsert failed with imagePath, retrying without imagePath', [
                            'status'     => $status,
                            'product_id' => $product['id'],
                            'body'       => $body,
                        ]);
                        continue 2;
                    }
                } catch (\Throwable $e) {
                    $lastError = $e->getMessage();
                    if ($this->isIfoodCatalogConcurrentModification($lastStatus, $lastBody, $lastError) && $retryIndex < count(self::CATALOG_CONCURRENT_RETRY_DELAYS_US)) {
                        self::$logger->warning('iFood catalog v2 upsert concurrently modified exception, retrying same payload', [
                            'product_id' => $product['id'],
                            'retry'      => $retryIndex + 1,
                            'error'      => $e->getMessage(),
                        ]);
                        continue;
                    }

                    if ($attemptIndex === 0 && count($attempts) > 1) {
                        self::$logger->warning('iFood catalog v2 upsert exception with imagePath, retrying without imagePath', [
                            'product_id' => $product['id'],
                            'error' => $e->getMessage(),
                        ]);
                        continue 2;
                    }
                }

                break;
            }

            break;
        }

        if ($lastError !== null) {
            self::$logger->error('iFood catalog v2 upsert exception', [
                'product_id'   => $product['id'],
                'error'        => $lastError,
                'sent_payload' => $lastPayload,
            ]);
            return ['ok' => false, 'http_status' => null, 'ifood_body' => null, 'error' => $lastError, 'sent_payload' => $lastPayload];
        }

        self::$logger->warning('iFood catalog v2 upsert non-2xx', [
            'status'       => $lastStatus,
            'product_id'   => $product['id'],
            'body'         => $lastBody,
            'sent_payload' => $lastPayload,
        ]);
        return ['ok' => false, 'http_status' => $lastStatus, 'ifood_body' => $lastBody, 'error' => null, 'sent_payload' => $lastPayload];
    }


}
