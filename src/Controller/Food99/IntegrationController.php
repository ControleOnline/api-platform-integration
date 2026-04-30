<?php

namespace ControleOnline\Controller\Food99;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Service\Food99Service;
use ControleOnline\Service\PeopleService;
use ControleOnline\Service\iFoodService;
use ControleOnline\Service\LoggerService;
use ControleOnline\Service\RequestPayloadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface as Security;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;

#[SecurityAttribute("is_granted('ROLE_HUMAN')")]
class IntegrationController extends AbstractController
{
    protected static $logger;

    public function __construct(
        private EntityManagerInterface $manager,
        private LoggerService $loggerService,
        private Security $security,
        private PeopleService $peopleService,
        private Food99Service $food99Service,
        private iFoodService $iFoodService,
        private RequestPayloadService $requestPayloadService,
    ) {
        self::$logger = $loggerService->getLogger('Food99');
    }

    private function getAuthenticatedPeople(): ?People
    {
        $user = $this->security->getToken()?->getUser();

        if (!is_object($user) || !method_exists($user, 'getPeople')) {
            return null;
        }

        $people = $user->getPeople();

        return $people instanceof People ? $people : null;
    }

    private function parseJsonBody(Request $request): array
    {
        $content = trim((string) $request->getContent());
        if ($content === '') {
            return [];
        }

        return $this->requestPayloadService->decodeJsonContent($content);
    }

    private function canAccessProvider(People $userPeople, People $provider): bool
    {
        if ($userPeople->getId() === $provider->getId()) {
            return true;
        }

        return $this->peopleService->canAccessCompany($provider, $userPeople);
    }

    private function resolveDefaultProvider(?People $userPeople): ?People
    {
        if (!$userPeople) {
            return null;
        }

        if ($this->peopleService->canAccessCompany($userPeople, $userPeople)) {
            return $userPeople;
        }

        $companies = $this->peopleService->getMyCompanies();

        return count($companies) === 1 ? $companies[0] : null;
    }

    private function resolveProvider(Request $request, array $payload = []): ?People
    {
        $providerId = $payload['provider_id']
            ?? $payload['company_id']
            ?? $request->query->get('provider_id')
            ?? $request->query->get('company_id');

        $userPeople = $this->getAuthenticatedPeople();

        if (!$providerId) {
            return $this->resolveDefaultProvider($userPeople);
        }

        $providerId = $this->requestPayloadService->normalizeOptionalNumericId($providerId);
        if (!$providerId) {
            return null;
        }

        $provider = $this->manager->getRepository(People::class)->find($providerId);
        if (!$provider) {
            return null;
        }

        if (!$userPeople) {
            return null;
        }

        if ($userPeople && !$this->canAccessProvider($userPeople, $provider)) {
            return null;
        }

        return $provider;
    }

    private function providerErrorResponse(): JsonResponse
    {
        return new JsonResponse([
            'error' => 'Provider not found or access denied',
        ], Response::HTTP_FORBIDDEN);
    }

    private function resolveOrder(string|int $orderId): ?Order
    {
        $normalizedOrderId = $this->requestPayloadService->normalizeOptionalNumericId($orderId);
        if (!$normalizedOrderId) {
            return null;
        }

        $order = $this->manager->getRepository(Order::class)->find($normalizedOrderId);
        if (!$order instanceof Order) {
            return null;
        }

        $provider = $order->getProvider();
        if (!$provider instanceof People) {
            return null;
        }

        $userPeople = $this->getAuthenticatedPeople();
        if (!$userPeople) {
            return null;
        }

        if ($userPeople && !$this->canAccessProvider($userPeople, $provider)) {
            return null;
        }

        return $order;
    }

    private function orderErrorResponse(string $message = 'Order not found or access denied'): JsonResponse
    {
        return new JsonResponse([
            'error' => $message,
        ], Response::HTTP_FORBIDDEN);
    }

    private function isFood99Order(Order $order): bool
    {
        return strcasecmp((string) $order->getApp(), Order::APP_FOOD99) === 0;
    }

    private function extractProductIds(array $payload): array
    {
        $productIds = $payload['product_ids'] ?? $payload['products'] ?? [];

        if (!is_array($productIds)) {
            return [];
        }

        return $productIds;
    }

    private function resolveBizStatusLabel(?int $bizStatus): string
    {
        return match ($bizStatus) {
            1 => 'Online',
            2 => 'Offline',
            default => 'Indefinido',
        };
    }

    private function resolveSubBizStatusLabel(?int $subStatus): string
    {
        return match ($subStatus) {
            1 => 'Pronta',
            2 => 'Pausada',
            3 => 'Fechada',
            default => 'Indefinido',
        };
    }

    private function isSuccessfulErrno(mixed $errno): bool
    {
        if ($errno === null) {
            return false;
        }

        if (is_numeric($errno)) {
            return (int) $errno === 0;
        }

        return trim((string) $errno) === '0';
    }

    private function normalizeTimeInput(mixed $value): ?string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $normalized)) {
            return null;
        }

        return $normalized;
    }

    private function normalizePositiveFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = str_replace(',', '.', trim((string) $value));
        if (!is_numeric($normalized)) {
            return null;
        }

        $floatValue = (float) $normalized;

        return $floatValue > 0 ? $floatValue : null;
    }

    private function normalizeTimeCandidate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = $this->extractFirstScalarString($value) ?? '';
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^\d{3,4}$/', $normalized)) {
            $padded = str_pad($normalized, 4, '0', STR_PAD_LEFT);
            $hour = (int) substr($padded, 0, 2);
            $minute = (int) substr($padded, 2, 2);

            if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59) {
                return sprintf('%02d:%02d', $hour, $minute);
            }
        }

        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $normalized, $matches)) {
            return sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
        }

        if (preg_match('/\b([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?\b/', $normalized, $matches)) {
            return sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
        }

        return null;
    }

    private function extractTimeRangeFromString(mixed $value): array
    {
        if ($value === null || is_array($value)) {
            return [null, null];
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return [null, null];
        }

        preg_match_all('/([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?/', $normalized, $matches, PREG_SET_ORDER);
        if (count($matches) < 2) {
            return [null, null];
        }

        $openTime = sprintf('%02d:%02d', (int) $matches[0][1], (int) $matches[0][2]);
        $closeTime = sprintf('%02d:%02d', (int) $matches[1][1], (int) $matches[1][2]);

        return [$openTime, $closeTime];
    }

    private function normalizeDeliveryMethodValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = $this->extractFirstScalarString($value) ?? '';
        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, ['1', '2'], true)) {
            return $normalized;
        }

        $normalizedLower = strtolower($normalized);
        if (
            str_contains($normalizedLower, '99')
            || str_contains($normalizedLower, 'platform')
            || str_contains($normalizedLower, 'didi')
        ) {
            return '1';
        }

        if (
            str_contains($normalizedLower, 'store')
            || str_contains($normalizedLower, 'shop')
            || str_contains($normalizedLower, 'merchant')
            || str_contains($normalizedLower, 'self')
            || str_contains($normalizedLower, 'loja')
        ) {
            return '2';
        }

        return $normalized;
    }

    private function normalizeConfirmMethodValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = $this->extractFirstScalarString($value) ?? '';
        return $normalized === '' ? null : $normalized;
    }

    private function normalizeRadiusValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalizedValue = $this->extractFirstScalarString($value);
        if ($normalizedValue === null) {
            return null;
        }

        $normalized = str_replace(',', '.', trim($normalizedValue));
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        $floatValue = (float) $normalized;
        if ((float) ((int) $floatValue) === $floatValue) {
            return (string) ((int) $floatValue);
        }

        return rtrim(rtrim(number_format($floatValue, 3, '.', ''), '0'), '.');
    }

    private function hasMeaningfulScalar(mixed $value): bool
    {
        if ($value === null || is_array($value)) {
            return false;
        }

        return trim((string) $value) !== '';
    }

    private function mergeStoreSettingsWithFallback(array $remoteSettings, array $fallbackSettings): array
    {
        $keys = [
            'delivery_radius',
            'open_time',
            'close_time',
            'delivery_method',
            'confirm_method',
            'delivery_area_id',
        ];

        $merged = $remoteSettings;
        foreach ($keys as $key) {
            if ($this->hasMeaningfulScalar($merged[$key] ?? null)) {
                continue;
            }

            if ($this->hasMeaningfulScalar($fallbackSettings[$key] ?? null)) {
                $merged[$key] = trim((string) $fallbackSettings[$key]);
            }
        }

        return $merged;
    }

    private function resolveStoreSettingsSource(array $remoteSettings, array $mergedSettings): string
    {
        $keys = [
            'delivery_radius',
            'open_time',
            'close_time',
            'delivery_method',
            'confirm_method',
            'delivery_area_id',
        ];

        $remoteCount = 0;
        $mergedCount = 0;
        foreach ($keys as $key) {
            if ($this->hasMeaningfulScalar($remoteSettings[$key] ?? null)) {
                $remoteCount++;
            }

            if ($this->hasMeaningfulScalar($mergedSettings[$key] ?? null)) {
                $mergedCount++;
            }
        }

        if ($remoteCount > 0 && $remoteCount === $mergedCount) {
            return 'remote';
        }

        if ($remoteCount > 0 && $mergedCount > $remoteCount) {
            return 'mixed';
        }

        if ($remoteCount === 0 && $mergedCount > 0) {
            return 'fallback';
        }

        return 'unavailable';
    }

    private function extractFirstScalarString(mixed $value): ?string
    {
        if (is_array($value)) {
            foreach ($value as $nestedValue) {
                $resolved = $this->extractFirstScalarString($nestedValue);
                if ($resolved !== null) {
                    return $resolved;
                }
            }

            return null;
        }

        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        return $normalized === '' ? null : $normalized;
    }

    private function firstNonEmptyValue(array $source, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }

            $value = $this->extractFirstScalarString($source[$key]);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function findFirstScalarByKeysRecursive(mixed $payload, array $keys): ?string
    {
        if (!is_array($payload)) {
            return null;
        }

        $direct = $this->firstNonEmptyValue($payload, $keys);
        if ($direct !== null) {
            return $direct;
        }

        foreach ($payload as $value) {
            if (!is_array($value)) {
                continue;
            }

            $nested = $this->findFirstScalarByKeysRecursive($value, $keys);
            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }

    private function findFirstAreaCandidate(mixed $payload): ?array
    {
        if (!is_array($payload)) {
            return null;
        }

        $hasAreaId = $this->firstNonEmptyValue($payload, ['area_id', 'delivery_area_id']) !== null;
        $hasAreaShape = $hasAreaId
            || $this->firstNonEmptyValue($payload, ['radius', 'delivery_distance', 'distance', 'range', 'max_distance']) !== null;

        if ($hasAreaShape) {
            return $payload;
        }

        foreach ($payload as $value) {
            if (!is_array($value)) {
                continue;
            }

            $candidate = $this->findFirstAreaCandidate($value);
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveFirstDeliveryArea(array $deliveryAreasResponse): ?array
    {
        $data = is_array($deliveryAreasResponse['data'] ?? null) ? $deliveryAreasResponse['data'] : [];
        $candidate = $this->findFirstAreaCandidate($data);
        if (is_array($candidate)) {
            return $candidate;
        }

        $groups = is_array($data['area_group'] ?? null) ? $data['area_group'] : [];

        foreach ($groups as $group) {
            if (is_array($group)) {
                return $group;
            }
        }

        return null;
    }

    private function normalizeIdentifierValue(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        return $normalized === '' ? null : $normalized;
    }

    private function normalizeIdentifierSet(array $candidateIds): array
    {
        $normalized = [];
        foreach ($candidateIds as $candidateId) {
            $value = $this->normalizeIdentifierValue($candidateId);
            if ($value === null) {
                continue;
            }

            $normalized[$value] = true;
        }

        return array_keys($normalized);
    }

    private function matchesAnyIdentifier(mixed $value, array $candidateIds): bool
    {
        $normalized = $this->normalizeIdentifierValue($value);
        if ($normalized === null) {
            return false;
        }

        return in_array($normalized, $candidateIds, true);
    }

    private function findBoundStoreCandidateInPayload(mixed $payload, array $candidateIds): ?array
    {
        if (!is_array($payload)) {
            return null;
        }

        $shopId = $this->firstNonEmptyValue($payload, ['shop_id', 'shopId', 'id']);
        $appShopId = $this->firstNonEmptyValue($payload, ['app_shop_id', 'appShopId']);

        if (
            ($shopId !== null && $this->matchesAnyIdentifier($shopId, $candidateIds))
            || ($appShopId !== null && $this->matchesAnyIdentifier($appShopId, $candidateIds))
        ) {
            return $payload;
        }

        foreach ($payload as $value) {
            if (!is_array($value)) {
                continue;
            }

            $candidate = $this->findBoundStoreCandidateInPayload($value, $candidateIds);
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveDeliveryAreaId(array $deliveryAreasResponse, array $payload): ?string
    {
        $requested = trim((string) ($payload['delivery_area_id'] ?? ''));
        if ($requested !== '') {
            return $requested;
        }

        $firstArea = $this->resolveFirstDeliveryArea($deliveryAreasResponse);
        if (!is_array($firstArea)) {
            return null;
        }

        foreach (['area_id', 'id', 'delivery_area_id'] as $key) {
            if (!array_key_exists($key, $firstArea) || is_array($firstArea[$key])) {
                continue;
            }

            $value = trim((string) $firstArea[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function extractStoreSettingsSnapshot(array $storeDetails, array $deliveryAreas): array
    {
        $storeData = is_array($storeDetails['data'] ?? null) ? $storeDetails['data'] : [];
        $firstArea = $this->resolveFirstDeliveryArea($deliveryAreas) ?? [];

        $openTime = $this->normalizeTimeCandidate($this->findFirstScalarByKeysRecursive($storeData, [
            'open_time',
            'openTime',
            'start_time',
            'startTime',
            'business_open_time',
            'businessOpenTime',
            'open_at',
            'openAt',
            'opening_time',
            'openingTime',
        ]));
        $closeTime = $this->normalizeTimeCandidate($this->findFirstScalarByKeysRecursive($storeData, [
            'close_time',
            'closeTime',
            'end_time',
            'endTime',
            'business_close_time',
            'businessCloseTime',
            'close_at',
            'closeAt',
            'closing_time',
            'closingTime',
        ]));

        if ($openTime === null || $closeTime === null) {
            [$rangeOpenTime, $rangeCloseTime] = $this->extractTimeRangeFromString(
                $this->findFirstScalarByKeysRecursive($storeData, [
                    'business_hours',
                    'businessHours',
                    'business_time',
                    'businessTime',
                    'opening_hours',
                    'openingHours',
                    'open_close_time',
                    'openCloseTime',
                ])
            );
            $openTime = $openTime ?? $rangeOpenTime;
            $closeTime = $closeTime ?? $rangeCloseTime;
        }

        if (($openTime === null || $closeTime === null) && is_array($storeData['business_hours'] ?? null)) {
            $firstBusinessWindow = $storeData['business_hours'][0] ?? null;
            if (is_array($firstBusinessWindow)) {
                $openTime = $openTime ?? $this->normalizeTimeCandidate(
                    $this->findFirstScalarByKeysRecursive($firstBusinessWindow, [
                        'open_time',
                        'openTime',
                        'start_time',
                        'startTime',
                        'business_open_time',
                        'businessOpenTime',
                    ])
                );
                $closeTime = $closeTime ?? $this->normalizeTimeCandidate(
                    $this->findFirstScalarByKeysRecursive($firstBusinessWindow, [
                        'close_time',
                        'closeTime',
                        'end_time',
                        'endTime',
                        'business_close_time',
                        'businessCloseTime',
                    ])
                );
                if ($openTime === null || $closeTime === null) {
                    [$nestedRangeOpen, $nestedRangeClose] = $this->extractTimeRangeFromString(
                        $this->findFirstScalarByKeysRecursive($firstBusinessWindow, [
                            'business_hours',
                            'businessHours',
                            'business_time',
                            'businessTime',
                            'open_close_time',
                            'openCloseTime',
                        ])
                    );
                    $openTime = $openTime ?? $nestedRangeOpen;
                    $closeTime = $closeTime ?? $nestedRangeClose;
                }
            }
        }

        $deliveryMethod = $this->normalizeDeliveryMethodValue($this->findFirstScalarByKeysRecursive($storeData, [
            'delivery_type',
            'deliveryType',
            'delivery_mode',
            'deliveryMode',
            'delivery_method',
            'deliveryMethod',
            'fulfillment_mode',
            'fulfillmentMode',
        ]));
        $confirmMethod = $this->normalizeConfirmMethodValue($this->findFirstScalarByKeysRecursive($storeData, [
            'confirm_method',
            'confirmMethod',
            'order_confirm_method',
            'orderConfirmMethod',
            'confirmation_method',
            'confirmationMethod',
            'confirm_type',
            'confirmType',
        ]));
        $radius = $this->normalizeRadiusValue($this->findFirstScalarByKeysRecursive($firstArea, [
            'radius',
            'delivery_distance',
            'deliveryDistance',
            'distance',
            'range',
            'max_distance',
            'max_delivery_distance',
            'delivery_radius',
            'deliveryRadius',
        ]));
        if ($radius === null) {
            $radius = $this->normalizeRadiusValue($this->findFirstScalarByKeysRecursive(
                is_array($deliveryAreas['data'] ?? null) ? $deliveryAreas['data'] : [],
                ['radius', 'delivery_distance', 'deliveryDistance', 'distance', 'range', 'max_distance', 'max_delivery_distance', 'delivery_radius', 'deliveryRadius']
            ));
        }

        return [
            'delivery_radius' => $radius,
            'open_time' => $openTime,
            'close_time' => $closeTime,
            'delivery_method' => $deliveryMethod,
            'confirm_method' => $confirmMethod,
            'delivery_area_id' => $this->resolveDeliveryAreaId($deliveryAreas, []),
        ];
    }

    private function buildStoreSettingsDetail(People $provider, ?array $storeDetails = null, ?array $deliveryAreas = null, array $extra = []): array
    {
        $resolvedStoreDetails = is_array($storeDetails) ? $storeDetails : ($this->food99Service->getStoreDetails($provider) ?? []);
        $resolvedDeliveryAreas = is_array($deliveryAreas) ? $deliveryAreas : ($this->food99Service->listDeliveryAreas($provider) ?? []);
        $local = $this->buildLocalIntegrationDetail($provider);
        $remoteSettings = $this->extractStoreSettingsSnapshot(
            is_array($resolvedStoreDetails) ? $resolvedStoreDetails : [],
            is_array($resolvedDeliveryAreas) ? $resolvedDeliveryAreas : []
        );

        $candidateShopIds = $this->normalizeIdentifierSet([
            $local['integration']['food99_code'] ?? null,
            $local['integration']['app_shop_id'] ?? null,
            $provider->getId(),
        ]);

        $boundStoresResponse = $this->food99Service->listAuthorizedStores([]) ?? [];
        $boundStoreCandidate = $this->findBoundStoreCandidateInPayload($boundStoresResponse['data'] ?? $boundStoresResponse, $candidateShopIds);
        $boundStoreMatched = is_array($boundStoreCandidate);
        if (is_array($boundStoreCandidate)) {
            $boundStoreSettings = $this->extractStoreSettingsSnapshot([
                'data' => $boundStoreCandidate,
            ], []);
            $remoteSettings = $this->mergeStoreSettingsWithFallback($remoteSettings, $boundStoreSettings);
        }

        $confirmMethodResponse = $this->food99Service->getStoreOrderConfirmationMethod($provider) ?? [];
        $confirmMethodFromEndpoint = $this->normalizeConfirmMethodValue(
            $this->findFirstScalarByKeysRecursive(
                is_array($confirmMethodResponse['data'] ?? null) ? $confirmMethodResponse['data'] : [],
                ['confirm_method', 'confirmMethod', 'order_confirm_method', 'orderConfirmMethod', 'confirm_type', 'confirmType', 'method']
            )
        );
        if ($confirmMethodFromEndpoint !== null) {
            $remoteSettings = $this->mergeStoreSettingsWithFallback($remoteSettings, [
                'confirm_method' => $confirmMethodFromEndpoint,
            ]);
        }

        $this->food99Service->persistOperationalSettings($provider, $remoteSettings);
        $fallbackSettings = $this->food99Service->getStoredOperationalSettings($provider);
        $settings = $this->mergeStoreSettingsWithFallback($remoteSettings, $fallbackSettings);
        $settingsSource = $this->resolveStoreSettingsSource($remoteSettings, $settings);

        if (!$this->hasMeaningfulScalar($settings['delivery_method'] ?? null)) {
            $deliveryMethodFromOrders = $this->food99Service->getLatestProviderOrderDeliveryType($provider);
            if ($deliveryMethodFromOrders !== null) {
                $settings['delivery_method'] = $deliveryMethodFromOrders;
                $this->food99Service->persistOperationalSettings($provider, [
                    'delivery_method' => $deliveryMethodFromOrders,
                ]);

                if ($settingsSource === 'unavailable') {
                    $settingsSource = 'fallback';
                } elseif ($settingsSource === 'remote') {
                    $settingsSource = 'mixed';
                }
            }
        }

        return array_merge([
            'provider' => [
                'id' => $provider->getId(),
                'name' => method_exists($provider, 'getName') ? $provider->getName() : null,
            ],
            'integration' => $local['integration'],
            'store' => $resolvedStoreDetails,
            'delivery_areas' => $resolvedDeliveryAreas,
            'settings' => $settings,
            'settings_source' => $settingsSource,
            'settings_debug' => [
                'bound_store_lookup_errno' => $boundStoresResponse['errno'] ?? null,
                'bound_store_lookup_msg' => $boundStoresResponse['errmsg'] ?? null,
                'bound_store_matched' => $boundStoreMatched,
                'confirm_method_lookup_errno' => $confirmMethodResponse['errno'] ?? null,
                'confirm_method_lookup_msg' => $confirmMethodResponse['errmsg'] ?? null,
                'confirm_method_found' => $confirmMethodFromEndpoint !== null,
            ],
        ], $extra);
    }

    private function resolvePublishedRemoteItemIds(?array $menuDetails): array
    {
        $items = is_array($menuDetails['data']['items'] ?? null) ? $menuDetails['data']['items'] : [];
        $remoteItemIds = [];

        foreach ($items as $item) {
            if (!is_array($item) || empty($item['app_item_id'])) {
                continue;
            }

            $remoteItemIds[] = (string) $item['app_item_id'];
        }

        return array_values(array_unique($remoteItemIds));
    }

    private function mapProductsWithRemoteCatalog(array $products, array $remoteItemIds): array
    {
        $remoteItemIdSet = array_flip($remoteItemIds);

        return array_map(static function (array $product) use ($remoteItemIdSet) {
            $candidateId = (string) ($product['food99_code'] ?: $product['suggested_app_item_id'] ?: $product['id']);
            $product['published_remotely'] = isset($remoteItemIdSet[$candidateId]);

            return $product;
        }, $products);
    }

    private function buildLocalIntegrationDetail(People $provider): array
    {
        $products = $this->food99Service->listSelectableMenuProducts($provider);
        $storedState = $this->food99Service->getStoredIntegrationState($provider);
        $mappedProducts = array_map(static function (array $product) {
            $product['published_remotely'] = !empty($product['food99_published']);

            return $product;
        }, $products['products'] ?? []);
        $publishedProductCount = count(array_filter(
            $mappedProducts,
            static fn(array $product) => !empty($product['published_remotely'])
        ));

        return [
            'provider' => [
                'id' => $provider->getId(),
                'name' => method_exists($provider, 'getName') ? $provider->getName() : null,
            ],
            'integration' => [
                'key' => '99food',
                'label' => '99Food',
                'minimum_required_items' => 5,
                'eligible_product_count' => $products['eligible_product_count'] ?? 0,
                'connected' => $storedState['connected'],
                'remote_connected' => $storedState['remote_connected'],
                'food99_code' => $storedState['food99_code'],
                'app_shop_id' => $storedState['app_shop_id'],
                'auth_available' => null,
                'online' => $storedState['online'],
                'biz_status' => $storedState['biz_status'],
                'biz_status_label' => $this->resolveBizStatusLabel($storedState['biz_status']),
                'sub_biz_status' => $storedState['sub_biz_status'],
                'sub_biz_status_label' => $this->resolveSubBizStatusLabel($storedState['sub_biz_status']),
                'store_status' => $storedState['store_status'],
                'last_sync_at' => $storedState['last_sync_at'],
                'last_error_code' => $storedState['last_error_code'],
                'last_error_message' => $storedState['last_error_message'],
                'last_menu_task_status' => $storedState['last_menu_task_status'],
                'last_menu_task_message' => $storedState['last_menu_task_message'],
                'last_menu_task_checked_at' => $storedState['last_menu_task_checked_at'],
                'last_menu_publish_state' => $storedState['last_menu_publish_state'],
                'menu_count' => $storedState['menu_count'],
                'menu_item_count' => $storedState['menu_item_count'],
                'delivery_area_count' => $storedState['delivery_area_count'],
                'remote_only_item_count' => $storedState['remote_only_item_count'],
                'published_product_count' => $publishedProductCount,
                'last_menu_task_id' => $storedState['last_menu_task_id'],
                'last_webhook_event_id' => $storedState['last_webhook_event_id'],
                'last_webhook_event_type' => $storedState['last_webhook_event_type'],
                'last_webhook_event_at' => $storedState['last_webhook_event_at'],
                'last_webhook_received_at' => $storedState['last_webhook_received_at'],
                'last_webhook_processed_at' => $storedState['last_webhook_processed_at'],
                'last_webhook_order_id' => $storedState['last_webhook_order_id'],
                'last_webhook_shop_id' => $storedState['last_webhook_shop_id'],
                'last_reconcile_at' => $storedState['last_reconcile_at'],
                'last_reconcile_status' => $storedState['last_reconcile_status'],
                'last_reconcile_message' => $storedState['last_reconcile_message'],
                'last_reconcile_source' => $storedState['last_reconcile_source'],
                'last_reconcile_duration_ms' => $storedState['last_reconcile_duration_ms'],
            ],
            'store' => null,
            'delivery_areas' => null,
            'menu' => [
                'remote_item_ids' => [],
            ],
            'products' => array_merge($products, [
                'products' => $mappedProducts,
                'published_product_count' => $publishedProductCount,
            ]),
            'errors' => [],
        ];
    }

    private function buildOrderIntegrationDetail(Order $order): array
    {
        $storedState = $this->food99Service->getStoredOrderIntegrationState($order);
        $homologationSnapshot = $this->food99Service->getOrderHomologationSnapshot($order);
        $actionCapabilities = $this->resolveOrderActionCapabilities($order, $storedState);
        $hasActionError = $this->hasErrnoError($storedState['last_action_errno'] ?? null);
        $hasConfirmError = $this->hasErrnoError($storedState['confirm_errno'] ?? null);
        $hasReconcileError = $this->hasErrnoError($storedState['reconcile_errno'] ?? null);

        return [
            'order' => [
                'id' => $order->getId(),
                'app' => $order->getApp(),
                'status' => [
                    'id' => $order->getStatus()?->getId(),
                    'status' => $order->getStatus()?->getStatus(),
                    'real_status' => $order->getStatus()?->getRealStatus(),
                ],
            ],
            'integration' => [
                'key' => '99food',
                'food99_id' => $storedState['food99_id'],
                'food99_code' => $storedState['food99_code'],
                'remote_order_state' => $storedState['remote_order_state'],
                'remote_order_state_label' => $this->resolveRemoteOrderStateLabel($storedState['remote_order_state'] ?? null),
                'remote_delivery_status' => $storedState['remote_delivery_status'],
                'last_event_type' => $storedState['last_event_type'],
                'last_event_at' => $storedState['last_event_at'],
                'cancel_code' => $storedState['cancel_code'],
                'cancel_reason' => $storedState['cancel_reason'],
                'last_action' => $storedState['last_action'],
                'last_action_at' => $storedState['last_action_at'],
                'last_action_errno' => $storedState['last_action_errno'],
                'last_action_message' => $storedState['last_action_message'],
                'confirm_at' => $storedState['confirm_at'],
                'confirm_errno' => $storedState['confirm_errno'],
                'confirm_message' => $storedState['confirm_message'],
                'reconcile_at' => $storedState['reconcile_at'],
                'reconcile_errno' => $storedState['reconcile_errno'],
                'reconcile_message' => $storedState['reconcile_message'],
                'reconcile_latency_ms' => $storedState['reconcile_latency_ms'],
            ],
            'delivery' => [
                'delivery_type' => $storedState['delivery_type'],
                'delivery_label' => $storedState['delivery_label'],
                'fulfillment_mode' => $storedState['fulfillment_mode'],
                'expected_arrived_eta' => $storedState['expected_arrived_eta'],
                'remote_delivery_status' => $storedState['remote_delivery_status'],
                'pickup_code' => $storedState['pickup_code'],
                'handover_code' => $storedState['handover_code'],
                'locator' => $storedState['locator'],
                'handover_page_url' => $storedState['handover_page_url'],
                'handover_confirmation_url' => $this->resolveFood99HandoverConfirmationUrl($storedState),
                'virtual_phone_number' => $storedState['virtual_phone_number'],
                'rider_name' => $storedState['rider_name'] ?? null,
                'rider_phone' => $storedState['rider_phone'] ?? null,
                'rider_to_store_eta' => $storedState['rider_to_store_eta'] ?? null,
                'is_store_delivery' => $storedState['is_store_delivery'],
                'is_platform_delivery' => $storedState['is_platform_delivery'],
                'allows_manual_delivery_completion' => $storedState['allows_manual_delivery_completion'],
            ],
            'observability' => [
                'has_action_error' => $hasActionError,
                'has_confirm_error' => $hasConfirmError,
                'has_reconcile_error' => $hasReconcileError,
                'is_healthy' => !$hasActionError && !$hasConfirmError && !$hasReconcileError,
                'remote_state_age_minutes' => $this->resolveAgeInMinutes($storedState['last_event_at'] ?? null),
                'last_action_age_minutes' => $this->resolveAgeInMinutes($storedState['last_action_at'] ?? null),
                'last_confirm_age_minutes' => $this->resolveAgeInMinutes($storedState['confirm_at'] ?? null),
                'last_reconcile_age_minutes' => $this->resolveAgeInMinutes($storedState['reconcile_at'] ?? null),
            ],
            'financial' => $homologationSnapshot['financial'] ?? null,
            'payment' => $homologationSnapshot['payment'] ?? null,
            'customer' => $homologationSnapshot['customer'] ?? null,
            'address' => $homologationSnapshot['address'] ?? null,
            'notes' => $homologationSnapshot['notes'] ?? null,
            'identifiers' => $homologationSnapshot['identifiers'] ?? null,
            'raw_payload_available' => !empty($homologationSnapshot['raw_payload_available']),
            'capabilities' => $actionCapabilities,
        ];
    }

    private function normalizeOrderStateValue(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }

    private function resolveRemoteOrderStateLabel(?string $state): string
    {
        return match ($this->normalizeOrderStateValue($state)) {
            'new' => 'Novo',
            'accepted' => 'Aceito',
            'preparing' => 'Preparando',
            'ready' => 'Pronto',
            'courier_to_store' => 'Entregador a caminho da loja',
            'picked_up' => 'Coletado',
            'arriving' => 'Chegando',
            'delivering' => 'Entregando',
            'delivered', 'finished', 'closed', 'complete', 'completed' => 'Entregue',
            'cancel_requested' => 'Cancelamento solicitado',
            'partial_cancel' => 'Cancelamento parcial',
            'cancelled', 'canceled' => 'Cancelado',
            default => 'Indefinido',
        };
    }

    private function resolveFood99HandoverConfirmationUrl(array $storedState): ?string
    {
        $handoverPageUrl = trim((string) ($storedState['handover_page_url'] ?? ''));
        if ($handoverPageUrl !== '') {
            return $handoverPageUrl;
        }

        $locator = trim((string) ($storedState['locator'] ?? ''));
        if (
            $locator !== ''
            || !empty($storedState['is_store_delivery'])
            || trim((string) ($storedState['pickup_code'] ?? '')) !== ''
            || trim((string) ($storedState['handover_code'] ?? '')) !== ''
        ) {
            return 'https://food-b-h5.99app.com/pt-BR/v2/confirmation-entrega';
        }

        return null;
    }

    private function isTerminalOrderState(string $realStatus, string $remoteState): bool
    {
        if (in_array($realStatus, ['closed', 'cancelled', 'canceled'], true)) {
            return true;
        }

        return in_array($remoteState, ['delivered', 'finished', 'closed', 'complete', 'completed', 'cancelled', 'canceled'], true);
    }

    private function resolveOrderActionCapabilities(Order $order, array $storedState): array
    {
        $realStatus = $this->normalizeOrderStateValue($order->getStatus()?->getRealStatus());
        $remoteState = $this->normalizeOrderStateValue($storedState['remote_order_state'] ?? null);

        $isTerminal = $this->isTerminalOrderState($realStatus, $remoteState);
        $isReadyOrBeyond = in_array($remoteState, ['ready', 'courier_to_store', 'picked_up', 'delivering', 'arriving', 'delivered', 'finished', 'closed', 'complete', 'completed'], true);
        $isDeliveredOrCancelled = in_array($remoteState, ['delivered', 'finished', 'closed', 'complete', 'completed', 'cancelled', 'canceled'], true);
        $isDelivering = in_array($remoteState, ['courier_to_store', 'picked_up', 'delivering', 'arriving'], true);
        $requiresDeliveryLocator = !empty($storedState['is_store_delivery'])
            && !empty($storedState['allows_manual_delivery_completion']);
        $requiresDeliveryCode = $requiresDeliveryLocator;
        $hasHandoverFlow = $this->resolveFood99HandoverConfirmationUrl($storedState) !== null;

        $canCancel = !$isTerminal;
        $canReady = !$isTerminal && !$isReadyOrBeyond;

        if (!empty($storedState['is_platform_delivery']) && $isReadyOrBeyond) {
            $canReady = false;
        }

        $canDelivered = !$isTerminal
            && !$isDeliveredOrCancelled
            && !empty($storedState['allows_manual_delivery_completion']);

        return [
            'can_ready' => $canReady,
            'can_cancel' => $canCancel,
            'can_delivered' => $canDelivered,
            'requires_delivery_locator' => $canDelivered && $requiresDeliveryLocator,
            'delivery_locator_length' => 8,
            'requires_delivery_code' => $canDelivered && $requiresDeliveryCode,
            'delivery_code_length' => $requiresDeliveryCode ? 4 : 0,
            'requires_handover_confirmation' => $canDelivered && $requiresDeliveryLocator,
            'can_open_handover_flow' => $canDelivered && $hasHandoverFlow,
            'is_terminal' => $isTerminal,
            'is_delivering' => $isDelivering,
            'remote_state' => $remoteState,
        ];
    }

    private function hasErrnoError(mixed $errno): bool
    {
        if ($errno === null) {
            return false;
        }

        $normalized = trim((string) $errno);
        if ($normalized === '') {
            return false;
        }

        return $normalized !== '0';
    }

    private function resolveAgeInMinutes(?string $datetime): ?int
    {
        $value = trim((string) $datetime);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        $diff = time() - $timestamp;

        return $diff >= 0 ? (int) floor($diff / 60) : 0;
    }

    #[Route('/marketplace/integrations', name: 'marketplace_integrations', methods: ['GET'])]
    public function listIntegrations(Request $request): JsonResponse
    {
        $provider = $this->resolveProvider($request);
        if (!$provider) {
            return $this->providerErrorResponse();
        }

        $products = $this->food99Service->listSelectableMenuProducts($provider);
        $storedState = $this->food99Service->getStoredIntegrationState($provider);
        $ifoodState = $this->iFoodService->getStoredIntegrationState($provider);
        $publishedProductCount = count(array_filter(
            $products['products'] ?? [],
            static fn(array $product) => !empty($product['food99_published'])
        ));
        $ifoodEligibleProducts = $this->iFoodService->countEligibleProducts($provider);

        return new JsonResponse([
            'provider' => [
                'id' => $provider->getId(),
                'name' => method_exists($provider, 'getName') ? $provider->getName() : null,
            ],
            'items' => [
                [
                    'key' => '99food',
                    'label' => '99Food',
                    'minimum_required_items' => 5,
                    'eligible_product_count' => $products['eligible_product_count'] ?? 0,
                    'connected' => $storedState['connected'],
                    'remote_connected' => $storedState['remote_connected'],
                    'food99_code' => $storedState['food99_code'],
                    'app_shop_id' => $storedState['app_shop_id'],
                    'biz_status' => $storedState['biz_status'],
                    'sub_biz_status' => $storedState['sub_biz_status'],
                    'store_status' => $storedState['store_status'],
                    'online' => $storedState['online'],
                    'last_sync_at' => $storedState['last_sync_at'],
                    'last_menu_task_status' => $storedState['last_menu_task_status'],
                    'last_menu_task_message' => $storedState['last_menu_task_message'],
                    'last_menu_task_checked_at' => $storedState['last_menu_task_checked_at'],
                    'last_menu_publish_state' => $storedState['last_menu_publish_state'],
                    'published_product_count' => $publishedProductCount,
                    'remote_only_item_count' => $storedState['remote_only_item_count'],
                    'last_error_code' => $storedState['last_error_code'],
                    'last_error_message' => $storedState['last_error_message'],
                    'store' => null,
                    'store_error' => null,
                ],
                [
                    'key' => 'ifood',
                    'label' => 'iFood',
                    'minimum_required_items' => 1,
                    'eligible_product_count' => $ifoodEligibleProducts,
                    'connected' => (bool) ($ifoodState['connected'] ?? false),
                    'remote_connected' => (bool) ($ifoodState['remote_connected'] ?? false),
                    'ifood_code' => $ifoodState['ifood_code'] ?? null,
                    'merchant_id' => $ifoodState['merchant_id'] ?? null,
                    'merchant_name' => $ifoodState['merchant_name'] ?? null,
                    'merchant_status' => $ifoodState['merchant_status'] ?? null,
                    'merchant_status_label' => $ifoodState['merchant_status_label'] ?? 'Indefinido',
                    'online' => (bool) ($ifoodState['online'] ?? false),
                    'last_sync_at' => $ifoodState['last_sync_at'] ?? null,
                    'last_error_code' => $ifoodState['last_error_code'] ?? null,
                    'last_error_message' => $ifoodState['last_error_message'] ?? null,
                    'auth_available' => (bool) ($ifoodState['auth_available'] ?? false),
                    'store' => ($ifoodState['merchant_id'] ?? null) ? [
                        'merchant_id' => $ifoodState['merchant_id'],
                        'name' => $ifoodState['merchant_name'] ?? null,
                        'status' => $ifoodState['merchant_status'] ?? null,
                        'status_label' => $ifoodState['merchant_status_label'] ?? 'Indefinido',
                    ] : null,
                    'store_error' => null,
                ],
            ],
        ]);
    }

    #[Route('/marketplace/integrations/99food/store', name: 'marketplace_integrations_food99_store', methods: ['GET'])]
    public function getStore(Request $request): JsonResponse
    {
        $provider = $this->resolveProvider($request);
        if (!$provider) {
            return $this->providerErrorResponse();
        }

        $storeDetails = $this->food99Service->getStoreDetails($provider);
        $deliveryAreas = $this->food99Service->listDeliveryAreas($provider);
        $menuDetails = $this->food99Service->getStoreMenuDetails($provider);

        return new JsonResponse([
            'provider' => [
                'id' => $provider->getId(),
                'name' => method_exists($provider, 'getName') ? $provider->getName() : null,
            ],
            'store' => $storeDetails,
            'delivery_areas' => $deliveryAreas,
            'menu' => $menuDetails,
        ]);
    }

    #[Route('/marketplace/integrations/99food/detail', name: 'marketplace_integrations_food99_detail', methods: ['GET'])]
    public function getIntegrationDetail(Request $request): JsonResponse
    {
        $provider = $this->resolveProvider($request);
        if (!$provider) {
            return $this->providerErrorResponse();
        }

        $refreshRemote = filter_var((string) $request->query->get('refresh_remote', ''), FILTER_VALIDATE_BOOLEAN);
        if ($refreshRemote) {
            $this->food99Service->reconcileProviderState($provider, 'detail');
        }

        return new JsonResponse($this->buildLocalIntegrationDetail($provider));
    }

    #[Route('/marketplace/integrations/99food/sync', name: 'marketplace_integrations_food99_sync', methods: ['POST'])]
    public function syncIntegration(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerErrorResponse();
        }

        $syncResult = $this->food99Service->syncIntegrationState($provider);
        $detail = $this->buildLocalIntegrationDetail($provider);
        $detail['integration']['auth_available'] = $syncResult['auth_available'];
        $detail['errors'] = $syncResult['errors'] ?? [];

        return new JsonResponse($detail);
    }

    #[Route('/marketplace/integrations/99food/reconcile', name: 'marketplace_integrations_food99_reconcile', methods: ['POST'])]
    public function reconcileIntegrationNow(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerErrorResponse();
        }

        $source = trim((string) ($payload['source'] ?? 'manual'));
        if ($source === '') {
            $source = 'manual';
        }

        $reconcile = $this->food99Service->reconcileProviderState($provider, $source);
        $detail = $this->buildLocalIntegrationDetail($provider);

        return new JsonResponse(array_merge($detail, [
            'reconcile' => $reconcile,
        ]));
    }

    #[Route('/marketplace/integrations/99food/store/authorization-page', name: 'marketplace_integrations_food99_authorization_page', methods: ['POST'])]
    public function getAuthorizationPage(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerErrorResponse();
        }

        $payload['app_shop_id'] = (string) ($payload['app_shop_id'] ?? $provider->getId());

        return new JsonResponse($this->food99Service->getAuthorizationPage($payload));
    }

    #[Route('/marketplace/integrations/99food/store/categories', name: 'marketplace_integrations_food99_categories', methods: ['GET'])]
    public function getStoreCategories(Request $request): JsonResponse
    {
        $provider = $this->resolveProvider($request);
        if (!$provider) {
            return $this->providerErrorResponse();
        }

        return new JsonResponse($this->food99Service->getStoreCategories($provider));
    }

    #[Route('/marketplace/integrations/99food/store/status', name: 'marketplace_integrations_food99_status', methods: ['POST'])]
    public function setStoreStatus(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerErrorResponse();
        }

        $bizStatus = isset($payload['biz_status']) ? (int) $payload['biz_status'] : null;
        if (!in_array($bizStatus, [1, 2], true)) {
            return new JsonResponse([
                'error' => 'biz_status must be 1 or 2',
            ], Response::HTTP_BAD_REQUEST);
        }

        $autoSwitch = isset($payload['auto_switch']) ? (int) $payload['auto_switch'] : null;

        return new JsonResponse($this->food99Service->setStoreStatus($provider, $bizStatus, $autoSwitch));
    }

    #[Route('/marketplace/integrations/99food/store/settings', name: 'marketplace_integrations_food99_store_settings', methods: ['GET'])]
    public function getStoreSettings(Request $request): JsonResponse
    {
        $provider = $this->resolveProvider($request);
        if (!$provider) {
            return $this->providerErrorResponse();
        }

        return new JsonResponse($this->buildStoreSettingsDetail($provider));
    }

    #[Route('/marketplace/integrations/99food/store/connect', name: 'marketplace_integrations_food99_store_connect', methods: ['POST'])]
    public function connectStore(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerErrorResponse();
        }

        $payload['app_shop_id'] = (string) ($payload['app_shop_id'] ?? $provider->getId());
        $result = $this->food99Service->bindStore($payload);

        if ($this->isSuccessfulErrno($result['errno'] ?? null)) {
            $shopId = trim((string) ($payload['shop_id'] ?? $payload['food99_code'] ?? $payload['store_code'] ?? ''));
            $this->food99Service->markProviderConnected($provider, $shopId !== '' ? $shopId : null);
        }

        return new JsonResponse($this->buildStoreSettingsDetail($provider, null, null, [
            'action' => 'connect',
            'result' => $result,
        ]));
    }

    #[Route('/marketplace/integrations/99food/store/disconnect', name: 'marketplace_integrations_food99_store_disconnect', methods: ['POST'])]
    public function disconnectStore(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerErrorResponse();
        }

        $result = $this->food99Service->unbindStore($provider, $payload);

        if ($this->isSuccessfulErrno($result['errno'] ?? null)) {
            $this->food99Service->clearProviderBindingState($provider);
        }

        return new JsonResponse($this->buildStoreSettingsDetail($provider, null, null, [
            'action' => 'disconnect',
            'result' => $result,
        ]));
    }

    #[Route('/marketplace/integrations/99food/store/settings', name: 'marketplace_integrations_food99_store_settings_update', methods: ['POST'])]
    public function updateStoreSettings(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerErrorResponse();
        }

        $operations = [];
        $persistedOperationalSettings = [];

        $storeUpdatePayload = is_array($payload['store_update_payload'] ?? null) ? $payload['store_update_payload'] : [];
        $openTime = $this->normalizeTimeInput($payload['open_time'] ?? null);
        $closeTime = $this->normalizeTimeInput($payload['close_time'] ?? null);
        $deliveryMethod = trim((string) ($payload['delivery_method'] ?? ''));

        if (($payload['open_time'] ?? null) !== null && $openTime === null) {
            return new JsonResponse(['error' => 'open_time must be HH:mm'], Response::HTTP_BAD_REQUEST);
        }

        if (($payload['close_time'] ?? null) !== null && $closeTime === null) {
            return new JsonResponse(['error' => 'close_time must be HH:mm'], Response::HTTP_BAD_REQUEST);
        }

        if (($payload['delivery_method'] ?? null) !== null && $deliveryMethod !== '' && !in_array($deliveryMethod, ['1', '2'], true)) {
            return new JsonResponse(['error' => 'delivery_method must be 1 (Entrega 99) or 2 (loja)'], Response::HTTP_BAD_REQUEST);
        }

        if ($openTime !== null) {
            $storeUpdatePayload['open_time'] = $openTime;
        }
        if ($closeTime !== null) {
            $storeUpdatePayload['close_time'] = $closeTime;
        }
        if ($deliveryMethod !== '') {
            $storeUpdatePayload['delivery_type'] = $deliveryMethod;
        }

        if (!empty($storeUpdatePayload)) {
            $operations['store_update'] = $this->food99Service->updateStoreInformation($provider, $storeUpdatePayload);
            if ($this->isSuccessfulErrno($operations['store_update']['errno'] ?? null)) {
                if ($openTime !== null) {
                    $persistedOperationalSettings['open_time'] = $openTime;
                }
                if ($closeTime !== null) {
                    $persistedOperationalSettings['close_time'] = $closeTime;
                }
                if ($deliveryMethod !== '') {
                    $persistedOperationalSettings['delivery_method'] = $deliveryMethod;
                }
            }
        }

        $confirmPayload = is_array($payload['confirm_method_payload'] ?? null) ? $payload['confirm_method_payload'] : [];
        $confirmMethod = trim((string) ($payload['confirm_method'] ?? ''));
        if (($payload['confirm_method'] ?? null) !== null && $confirmMethod !== '' && !preg_match('/^\d{1,3}$/', $confirmMethod)) {
            return new JsonResponse(['error' => 'confirm_method must be numeric'], Response::HTTP_BAD_REQUEST);
        }

        if ($confirmMethod !== '') {
            $confirmPayload['confirm_method'] = $confirmMethod;
        }

        if (!empty($confirmPayload)) {
            $operations['confirm_method_update'] = $this->food99Service->setStoreOrderConfirmationMethod($provider, $confirmPayload);
            if ($this->isSuccessfulErrno($operations['confirm_method_update']['errno'] ?? null) && $confirmMethod !== '') {
                $persistedOperationalSettings['confirm_method'] = $confirmMethod;
            }
        }

        $deliveryAreaPayload = is_array($payload['delivery_area_payload'] ?? null) ? $payload['delivery_area_payload'] : [];
        $deliveryRadiusKm = $this->normalizePositiveFloat($payload['delivery_radius_km'] ?? $payload['delivery_radius'] ?? null);
        if (($payload['delivery_radius_km'] ?? $payload['delivery_radius'] ?? null) !== null && $deliveryRadiusKm === null) {
            return new JsonResponse(['error' => 'delivery_radius_km must be a positive number'], Response::HTTP_BAD_REQUEST);
        }

        if ($deliveryRadiusKm !== null || !empty($deliveryAreaPayload)) {
            $deliveryAreasSnapshot = $this->food99Service->listDeliveryAreas($provider) ?? [];
            $resolvedAreaId = $this->resolveDeliveryAreaId(
                is_array($deliveryAreasSnapshot) ? $deliveryAreasSnapshot : [],
                $payload
            );

            if ($resolvedAreaId === null && !isset($deliveryAreaPayload['area_id'])) {
                return new JsonResponse([
                    'error' => 'delivery_area_id is required when no delivery area is available',
                ], Response::HTTP_BAD_REQUEST);
            }

            if ($resolvedAreaId !== null && !isset($deliveryAreaPayload['area_id'])) {
                $deliveryAreaPayload['area_id'] = $resolvedAreaId;
            }

            if ($deliveryRadiusKm !== null) {
                $deliveryAreaPayload['radius'] = $deliveryRadiusKm;
                $deliveryAreaPayload['delivery_distance'] = $deliveryRadiusKm;
            }

            $operations['delivery_area_update'] = $this->food99Service->updateDeliveryArea($provider, $deliveryAreaPayload);
            if ($this->isSuccessfulErrno($operations['delivery_area_update']['errno'] ?? null)) {
                if ($deliveryRadiusKm !== null) {
                    $persistedOperationalSettings['delivery_radius'] = (string) $deliveryRadiusKm;
                }

                $updatedAreaId = trim((string) ($deliveryAreaPayload['area_id'] ?? ''));
                if ($updatedAreaId !== '') {
                    $persistedOperationalSettings['delivery_area_id'] = $updatedAreaId;
                }
            }
        }

        if (empty($operations)) {
            return new JsonResponse([
                'error' => 'No settings to update',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!empty($persistedOperationalSettings)) {
            $this->food99Service->persistOperationalSettings($provider, $persistedOperationalSettings);
        }

        return new JsonResponse($this->buildStoreSettingsDetail($provider, null, null, [
            'action' => 'update_settings',
            'operations' => $operations,
        ]));
    }

    #[Route('/marketplace/integrations/99food/products', name: 'marketplace_integrations_food99_products', methods: ['GET'])]
    public function listProducts(Request $request): JsonResponse
    {
        $provider = $this->resolveProvider($request);
        if (!$provider) {
            return $this->providerErrorResponse();
        }

        return new JsonResponse($this->food99Service->listSelectableMenuProducts($provider));
    }

    #[Route('/marketplace/integrations/99food/menu/preview', name: 'marketplace_integrations_food99_menu_preview', methods: ['POST'])]
    public function previewMenuUpload(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerErrorResponse();
        }

        $preview = $this->food99Service->buildStoreMenuPayloadFromProducts($provider, $this->extractProductIds($payload));
        $statusCode = empty($preview['errors']) ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY;

        return new JsonResponse($preview, $statusCode);
    }

    #[Route('/marketplace/integrations/99food/menu/upload', name: 'marketplace_integrations_food99_menu_upload', methods: ['POST'])]
    public function uploadMenu(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerErrorResponse();
        }

        try {
            $productIds = $this->extractProductIds($payload);
            $preview = $this->food99Service->buildStoreMenuPayloadFromProducts($provider, $productIds);
            if (!empty($preview['errors'])) {
                return new JsonResponse($preview, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $this->food99Service->ensureMenuProductCodes($provider, $productIds);
            $result = $this->food99Service->uploadStoreMenu($provider, $preview['payload']);
            $detail = $this->buildLocalIntegrationDetail($provider);

            return new JsonResponse([
                'provider_id' => $provider->getId(),
                'selected_product_count' => $preview['selected_product_count'] ?? 0,
                'eligible_product_count' => $preview['eligible_product_count'] ?? 0,
                'result' => $result,
                'payload' => $preview['payload'],
                'integration' => $detail['integration'],
                'products' => $detail['products'],
            ]);
        } catch (\Throwable $e) {
            self::$logger->error('Food99 menu upload endpoint error', [
                'provider_id' => $provider->getId(),
                'product_ids' => $this->extractProductIds($payload),
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'provider_id' => $provider->getId(),
                'selected_product_count' => 0,
                'eligible_product_count' => 0,
                'result' => [
                    'errno' => 10001,
                    'errmsg' => 'Nao foi possivel publicar o menu no 99Food.',
                    'data' => [],
                ],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/marketplace/integrations/99food/menu/task/{taskId}', name: 'marketplace_integrations_food99_menu_task', methods: ['GET'])]
    public function getMenuTask(Request $request, string $taskId): JsonResponse
    {
        $provider = $this->resolveProvider($request);
        if (!$provider) {
            return $this->providerErrorResponse();
        }

        $taskResponse = $this->food99Service->getMenuUploadTaskInfo($provider, $taskId);
        $detail = $this->buildLocalIntegrationDetail($provider);

        return new JsonResponse(array_merge(
            is_array($taskResponse) ? $taskResponse : [],
            [
                'publish_state' => $detail['integration']['last_menu_publish_state'],
                'task_message' => $detail['integration']['last_menu_task_message'],
                'task_status' => $detail['integration']['last_menu_task_status'],
                'task_checked_at' => $detail['integration']['last_menu_task_checked_at'],
                'integration' => $detail['integration'],
                'products' => $detail['products'],
            ]
        ));
    }

    #[Route('/marketplace/integrations/99food/orders/{orderId}/state', name: 'marketplace_integrations_food99_order_state', methods: ['GET'])]
    public function getOrderState(string $orderId): JsonResponse
    {
        $order = $this->resolveOrder($orderId);
        if (!$order) {
            return $this->orderErrorResponse();
        }

        if (!$this->isFood99Order($order)) {
            return new JsonResponse([
                'error' => 'Order is not linked to Food99',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse($this->buildOrderIntegrationDetail($order));
    }

    #[Route('/marketplace/integrations/99food/orders/{orderId}/cancel-reasons', name: 'marketplace_integrations_food99_order_cancel_reasons', methods: ['GET'])]
    public function getCancelReasonsAction(string $orderId): JsonResponse
    {
        $order = $this->resolveOrder($orderId);
        if (!$order) {
            return $this->orderErrorResponse();
        }

        if (!$this->isFood99Order($order)) {
            return new JsonResponse(['error' => 'Order is not linked to Food99'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse([
            'action' => 'cancel_reasons',
            'result' => $this->food99Service->getOrderCancelReasons($order),
            'state' => $this->buildOrderIntegrationDetail($order),
        ]);
    }

    #[Route('/marketplace/integrations/99food/orders/{orderId}/delivery-locator/verify', name: 'marketplace_integrations_food99_order_delivery_locator_verify', methods: ['POST'])]
    public function verifyDeliveredLocatorAction(string $orderId, Request $request): JsonResponse
    {
        $order = $this->resolveOrder($orderId);
        if (!$order) {
            return $this->orderErrorResponse();
        }

        if (!$this->isFood99Order($order)) {
            return new JsonResponse(['error' => 'Order is not linked to Food99'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $locator = trim((string) ($payload['locator'] ?? ''));
        $verification = $this->food99Service->performDeliveredLocatorVerification(
            $order,
            $locator !== '' ? $locator : null
        );

        return new JsonResponse([
            'action' => 'locator_verify',
            'result' => $verification['result'] ?? [],
            'flow' => $verification['flow'] ?? [],
            'state' => $this->buildOrderIntegrationDetail($order),
        ]);
    }

    #[Route('/marketplace/integrations/99food/orders/{orderId}/reconcile', name: 'marketplace_integrations_food99_order_reconcile', methods: ['POST'])]
    public function reconcileOrderAction(string $orderId): JsonResponse
    {
        $order = $this->resolveOrder($orderId);
        if (!$order) {
            return $this->orderErrorResponse();
        }

        if (!$this->isFood99Order($order)) {
            return new JsonResponse(['error' => 'Order is not linked to Food99'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $this->food99Service->reconcileOrder($order);

        return new JsonResponse([
            'action' => 'reconcile',
            'result' => $result,
            'state' => $this->buildOrderIntegrationDetail($order),
        ]);
    }

    #[Route('/marketplace/integrations/99food/orders/{orderId}/verify', name: 'marketplace_integrations_food99_order_verify', methods: ['POST'])]
    public function verifyOrderAction(string $orderId, Request $request): JsonResponse
    {
        $order = $this->resolveOrder($orderId);
        if (!$order) {
            return $this->orderErrorResponse();
        }

        if (!$this->isFood99Order($order)) {
            return new JsonResponse(['error' => 'Order is not linked to Food99'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->food99Service->performVerifyAction($order, $payload);

        return new JsonResponse([
            'action' => 'verify',
            'result' => $result,
            'state' => $this->buildOrderIntegrationDetail($order),
        ]);
    }

    #[Route('/marketplace/integrations/99food/orders/{orderId}/pay-confirm', name: 'marketplace_integrations_food99_order_pay_confirm', methods: ['POST'])]
    public function payConfirmOrderAction(string $orderId): JsonResponse
    {
        $order = $this->resolveOrder($orderId);
        if (!$order) {
            return $this->orderErrorResponse();
        }

        if (!$this->isFood99Order($order)) {
            return new JsonResponse(['error' => 'Order is not linked to Food99'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $this->food99Service->performCashPaymentConfirmAction($order);

        return new JsonResponse([
            'action' => 'pay_confirm',
            'result' => $result,
            'state' => $this->buildOrderIntegrationDetail($order),
        ]);
    }

    #[Route('/marketplace/integrations/99food/delivery-areas', name: 'marketplace_integrations_food99_delivery_areas', methods: ['GET'])]
    public function getDeliveryAreas(Request $request): JsonResponse
    {
        $provider = $this->resolveProvider($request);
        if (!$provider) {
            return $this->providerErrorResponse();
        }

        return new JsonResponse($this->food99Service->listDeliveryAreas($provider));
    }
}
