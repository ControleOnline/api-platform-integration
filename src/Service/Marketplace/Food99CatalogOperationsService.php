<?php

namespace ControleOnline\Service\Marketplace;

use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Address;
use ControleOnline\Entity\Invoice;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\OrderProductQueue;
use ControleOnline\Entity\PaymentType;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductGroup;
use ControleOnline\Entity\ProductGroupProduct;
use ControleOnline\Entity\ProductUnity;
use ControleOnline\Entity\Status;
use ControleOnline\Entity\Wallet;
use ControleOnline\Service\Food99Service;
use ControleOnline\Service\Marketplace\MarketplaceIntegrationHandlerInterface;
use ControleOnline\Service\Marketplace\AbstractMarketplaceService;
use ControleOnline\Service\Marketplace\MarketplaceIntegrationStateProviderInterface;
use ControleOnline\Service\Marketplace\MarketplaceLogisticsQuoteProviderInterface;
use ControleOnline\Service\Marketplace\MarketplaceOrderSnapshotProviderInterface;
use ControleOnline\Event\EntityChangedEvent;
use DateTime;
use DateTimeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

class Food99CatalogOperationsService extends AbstractMarketplaceService
{
    private const APP_CONTEXT = Order::APP_FOOD99;

    protected function getMarketplaceApp(): string
    {
        return self::APP_CONTEXT;
    }

    private function normalizeIncomingFood99Value(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    private function normalizeExtraDataValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '';
    }

    private function getFood99ExtraDataValue(string $entityName, int $entityId, string $fieldName = 'code'): ?string
    {
        return $this->extraDataService->getExtraDataValue(
            Order::APP_FOOD99,
            $entityName,
            $entityId,
            $fieldName
        );
    }

    private function upsertFood99ExtraDataValue(
        string $entityName,
        int $entityId,
        string $fieldName,
        mixed $value,
        string $fieldType = 'text'
    ): void {
        $this->extraDataService->upsertExtraDataValue(
            Order::APP_FOOD99,
            $entityName,
            $entityId,
            $fieldName,
            $this->normalizeExtraDataValue($value),
            $fieldType,
            self::APP_CONTEXT
        );
    }

    private function callFood99ServiceMethod(string $method, array $arguments = []): mixed
    {
        $service = $this->resolveMarketplaceServiceInstance(Food99Service::class);
        if (!is_object($service)) {
            return null;
        }

        return $this->invokeMarketplaceServiceMethod($service, $method, $arguments);
    }

    private function call99EndpointWithResponse(string $uri, array $payload, ?People $provider = null): ?array
    {
        $response = $this->callFood99ServiceMethod(__FUNCTION__, [$uri, $payload, $provider]);

        return is_array($response) ? $response : null;
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

    private function resolveFood99StoreOperationsService(): ?Food99StoreOperationsService
    {
        $service = $this->resolveMarketplaceServiceInstance(Food99StoreOperationsService::class);

        return $service instanceof Food99StoreOperationsService ? $service : null;
    }

    public function getStoredIntegrationState(People $provider): array
    {
        $storeService = $this->resolveFood99StoreOperationsService();

        return $storeService instanceof Food99StoreOperationsService
            ? $storeService->getStoredIntegrationState($provider)
            : [];
    }

    private function fetchMenuProducts(People $provider, array $productIds = []): array
    {
        $connection = $this->entityManager->getConnection();
        $params = [
            'providerId' => $provider->getId(),
            'food99Context' => self::APP_CONTEXT,
            'codeFieldName' => 'code',
            'publishedFieldName' => 'published',
        ];
        $sql = <<<SQL
            SELECT
                p.id,
                p.product AS product_name,
                p.description,
                p.price,
                p.type,
                p.active,
                c.id AS category_id,
                c.name AS category_name,
                pf.file_id AS cover_file_id,
                ed.data_value AS food99_code,
                ed_published.data_value AS food99_published,
                CASE WHEN EXISTS (
                    SELECT 1
                    FROM product_group pg_req
                    INNER JOIN product_group_parent pg_parent_req
                        ON pg_parent_req.product_group_id = pg_req.id
                       AND pg_parent_req.parent_product_id = p.id
                       AND pg_parent_req.active = 1
                    INNER JOIN product_group_product pgp_req
                        ON pgp_req.product_group_id = pg_req.id
                    INNER JOIN product child_req
                        ON child_req.id = pgp_req.product_child_id
                    WHERE pg_req.active = 1
                      AND pgp_req.active = 1
                      AND child_req.active = 1
                      AND pgp_req.product_type IN ('component', 'package')
                      AND COALESCE(pg_req.required, 0) = 1
                      AND COALESCE(pg_req.minimum, 0) >= 1
                ) THEN 1 ELSE 0 END AS has_required_modifiers
            FROM product p
            LEFT JOIN product_category pc ON pc.id = (
                SELECT MIN(pc2.id)
                FROM product_category pc2
                INNER JOIN category c2 ON c2.id = pc2.category_id
                WHERE pc2.product_id = p.id
                  AND c2.context = 'products'
            )
            LEFT JOIN category c ON c.id = pc.category_id
            LEFT JOIN product_file pf ON pf.id = (
                SELECT MIN(pf2.id)
                FROM product_file pf2
                WHERE pf2.product_id = p.id
            )
            LEFT JOIN extra_fields ef
                ON ef.context = :food99Context
               AND ef.field_name = :codeFieldName
            LEFT JOIN extra_data ed
                ON ed.extra_fields_id = ef.id
               AND ed.entity_name = 'Product'
               AND ed.entity_id = p.id
            LEFT JOIN extra_fields ef_published
                ON ef_published.context = :food99Context
               AND ef_published.field_name = :publishedFieldName
            LEFT JOIN extra_data ed_published
                ON ed_published.extra_fields_id = ef_published.id
               AND ed_published.entity_name = 'Product'
               AND ed_published.entity_id = p.id
            WHERE p.company_id = :providerId
              AND p.active = 1
              AND p.type IN ('manufactured', 'custom', 'product')
        SQL;

        if (!empty($productIds)) {
            $placeholders = [];
            foreach ($productIds as $index => $productId) {
                $key = 'productId' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $productId;
            }
            $sql .= ' AND p.id IN (' . implode(', ', $placeholders) . ')';
        }

        $sql .= ' ORDER BY COALESCE(c.name, \'\'), p.product ASC';

        return $connection->fetchAllAssociative($sql, $params);
    }

    private function fetchMenuModifierRows(People $provider, array $productIds = []): array
    {
        $parentIds = $this->normalizeProductIds($productIds);
        if (empty($parentIds)) {
            return [];
        }

        $connection = $this->entityManager->getConnection();
        $params = [
            'providerId' => $provider->getId(),
            'food99Context' => self::APP_CONTEXT,
            'codeFieldName' => 'code',
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
                pgp.product_child_id AS child_product_id,
                pgp.price AS child_relation_price,
                child.product AS child_product_name,
                child.description AS child_description,
                child.price AS child_base_price,
                child_pf.file_id AS child_cover_file_id,
                ed_child.data_value AS child_food99_code
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
            LEFT JOIN extra_fields ef_code
                ON ef_code.context = :food99Context
               AND ef_code.field_name = :codeFieldName
            LEFT JOIN extra_data ed_child
                ON ed_child.extra_fields_id = ef_code.id
               AND ed_child.entity_name = 'Product'
               AND ed_child.entity_id = child.id
            WHERE parent.company_id = :providerId
              AND parent.active = 1
              AND child.active = 1
              AND pg.active = 1
              AND pgp.active = 1
              AND pgp.product_type IN ('component', 'package')
              AND group_parent.parent_product_id IN (%s)
            ORDER BY
                group_parent.parent_product_id ASC,
                COALESCE(pg.group_order, 0) ASC,
                pg.id ASC,
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

    private function buildMenuProductView(array $row): array
    {
        $productId = (int) ($row['id'] ?? 0);
        $productName = trim((string) ($row['product_name'] ?? ''));
        $categoryId = isset($row['category_id']) && $row['category_id'] !== null ? (int) $row['category_id'] : null;
        $categoryName = $row['category_name'] ? trim((string) $row['category_name']) : null;
        $price = round((float) ($row['price'] ?? 0), 2);
        $type = strtolower(trim((string) ($row['type'] ?? '')));
        $appItemId = trim((string) ($row['food99_code'] ?? '')) ?: (string) $productId;
        $published = in_array((string) ($row['food99_published'] ?? ''), ['1', 'true'], true);
        $hasRequiredModifiers = in_array((string) ($row['has_required_modifiers'] ?? ''), ['1', 'true'], true);
        $allowZeroPriceByGroups = $type === 'custom' && $hasRequiredModifiers;

        $blockers = [];
        if ($productName === '') {
            $blockers[] = 'Produto sem nome';
        }
        if (!$categoryId) {
            $blockers[] = 'Produto sem categoria';
        }
        if ($price <= 0 && !$allowZeroPriceByGroups) {
            $blockers[] = 'Produto com preco invalido';
        }

        $view = [
            'id' => $productId,
            'name' => $productName,
            'description' => trim((string) ($row['description'] ?? '')),
            'price' => $price,
            'type' => (string) ($row['type'] ?? ''),
            'category' => $categoryId ? [
                'id' => $categoryId,
                'name' => $categoryName,
            ] : null,
            'food99_code' => trim((string) ($row['food99_code'] ?? '')) ?: null,
            'food99_published' => $published,
            'has_required_modifiers' => $hasRequiredModifiers,
            'image_url' => $this->buildPublicFileDownloadUrl($row['cover_file_id'] ?? null),
            'suggested_app_item_id' => $appItemId,
            'eligible' => empty($blockers),
            'blockers' => $blockers,
        ];

        $view['sync'] = $this->buildFood99ProductSyncState($view, $published);

        return $view;
    }

    public function listSelectableMenuProducts(People $provider): array
    {
        $this->init();

        $products = array_map(
            fn(array $row) => $this->buildMenuProductView($row),
            $this->fetchMenuProducts($provider)
        );

        return [
            'provider_id' => $provider->getId(),
            'minimum_required_items' => 5,
            'eligible_product_count' => count(array_filter($products, fn(array $product) => $product['eligible'])),
            'products' => $products,
        ];
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

    private function buildFood99ProductSyncHash(array $product): string
    {
        return $this->buildCatalogSyncHash([
            'id' => (int) ($product['id'] ?? 0),
            'name' => trim((string) ($product['name'] ?? '')),
            'description' => trim((string) ($product['description'] ?? '')),
            'price' => round((float) ($product['price'] ?? 0), 2),
            'type' => trim((string) ($product['type'] ?? '')),
            'category_id' => (int) ($product['category']['id'] ?? 0),
            'category_name' => trim((string) ($product['category']['name'] ?? '')),
            'app_item_id' => trim((string) (
                $product['food99_code']
                ?? $product['suggested_app_item_id']
                ?? $product['id']
                ?? ''
            )),
            'has_required_modifiers' => !empty($product['has_required_modifiers']),
            'image_url' => trim((string) ($product['image_url'] ?? '')),
        ]);
    }

    private function buildFood99CategorySyncHash(array $category): string
    {
        return $this->buildCatalogSyncHash([
            'id' => (int) ($category['id'] ?? 0),
            'name' => trim((string) ($category['name'] ?? '')),
            'color' => trim((string) ($category['color'] ?? '')),
            'icon' => trim((string) ($category['icon'] ?? '')),
            'parent_id' => (int) ($category['parent_id'] ?? 0),
        ]);
    }

    private function buildFood99ProductSyncState(array $product, ?bool $publishedOverride = null): array
    {
        $productId = (int) ($product['id'] ?? 0);
        $published = $publishedOverride ?? (!empty($product['published_remotely']) || !empty($product['food99_published']));
        $currentHash = $this->buildFood99ProductSyncHash($product);
        $storedHash = $this->getFood99ExtraDataValue('Product', $productId, 'sync_hash') ?? '';
        $hasStoredHash = $storedHash !== '';
        $dirty = $published && (!$hasStoredHash || !hash_equals($storedHash, $currentHash));
        $remoteId = trim((string) (
            $product['food99_code']
            ?? $product['suggested_app_item_id']
            ?? ($published ? ($product['id'] ?? '') : '')
        ));

        return [
            'platform' => '99food',
            'remote_id' => $remoteId !== '' ? $remoteId : null,
            'published' => (bool) $published,
            'eligible' => !empty($product['eligible']),
            'synced' => (bool) ($published && !$dirty),
            'dirty' => (bool) $dirty,
            'last_synced_at' => $this->getFood99ExtraDataValue('Product', $productId, 'sync_synced_at'),
            'status' => !$published ? 'not_synced' : ($dirty ? 'dirty' : 'synced'),
        ];
    }

    private function buildFood99CategorySyncState(array $category, array $productIds, bool $published, bool $eligible): array
    {
        $categoryId = (int) ($category['id'] ?? 0);
        $currentHash = $this->buildFood99CategorySyncHash($category);
        $storedHash = $this->getFood99ExtraDataValue('Category', $categoryId, 'sync_hash') ?? '';
        $hasStoredHash = $storedHash !== '';
        $dirty = $published && (!$hasStoredHash || !hash_equals($storedHash, $currentHash));
        $remoteId = $published && $categoryId > 0 ? (string) $categoryId : null;

        return [
            'platform' => '99food',
            'remote_id' => $remoteId,
            'published' => (bool) $published,
            'eligible' => $eligible,
            'synced' => (bool) ($published && !$dirty),
            'dirty' => (bool) $dirty,
            'last_synced_at' => $this->getFood99ExtraDataValue('Category', $categoryId, 'sync_synced_at'),
            'status' => !$published ? 'not_synced' : ($dirty ? 'dirty' : 'synced'),
            'product_ids' => array_values(array_unique(array_map('intval', $productIds))),
        ];
    }

    private function fetchMenuCategories(People $provider): array
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
        $categories = $this->fetchMenuCategories($provider);
        $categoryProductIds = [];
        $categoryEligibleProductIds = [];
        $categoryPublished = [];
        $eligibleProductIds = [];
        $publishedProductIds = [];

        $productStatuses = array_map(function (array $product) use (&$categoryProductIds, &$categoryEligibleProductIds, &$categoryPublished, &$eligibleProductIds, &$publishedProductIds): array {
            $productId = (int) ($product['id'] ?? 0);
            $categoryId = (int) ($product['category']['id'] ?? 0);
            $sync = is_array($product['sync'] ?? null) ? $product['sync'] : $this->buildFood99ProductSyncState($product);

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
            $sync = $this->buildFood99CategorySyncState(
                $category,
                $productIds,
                !empty($categoryPublished[$categoryId]),
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
                'key' => '99food',
                'label' => '99Food',
                'active' => !empty($storedState['connected']) || !empty($storedState['remote_connected']),
                'connected' => !empty($storedState['connected']),
                'remote_connected' => !empty($storedState['remote_connected']),
                'store_code' => $storedState['food99_code'] ?? null,
                'last_sync_at' => $storedState['last_sync_at'] ?? null,
                'last_error_message' => $storedState['last_error_message'] ?? null,
            ],
            'products' => $productStatuses,
            'categories' => $categoryStatuses,
            'eligible_product_ids' => array_values(array_unique(array_map('intval', $eligibleProductIds))),
            'published_product_ids' => array_values(array_unique(array_map('intval', $publishedProductIds))),
            'minimum_required_items' => (int) ($productsResponse['minimum_required_items'] ?? 5),
        ];
    }

    private function markCategoriesCatalogSynced(People $provider, array $categoryIds): void
    {
        $categoryIdSet = array_flip(array_map('intval', $categoryIds));
        if (empty($categoryIdSet)) {
            return;
        }

        foreach ($this->fetchMenuCategories($provider) as $category) {
            $categoryId = (int) ($category['id'] ?? 0);
            if ($categoryId <= 0 || !isset($categoryIdSet[$categoryId])) {
                continue;
            }

            $this->upsertFood99ExtraDataValue('Category', $categoryId, 'sync_hash', $this->buildFood99CategorySyncHash($category));
            $this->upsertFood99ExtraDataValue('Category', $categoryId, 'sync_synced_at', date('Y-m-d H:i:s'));
        }
    }

    public function markProductsCatalogSynced(People $provider, array $productIds): void
    {
        $this->init();

        $rows = $this->fetchMenuProducts($provider, $this->normalizeProductIds($productIds));
        if (empty($rows)) {
            return;
        }

        $categoryIds = [];
        foreach ($rows as $row) {
            $product = $this->buildMenuProductView($row);
            $productId = (int) ($product['id'] ?? 0);
            if ($productId <= 0 || empty($product['eligible'])) {
                continue;
            }

            $this->upsertFood99ExtraDataValue('Product', $productId, 'sync_hash', $this->buildFood99ProductSyncHash($product));
            $this->upsertFood99ExtraDataValue('Product', $productId, 'sync_synced_at', date('Y-m-d H:i:s'));
            $this->upsertFood99ExtraDataValue('Product', $productId, 'published', '1');

            $categoryId = (int) ($product['category']['id'] ?? 0);
            if ($categoryId > 0) {
                $categoryIds[] = $categoryId;
            }
        }

        $this->markCategoriesCatalogSynced($provider, $categoryIds);
    }

    private function resolvePublishedRemoteItemIds(?array $menuDetails): array
    {
        $items = is_array($menuDetails['data']['items'] ?? null) ? $menuDetails['data']['items'] : [];

        return array_values(array_unique(array_filter(array_map(
            static fn(array $item) => isset($item['app_item_id']) ? (string) $item['app_item_id'] : null,
            array_filter($items, 'is_array')
        ))));
    }

    private function resolveIncomingProductCode(array $item, string $productType): string
    {
        foreach (['app_item_id', 'mdu_id', 'app_external_id'] as $key) {
            $candidate = $this->normalizeIncomingFood99Value($item[$key] ?? null);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        $fallbackSource = implode('|', array_filter([
            $productType,
            $this->normalizeIncomingFood99Value($item['name'] ?? null),
            $this->normalizeIncomingFood99Value($item['content_name'] ?? null),
            $this->normalizeIncomingFood99Value($item['app_content_id'] ?? null),
            $this->normalizeIncomingFood99Value($item['sku_price'] ?? null),
        ]));

        return 'food99:' . substr(sha1($fallbackSource !== '' ? $fallbackSource : json_encode($item)), 0, 24);
    }

    private function mapProductsWithRemoteCatalog(array $products, array $remoteItemIds): array
    {
        $remoteItemIdSet = array_flip($remoteItemIds);

        return array_map(function (array $product) use ($remoteItemIdSet) {
            $candidateId = (string) ($product['food99_code'] ?: $product['suggested_app_item_id'] ?: $product['id']);
            $product['published_remotely'] = isset($remoteItemIdSet[$candidateId]);
            $product['sync'] = $this->buildFood99ProductSyncState($product, $product['published_remotely']);

            return $product;
        }, $products);
    }

    private function resolveStatusLabel(?int $bizStatus): string
    {
        return match ($bizStatus) {
            1 => 'Online',
            2 => 'Offline',
            default => 'Indefinido',
        };
    }

    private function resolveSubStatusLabel(?int $subStatus): string
    {
        return match ($subStatus) {
            1 => 'Pronta',
            2 => 'Pausada',
            3 => 'Fechada',
            default => 'Indefinido',
        };
    }

    public function getIntegrationSnapshot(People $provider): array
    {
        $this->init();

        $products = $this->listSelectableMenuProducts($provider);
        $integratedStoreCode = $this->resolveMarketplaceProviderCode($provider, self::APP_CONTEXT);
        $connected = !empty($integratedStoreCode);

        $detail = [
            'provider' => [
                'id' => $provider->getId(),
                'name' => method_exists($provider, 'getName') ? $provider->getName() : null,
            ],
            'integration' => [
                'key' => '99food',
                'label' => '99Food',
                'minimum_required_items' => 5,
                'eligible_product_count' => $products['eligible_product_count'] ?? 0,
                'connected' => $connected,
                'remote_connected' => false,
                'food99_code' => $integratedStoreCode,
                'app_shop_id' => (string) $provider->getId(),
                'auth_available' => false,
                'online' => false,
                'biz_status' => null,
                'biz_status_label' => 'Indefinido',
                'sub_biz_status' => null,
                'sub_biz_status_label' => 'Indefinido',
            ],
            'store' => null,
            'delivery_areas' => null,
            'menu' => [
                'remote_item_ids' => [],
            ],
            'products' => array_merge($products, [
                'published_product_count' => 0,
                'products' => $this->mapProductsWithRemoteCatalog($products['products'] ?? [], []),
            ]),
            'errors' => [],
        ];

        if (!$connected) {
            return $detail;
        }

        $food99Client = $this->resolveFood99Client();
        $authToken = $food99Client ? $food99Client->resolveIntegrationAccessToken($provider) : null;
        $detail['integration']['auth_available'] = !empty($authToken);

        if (!$authToken) {
            $detail['errors']['auth'] = 'Nao foi possivel obter o auth_token da loja na 99Food.';
            return $detail;
        }

        try {
            $storeDetails = $this->getStoreDetails($provider);
            $detail['store'] = $storeDetails;

            $remoteConnected = is_array($storeDetails) && $this->isSuccessfulErrno($storeDetails['errno'] ?? null);
            $detail['integration']['remote_connected'] = $remoteConnected;

            $remoteStore = is_array($storeDetails['data'] ?? null) ? $storeDetails['data'] : null;
            $bizStatus = isset($remoteStore['biz_status']) ? (int) $remoteStore['biz_status'] : null;
            $subBizStatus = isset($remoteStore['sub_biz_status']) ? (int) $remoteStore['sub_biz_status'] : null;

            $detail['integration']['online'] = $remoteConnected && $bizStatus === 1;
            $detail['integration']['biz_status'] = $bizStatus;
            $detail['integration']['biz_status_label'] = $this->resolveStatusLabel($bizStatus);
            $detail['integration']['sub_biz_status'] = $subBizStatus;
            $detail['integration']['sub_biz_status_label'] = $this->resolveSubStatusLabel($subBizStatus);
        } catch (\Throwable $e) {
            $detail['errors']['store'] = $e->getMessage();
        }

        try {
            $deliveryAreas = $this->listDeliveryAreas($provider);
            $detail['delivery_areas'] = $deliveryAreas;
        } catch (\Throwable $e) {
            $detail['errors']['delivery_areas'] = $e->getMessage();
        }

        try {
            $menuDetails = $this->getStoreMenuDetails($provider);
            $remoteItemIds = $this->resolvePublishedRemoteItemIds($menuDetails);
            $mappedProducts = $this->mapProductsWithRemoteCatalog($products['products'] ?? [], $remoteItemIds);

            $detail['menu'] = array_merge(is_array($menuDetails) ? $menuDetails : [], [
                'remote_item_ids' => $remoteItemIds,
            ]);
            $detail['products'] = array_merge($products, [
                'products' => $mappedProducts,
                'published_product_count' => count(array_filter(
                    $mappedProducts,
                    static fn(array $product) => !empty($product['published_remotely'])
                )),
            ]);
        } catch (\Throwable $e) {
            $detail['errors']['menu'] = $e->getMessage();
        }

        return $detail;
    }

    public function syncIntegrationState(People $provider): array
    {
        $this->init();

        $sync = [
            'auth_available' => false,
            'store' => null,
            'delivery_areas' => null,
            'menu' => null,
            'errors' => [],
        ];

        $food99Client = $this->resolveFood99Client();
        $authToken = $food99Client ? $food99Client->resolveIntegrationAccessToken($provider) : null;
        $sync['auth_available'] = !empty($authToken);

        if (!$authToken) {
            $message = 'Nao foi possivel obter o auth_token da loja na 99Food.';
            $this->persistIntegrationAuthError($provider, $message);
            $sync['errors']['auth'] = $message;
            return $sync;
        }

        $this->clearIntegrationError($provider);

        $storeDetails = $this->getStoreDetails($provider);
        $sync['store'] = $storeDetails;
        if (!$this->isSuccessfulErrno($storeDetails['errno'] ?? null)) {
            $sync['errors']['store'] = $storeDetails['errmsg'] ?? 'Nao foi possivel sincronizar os detalhes da loja.';
        }

        $deliveryAreas = $this->listDeliveryAreas($provider);
        $sync['delivery_areas'] = $deliveryAreas;
        if (!$this->isSuccessfulErrno($deliveryAreas['errno'] ?? null)) {
            $sync['errors']['delivery_areas'] = $deliveryAreas['errmsg'] ?? 'Nao foi possivel sincronizar as areas de entrega.';
        }

        $menuDetails = $this->getStoreMenuDetails($provider);
        $sync['menu'] = $menuDetails;
        if (!$this->isSuccessfulErrno($menuDetails['errno'] ?? null)) {
            $sync['errors']['menu'] = $menuDetails['errmsg'] ?? 'Nao foi possivel sincronizar o menu remoto.';
        }

        return $sync;
    }

    public function buildStoreMenuPayloadFromProducts(People $provider, array $productIds): array
    {
        $this->init();

        $selectedIds = $this->normalizeProductIds($productIds);
        if (empty($selectedIds)) {
            return [
                'provider_id' => $provider->getId(),
                'selected_product_count' => 0,
                'eligible_product_count' => 0,
                'errors' => ['Nenhum produto foi selecionado para a integracao.'],
                'products' => [],
                'payload' => null,
            ];
        }

        $rows = $this->fetchMenuProducts($provider, $selectedIds);
        $products = array_map(fn(array $row) => $this->buildMenuProductView($row), $rows);
        $resolvedIds = array_map(fn(array $product) => (int) $product['id'], $products);

        $errors = [];
        if (count($resolvedIds) !== count($selectedIds)) {
            $missingIds = array_values(array_diff($selectedIds, $resolvedIds));
            $errors[] = 'Um ou mais produtos selecionados nao pertencem a empresa atual ou nao estao ativos.';
            if (!empty($missingIds)) {
                self::$logger->warning('Food99 menu builder skipped missing products', [
                    'provider_id' => $provider->getId(),
                    'missing_product_ids' => $missingIds,
                ]);
            }
        }

        $ineligibleProducts = array_values(array_filter($products, fn(array $product) => !$product['eligible']));
        if (!empty($ineligibleProducts)) {
            $errors[] = 'Existem produtos selecionados sem categoria ou com preco invalido.';
        }

        $eligibleProducts = array_values(array_filter($products, fn(array $product) => $product['eligible']));
        if (count($eligibleProducts) < 5) {
            $errors[] = 'A 99Food exige pelo menos 5 produtos unicos elegiveis para publicar o menu.';
        }

        if (!empty($errors)) {
            return [
                'provider_id' => $provider->getId(),
                'selected_product_count' => count($products),
                'eligible_product_count' => count($eligibleProducts),
                'errors' => $errors,
                'products' => $products,
                'payload' => null,
            ];
        }

        $categoriesMap = [];
        $itemsById = [];
        $parentItemIdByProductId = [];
        $nextPriority = 1;

        $upsertItem = static function (array &$itemsById, string $appItemId, array $itemData) use (&$nextPriority): void {
            $itemData['app_item_id'] = $appItemId;

            if (!isset($itemsById[$appItemId])) {
                $itemData['priority'] = $itemData['priority'] ?? $nextPriority++;
                $itemData['status'] = $itemData['status'] ?? 1;
                $itemData['short_desc'] = $itemData['short_desc'] ?? '';
                $itemData['is_sold_separately'] = $itemData['is_sold_separately'] ?? true;
                $itemsById[$appItemId] = $itemData;
                return;
            }

            $existingItem = $itemsById[$appItemId];

            if (
                !empty($itemData['item_name'])
                && (!isset($existingItem['item_name']) || trim((string) $existingItem['item_name']) === '')
            ) {
                $existingItem['item_name'] = $itemData['item_name'];
            }

            if (
                array_key_exists('price', $itemData)
                && (!isset($existingItem['price']) || (int) $existingItem['price'] === 0)
            ) {
                $existingItem['price'] = (int) $itemData['price'];
            }

            if (!empty($itemData['short_desc']) && empty($existingItem['short_desc'])) {
                $existingItem['short_desc'] = $itemData['short_desc'];
            }

            if (!empty($itemData['head_img']) && empty($existingItem['head_img'])) {
                $existingItem['head_img'] = $itemData['head_img'];
            }

            if (($itemData['is_sold_separately'] ?? false) === true) {
                $existingItem['is_sold_separately'] = true;
            }

            $itemsById[$appItemId] = $existingItem;
        };

        foreach ($eligibleProducts as $index => $product) {
            $categoryId = (string) $product['category']['id'];
            if (!isset($categoriesMap[$categoryId])) {
                $categoriesMap[$categoryId] = [
                    'app_category_id' => $categoryId,
                    'category_name' => $product['category']['name'],
                    'app_item_ids' => [],
                    'priority' => count($categoriesMap) + 1,
                ];
            }

            $appItemId = (string) $product['suggested_app_item_id'];
            $parentItemIdByProductId[(int) $product['id']] = $appItemId;
            $categoriesMap[$categoryId]['app_item_ids'][] = $appItemId;

            $itemPayload = [
                'item_name' => $product['name'],
                'short_desc' => $product['description'],
                'price' => (int) round($product['price'] * 100),
                'priority' => $index + 1,
                'is_sold_separately' => true,
            ];
            if (!empty($product['image_url'])) {
                $itemPayload['head_img'] = (string) $product['image_url'];
            }
            $upsertItem($itemsById, $appItemId, $itemPayload);
        }

        foreach ($categoriesMap as &$category) {
            $category['app_item_ids'] = array_values(array_unique($category['app_item_ids']));
        }
        unset($category);

        $modifierRows = $this->fetchMenuModifierRows($provider, array_keys($parentItemIdByProductId));
        $modifierGroupsMap = [];

        foreach ($modifierRows as $modifierRow) {
            $parentProductId = (int) ($modifierRow['parent_product_id'] ?? 0);
            if ($parentProductId <= 0 || !isset($parentItemIdByProductId[$parentProductId])) {
                continue;
            }

            $groupId = (int) ($modifierRow['product_group_id'] ?? 0);
            if ($groupId <= 0) {
                continue;
            }

            $appModifierGroupId = 'mg_' . $groupId;
            if (!isset($modifierGroupsMap[$appModifierGroupId])) {
                $isRequired = (int) ($modifierRow['group_required'] ?? 0) === 1;
                $minimum = max(0, (int) round((float) ($modifierRow['group_minimum'] ?? 0)));
                $maximum = max(0, (int) round((float) ($modifierRow['group_maximum'] ?? 0)));

                if ($isRequired && $minimum === 0) {
                    $minimum = 1;
                }

                $modifierGroupsMap[$appModifierGroupId] = [
                    'app_modifier_group_id' => $appModifierGroupId,
                    'modifier_group_name' => trim((string) ($modifierRow['product_group_name'] ?? 'Grupo')),
                    'is_required' => $isRequired ? 1 : 2,
                    'quantity_min_permitted' => $minimum,
                    'quantity_max_permitted' => $maximum,
                    'buy_mode' => 0,
                    'app_mg_items' => [],
                    '_parent_item_ids' => [],
                ];
            }

            $parentAppItemId = $parentItemIdByProductId[$parentProductId];
            if (!in_array($parentAppItemId, $modifierGroupsMap[$appModifierGroupId]['_parent_item_ids'], true)) {
                $modifierGroupsMap[$appModifierGroupId]['_parent_item_ids'][] = $parentAppItemId;
            }

            $childProductId = (int) ($modifierRow['child_product_id'] ?? 0);
            $childName = trim((string) ($modifierRow['child_product_name'] ?? ''));
            if ($childProductId <= 0 || $childName === '') {
                continue;
            }

            $childAppItemId = trim((string) ($modifierRow['child_food99_code'] ?? ''));
            if ($childAppItemId === '') {
                $childAppItemId = (string) $childProductId;
            }

            $rawChildPrice = $modifierRow['child_relation_price'] ?? null;
            $childPrice = ($rawChildPrice === null || $rawChildPrice === '')
                ? (float) ($modifierRow['child_base_price'] ?? 0)
                : (float) $rawChildPrice;

            $childPriceCents = max(0, (int) round($childPrice * 100));
            $childImageUrl = $this->buildPublicFileDownloadUrl($modifierRow['child_cover_file_id'] ?? null);

            $childPayload = [
                'item_name' => $childName,
                'short_desc' => trim((string) ($modifierRow['child_description'] ?? '')),
                'price' => $childPriceCents,
                'is_sold_separately' => false,
            ];
            if ($childImageUrl) {
                $childPayload['head_img'] = $childImageUrl;
            }
            $upsertItem($itemsById, $childAppItemId, $childPayload);

            $modifierGroupsMap[$appModifierGroupId]['app_mg_items'][] = [
                'app_item_id' => $childAppItemId,
                'price' => $childPriceCents,
            ];
        }

        $modifierGroups = [];
        foreach ($modifierGroupsMap as $modifierGroup) {
            $deduplicatedModifierItems = [];
            foreach ($modifierGroup['app_mg_items'] as $modifierItem) {
                $modifierItemId = (string) ($modifierItem['app_item_id'] ?? '');
                if ($modifierItemId === '' || isset($deduplicatedModifierItems[$modifierItemId])) {
                    continue;
                }

                $deduplicatedModifierItems[$modifierItemId] = [
                    'app_item_id' => $modifierItemId,
                    'price' => max(0, (int) ($modifierItem['price'] ?? 0)),
                ];
            }

            if (empty($deduplicatedModifierItems)) {
                continue;
            }

            $modifierGroup['app_mg_items'] = array_values($deduplicatedModifierItems);
            $modifierItemCount = count($modifierGroup['app_mg_items']);

            if (($modifierGroup['quantity_max_permitted'] ?? 0) <= 0) {
                $modifierGroup['quantity_max_permitted'] = $modifierItemCount;
            }

            if (($modifierGroup['quantity_max_permitted'] ?? 0) < ($modifierGroup['quantity_min_permitted'] ?? 0)) {
                $modifierGroup['quantity_max_permitted'] = $modifierGroup['quantity_min_permitted'];
            }

            foreach ($modifierGroup['_parent_item_ids'] as $parentItemId) {
                if (!isset($itemsById[$parentItemId])) {
                    continue;
                }

                if (!isset($itemsById[$parentItemId]['app_modifier_group_ids'])) {
                    $itemsById[$parentItemId]['app_modifier_group_ids'] = [];
                }

                if (!in_array($modifierGroup['app_modifier_group_id'], $itemsById[$parentItemId]['app_modifier_group_ids'], true)) {
                    $itemsById[$parentItemId]['app_modifier_group_ids'][] = $modifierGroup['app_modifier_group_id'];
                }
            }

            unset($modifierGroup['_parent_item_ids']);
            $modifierGroups[] = $modifierGroup;
        }

        $payload = [
            'menus' => [[
                'app_menu_id' => 'menu_' . $provider->getId() . '_principal',
                'menu_name' => 'Cardapio Principal',
                'app_category_ids' => array_values(array_map(
                    static fn(string|int $categoryId) => (string) $categoryId,
                    array_keys($categoriesMap)
                )),
            ]],
            'categories' => array_values($categoriesMap),
            'items' => array_values($itemsById),
            'modifier_groups' => $modifierGroups,
        ];

        return [
            'provider_id' => $provider->getId(),
            'selected_product_count' => count($products),
            'eligible_product_count' => count($eligibleProducts),
            'errors' => [],
            'products' => $products,
            'payload' => $payload,
        ];
    }

    public function ensureMenuProductCodes(People $provider, array $productIds): array
    {
        $this->init();

        $selectedIds = $this->normalizeProductIds($productIds);
        if (empty($selectedIds)) {
            return [];
        }

        $products = $this->entityManager->getRepository(Product::class)->findBy([
            'id' => $selectedIds,
        ]);

        $codes = [];
        foreach ($products as $product) {
            $code = $this->findLocalFoodCodeByEntity('Product', (int) $product->getId());
            if (!$code) {
                $code = (string) $product->getId();
                $persistedCode = $this->persistLocalFoodCodeByEntity('Product', (int) $product->getId(), $code);
                $code = $persistedCode ?: $code;
            }

            $codes[$product->getId()] = (string) $code;
        }

        self::$logger->info('Food99 product codes ensured for menu upload', [
            'provider_id' => $provider->getId(),
            'product_ids' => array_keys($codes),
        ]);

        return $codes;
    }

    private function getModifierGroupItemIds(array $modifierGroups): array
    {
        $modifierItemIds = [];

        foreach ($modifierGroups as $modifierGroup) {
            if (!is_array($modifierGroup)) {
                continue;
            }

            foreach (($modifierGroup['app_mg_items'] ?? []) as $modifierItem) {
                if (!is_array($modifierItem) || empty($modifierItem['app_item_id'])) {
                    continue;
                }

                $modifierItemIds[] = (string) $modifierItem['app_item_id'];
            }

            foreach (($modifierGroup['app_mg_item_ids'] ?? []) as $modifierItemId) {
                if ($modifierItemId === null || $modifierItemId === '') {
                    continue;
                }

                $modifierItemIds[] = (string) $modifierItemId;
            }
        }

        return array_values(array_unique($modifierItemIds));
    }

    private function isActiveMenuItem(array $item): bool
    {
        $status = $item['status'] ?? null;

        return $status === null || (string) $status === '1';
    }

    private function isStandaloneMenuItem(array $item): bool
    {
        $isSoldSeparately = $item['is_sold_separately'] ?? null;

        if ($isSoldSeparately === null) {
            return true;
        }

        return in_array($isSoldSeparately, [true, 1, '1', 'true'], true);
    }

    private function getUniqueSellableMenuItemIds(array $payload): array
    {
        $sellableItemIds = [];

        foreach (($payload['items'] ?? []) as $item) {
            if (!is_array($item) || empty($item['app_item_id'])) {
                continue;
            }

            $itemId = (string) $item['app_item_id'];

            if (!$this->isStandaloneMenuItem($item) || !$this->isActiveMenuItem($item)) {
                continue;
            }

            $sellableItemIds[] = $itemId;
        }

        return array_values(array_unique($sellableItemIds));
    }

    private function validateStoreMenuPayload(People $provider, array $payload): ?array
    {
        if (!isset($payload['menus'], $payload['categories'], $payload['items'])) {
            self::$logger->warning('Food99 uploadStoreMenu called without required menu sections', [
                'provider_id' => $provider->getId(),
                'payload_keys' => array_keys($payload),
            ]);

            return null;
        }

        $sellableItemIds = $this->getUniqueSellableMenuItemIds($payload);
        $sellableItemCount = count($sellableItemIds);

        if ($sellableItemCount >= 5) {
            return null;
        }

        self::$logger->warning('Food99 menu upload blocked because the menu has fewer than 5 unique sellable items', [
            'provider_id' => $provider->getId(),
            'sellable_item_count' => $sellableItemCount,
            'sellable_item_ids' => $sellableItemIds,
        ]);

        return [
            'errno' => 10002,
            'errmsg' => 'Food99 requires at least 5 unique sellable items before publishing a store menu.',
            'data' => [
                'sellable_item_count' => $sellableItemCount,
                'sellable_item_ids' => $sellableItemIds,
            ],
        ];
    }

    private function isHttpImageUrl(?string $url): bool
    {
        if (!$url) {
            return false;
        }

        return (bool) preg_match('/^https?:\\/\\//i', trim($url));
    }

    private function buildFood99ImageUploadExt(People $provider, string $appItemId, string $sourceUrl): string
    {
        $base = sprintf('%s-%s', (string) $provider->getId(), $appItemId !== '' ? $appItemId : md5($sourceUrl));
        $base = trim($base);

        if ($base === '') {
            $base = md5($sourceUrl);
        }

        if (function_exists('mb_substr')) {
            return mb_substr($base, 0, 255);
        }

        return substr($base, 0, 255);
    }

    private function syncStoreMenuHeadImagesToFood99(People $provider, array &$payload): array
    {
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        if (empty($items)) {
            return [
                'uploaded' => 0,
                'reused' => 0,
                'failed' => 0,
                'skipped' => 0,
            ];
        }

        $uploadedBySourceUrl = [];
        $failedSourceUrls = [];
        $stats = [
            'uploaded' => 0,
            'reused' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $stats['skipped']++;
                continue;
            }

            $sourceUrl = trim((string) ($item['head_img'] ?? ''));
            if (!$this->isHttpImageUrl($sourceUrl)) {
                $stats['skipped']++;
                continue;
            }

            if (isset($uploadedBySourceUrl[$sourceUrl])) {
                $payload['items'][$index]['head_img'] = $uploadedBySourceUrl[$sourceUrl];
                $stats['reused']++;
                continue;
            }

            if (isset($failedSourceUrls[$sourceUrl])) {
                unset($payload['items'][$index]['head_img']);
                $stats['failed']++;
                continue;
            }

            $appItemId = trim((string) ($item['app_item_id'] ?? ''));
            $normalizedImagePath = $this->normalizeImageForFood99Upload($sourceUrl, (int) $provider->getId(), $appItemId);
            $uploadPayload = [
                'ext' => $this->buildFood99ImageUploadExt($provider, $appItemId, $sourceUrl),
            ];

            if ($normalizedImagePath) {
                $uploadPayload['image_file'] = DataPart::fromPath($normalizedImagePath, basename($normalizedImagePath), 'image/jpeg');
            } else {
                $uploadPayload['image_url'] = $sourceUrl;
            }

            $uploadResponse = $this->uploadImage($provider, $uploadPayload);

            $uploadedUrl = trim((string) ($uploadResponse['data']['giftUrl'] ?? ''));
            if ($this->isSuccessfulErrno($uploadResponse['errno'] ?? null) && $uploadedUrl !== '') {
                $uploadedBySourceUrl[$sourceUrl] = $uploadedUrl;
                $payload['items'][$index]['head_img'] = $uploadedUrl;
                $stats['uploaded']++;
                if ($normalizedImagePath && file_exists($normalizedImagePath)) {
                    @unlink($normalizedImagePath);
                }
                continue;
            }

            if ($normalizedImagePath && file_exists($normalizedImagePath)) {
                @unlink($normalizedImagePath);
            }

            $failedSourceUrls[$sourceUrl] = true;
            $stats['failed']++;
            unset($payload['items'][$index]['head_img']);

            self::$logger->warning('Food99 image upload falhou; head_img removido do payload para evitar URL inacessivel', [
                'provider_id' => $provider->getId(),
                'app_item_id' => $appItemId,
                'image_url' => $sourceUrl,
                'errno' => $uploadResponse['errno'] ?? null,
                'errmsg' => $uploadResponse['errmsg'] ?? null,
            ]);
        }

        return $stats;
    }

    /**
     * Converte bytes brutos de imagem para recurso GD.
     * Tenta GD nativo primeiro; para formatos não suportados (SVG, AVIF, etc.)
     * tenta Imagick se disponível, convertendo internamente para JPEG antes.
     */
    private function tryRawToGdImage(string $raw): ?\GdImage
    {
        $image = @imagecreatefromstring($raw);
        if ($image instanceof \GdImage) {
            return $image;
        }

        if (!class_exists('Imagick')) {
            return null;
        }

        try {
            $imagick = new \Imagick();
            $imagick->setResolution(150, 150);
            $imagick->readImageBlob($raw);
            $imagick->setImageBackgroundColor(new \ImagickPixel('white'));
            $imagick = $imagick->flattenImages();
            $imagick->setImageFormat('jpeg');
            $jpeg = $imagick->getImageBlob();
            $imagick->clear();
            $result = @imagecreatefromstring($jpeg);
            return ($result instanceof \GdImage) ? $result : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function normalizeImageForFood99Upload(string $sourceUrl, int $providerId, string $appItemId): ?string
    {
        try {
            if (!$this->isHttpImageUrl($sourceUrl) || !function_exists('imagecreatefromstring')) {
                return null;
            }

            $response = $this->httpClient->request('GET', $sourceUrl);
            if ($response->getStatusCode() >= 400) {
                return null;
            }

            $raw = $response->getContent(false);
            if (!$raw) {
                return null;
            }

            $image = $this->tryRawToGdImage($raw);
            if (!$image) {
                return null;
            }

            $width = imagesx($image);
            $height = imagesy($image);

            if ($width <= 0 || $height <= 0) {
                imagedestroy($image);
                return null;
            }

            $maxDimension = 3000;
            $minDimension = 150;
            $targetWidth = $width;
            $targetHeight = $height;

            if ($targetWidth > $maxDimension || $targetHeight > $maxDimension) {
                $scale = min($maxDimension / $targetWidth, $maxDimension / $targetHeight);
                $targetWidth = (int) max(1, floor($targetWidth * $scale));
                $targetHeight = (int) max(1, floor($targetHeight * $scale));
            }

            if ($targetWidth < $minDimension || $targetHeight < $minDimension) {
                $scale = max($minDimension / max(1, $targetWidth), $minDimension / max(1, $targetHeight));
                $targetWidth = (int) max($minDimension, ceil($targetWidth * $scale));
                $targetHeight = (int) max($minDimension, ceil($targetHeight * $scale));
            }

            $normalized = imagecreatetruecolor($targetWidth, $targetHeight);
            imagealphablending($normalized, true);
            imagesavealpha($normalized, false);
            $white = imagecolorallocate($normalized, 255, 255, 255);
            imagefilledrectangle($normalized, 0, 0, $targetWidth, $targetHeight, $white);
            imagecopyresampled($normalized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
            imagedestroy($image);

            $tempBase = tempnam(sys_get_temp_dir(), 'food99_img_');
            if (!$tempBase) {
                imagedestroy($normalized);
                return null;
            }

            $targetPath = $tempBase . '.jpg';
            @unlink($tempBase);

            $quality = 90;
            $saved = false;

            while ($quality >= 70) {
                $saved = imagejpeg($normalized, $targetPath, $quality);
                if ($saved && file_exists($targetPath) && filesize($targetPath) <= 10 * 1024 * 1024) {
                    break;
                }
                $quality -= 5;
            }

            imagedestroy($normalized);

            if (!$saved || !file_exists($targetPath)) {
                if (file_exists($targetPath)) {
                    @unlink($targetPath);
                }
                return null;
            }

            if (filesize($targetPath) > 10 * 1024 * 1024) {
                @unlink($targetPath);
                return null;
            }

            return $targetPath;
        } catch (\Throwable $e) {
            self::$logger->warning('Food99 image normalization failed before upload', [
                'provider_id' => $providerId,
                'app_item_id' => $appItemId,
                'image_url' => $sourceUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function uploadStoreMenu(People $provider, array $payload): ?array
    {
        $this->init();

        if (!isset($payload['modifier_groups'])) {
            $payload['modifier_groups'] = [];
        }

        $validationError = $this->validateStoreMenuPayload($provider, $payload);
        if ($validationError) {
            return $validationError;
        }

        $imageSyncStats = $this->syncStoreMenuHeadImagesToFood99($provider, $payload);

        $response = $this->call99EndpointWithResponse('/v3/item/item/upload', $payload, $provider);
        $taskId = is_array($response['data'] ?? null) ? ($response['data']['taskID'] ?? null) : null;
        $response = is_array($response) ? $this->normalizeMenuTaskResponse($response, $taskId) : $response;

        if (is_array($response)) {
            $response['image_sync'] = $imageSyncStats;
        }

        if ($this->isSuccessfulErrno($response['errno'] ?? null)) {
            $this->persistProviderMenuUploadSubmission($provider, $response, $taskId);
            return $response;
        }

        $this->persistProviderLastError($provider, $response['errno'] ?? null, $response['errmsg'] ?? null);

        return $response;
    }

    public function getMenuUploadTaskInfo(People $provider, int|string $taskId): ?array
    {
        $this->init();

        $response = $this->call99EndpointWithResponse('/v1/item/item/getMenuTaskInfo', [
            'task_id' => $taskId,
        ], $provider);
        $response = is_array($response) ? $this->normalizeMenuTaskResponse($response, $taskId) : $response;

        if (!$this->isSuccessfulErrno($response['errno'] ?? null)) {
            $this->persistProviderLastError($provider, $response['errno'] ?? null, $response['errmsg'] ?? null);
            return $response;
        }

        $taskState = $this->persistProviderMenuTaskState($provider, $response, $taskId);

        if ($taskState === 'completed') {
            $menuResponse = $this->getStoreMenuDetails($provider);
            if ($this->isSuccessfulErrno($menuResponse['errno'] ?? null)) {
                $this->markProviderMenuPublished($provider);
            } else {
                $this->markProviderMenuSyncError($provider, $menuResponse['errmsg'] ?? null);
            }
        }

        return $response;
    }

}
