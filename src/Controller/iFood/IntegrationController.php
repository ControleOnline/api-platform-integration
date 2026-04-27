<?php

namespace ControleOnline\Controller\iFood;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Service\iFoodService;
use ControleOnline\Service\RequestPayloadService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface as Security;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;

#[SecurityAttribute("is_granted('ROLE_ADMIN') or is_granted('ROLE_CLIENT')")]
class IntegrationController extends AbstractController
{
    private const IFOOD_SELF_DELIVERY_CONFIRMATION_URL = 'https://confirmacao-entrega-propria.ifood.com.br/';

    public function __construct(
        private EntityManagerInterface $manager,
        private Security $security,
        private iFoodService $iFoodService,
        private RequestPayloadService $requestPayloadService,
    ) {}

    private function normalizeString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (!is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
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

    private function isAdminUser(): bool
    {
        $user = $this->security->getToken()?->getUser();
        $roles = is_object($user) && method_exists($user, 'getRoles') ? (array) $user->getRoles() : [];

        return in_array('ROLE_ADMIN', $roles, true);
    }

    private function parseJsonBody(Request $request): array
    {
        $content = trim((string) $request->getContent());
        if ($content === '') {
            return [];
        }

        return $this->requestPayloadService->decodeJsonContent($content);
    }

    private function decodeArrayValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return [];
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return [];
        }

        try {
            return $this->requestPayloadService->decodeJsonContent($normalized);
        } catch (\InvalidArgumentException) {
            return [];
        }
    }

    private function resolvePayloadSources(array $otherInformations): array
    {
        $sources = [];
        if ($otherInformations) {
            $sources[] = $otherInformations;
        }

        foreach (['iFood', 'ifood', 'IFOOD'] as $contextKey) {
            $contextPayload = $this->decodeArrayValue($otherInformations[$contextKey] ?? null);
            if ($contextPayload) {
                $sources[] = $contextPayload;
            }
        }

        return $sources;
    }

    private function resolveOrderPayload(array $payload): array
    {
        $orderPayload = $this->decodeArrayValue($payload['order'] ?? null);
        if ($orderPayload) {
            return $orderPayload;
        }

        if (
            isset($payload['displayId'])
            || isset($payload['delivery'])
            || isset($payload['items'])
            || isset($payload['payments'])
            || isset($payload['total'])
        ) {
            return $payload;
        }

        return [];
    }

    private function canAccessProvider(People $provider): bool
    {
        if ($this->isAdminUser()) {
            return true;
        }

        $userPeople = $this->getAuthenticatedPeople();
        if (!$userPeople) {
            return false;
        }

        if ($userPeople->getId() === $provider->getId()) {
            return true;
        }

        $sql = <<<SQL
            SELECT COUNT(1)
            FROM people_link
            WHERE company_id = :companyId
              AND people_id = :peopleId
              AND enable = 1
        SQL;

        $count = (int) $this->manager->getConnection()->fetchOne($sql, [
            'companyId' => $provider->getId(),
            'peopleId' => $userPeople->getId(),
        ]);

        return $count > 0;
    }

    private function resolveProvider(Request $request, array $payload = []): ?People
    {
        $providerId = $payload['provider_id']
            ?? $payload['company_id']
            ?? $request->query->get('provider_id')
            ?? $request->query->get('company_id');

        $userPeople = $this->getAuthenticatedPeople();

        if (!$providerId) {
            if ($userPeople && $this->canAccessProvider($userPeople)) {
                return $userPeople;
            }
            return null;
        }

        $providerId = $this->requestPayloadService->normalizeOptionalNumericId($providerId);
        if (!$providerId) {
            return null;
        }

        $provider = $this->manager->getRepository(People::class)->find($providerId);
        if (!$provider instanceof People) {
            return null;
        }

        return $this->canAccessProvider($provider) ? $provider : null;
    }

    private function canAccessOrder(Order $order): bool
    {
        $provider = $order->getProvider();
        if (!$provider instanceof People) {
            return false;
        }

        return $this->canAccessProvider($provider);
    }

    private function resolveOrder(string|int $orderId): ?Order
    {
        $id = $this->requestPayloadService->normalizeOptionalNumericId($orderId);
        if (!$id) {
            return null;
        }

        $order = $this->manager->getRepository(Order::class)->find($id);
        if (!$order instanceof Order) {
            return null;
        }

        if (!$this->canAccessOrder($order)) {
            return null;
        }

        return $order;
    }

    private function isIfoodOrder(Order $order): bool
    {
        return $this->normalizeString($order->getApp()) !== ''
            && strtolower($this->normalizeString($order->getApp())) === 'ifood';
    }

    private function orderNotFound(): JsonResponse
    {
        return new JsonResponse(
            ['error' => 'Pedido nao encontrado ou acesso negado'],
            Response::HTTP_FORBIDDEN
        );
    }

    private function providerNotFound(): JsonResponse
    {
        return new JsonResponse(
            ['error' => 'Provider nao encontrado ou acesso negado'],
            Response::HTTP_FORBIDDEN
        );
    }

    private function hasErrnoError(mixed $value): bool
    {
        $normalized = $this->normalizeString($value);
        if ($normalized === '') {
            return false;
        }

        return $normalized !== '0';
    }

    private function resolveAgeInMinutes(mixed $dateTimeValue): ?int
    {
        $normalized = $this->normalizeString($dateTimeValue);
        if ($normalized === '') {
            return null;
        }

        try {
            $dateTime = new DateTimeImmutable($normalized);
        } catch (\Throwable) {
            return null;
        }

        $now = new DateTimeImmutable('now');
        $seconds = $now->getTimestamp() - $dateTime->getTimestamp();
        if ($seconds < 0) {
            return 0;
        }

        return (int) floor($seconds / 60);
    }

    private function resolveRemoteOrderStateLabel(?string $state): string
    {
        return match (strtolower($this->normalizeString($state))) {
            'new', 'placed' => 'Novo',
            'confirmed' => 'Confirmado',
            'preparing', 'started' => 'Preparando',
            'ready' => 'Pronto',
            'dispatching', 'dispatched' => 'Em entrega',
            'delivery_drop_code_requested', 'delivery_drop_code_validating' => 'Pronto',
            'concluded', 'closed' => 'Concluido',
            'cancelled', 'canceled' => 'Cancelado',
            'cancellation_requested' => 'Cancelamento solicitado',
            'handshake_dispute' => 'Negociacao solicitada',
            'handshake_settlement' => 'Negociacao finalizada',
            default => 'Indefinido',
        };
    }

    private function decodeOrderOtherInformations(Order $order): array
    {
        $raw = $order->getOtherInformations(true);
        if (is_array($raw)) {
            return $raw;
        }

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        try {
            return $this->requestPayloadService->decodeJsonContent($raw);
        } catch (\InvalidArgumentException) {
            return [];
        }
    }

    private function payloadHasOrderData(array $payload): bool
    {
        $orderData = $this->resolveOrderPayload($payload);
        if (!$orderData) {
            return false;
        }

        if (!empty($orderData['displayId'])) {
            return true;
        }

        if (!empty($orderData['delivery']) || !empty($orderData['items']) || !empty($orderData['payments'])) {
            return true;
        }

        return !empty($orderData['total']);
    }

    private function resolveLatestOrderPayload(Order $order): array
    {
        $otherInformations = $this->decodeOrderOtherInformations($order);
        if (!$otherInformations) {
            return [];
        }

        $firstEventPayload = [];

        foreach ($this->resolvePayloadSources($otherInformations) as $source) {
            $latestEventType = $this->normalizeString(
                $source['latest_event_type']
                    ?? $otherInformations['latest_event_type']
                    ?? null
            );
            if ($latestEventType !== '') {
                $latestPayload = $this->decodeArrayValue($source[$latestEventType] ?? null);
                if ($latestPayload && $this->payloadHasOrderData($latestPayload)) {
                    return $latestPayload;
                }
            }

            foreach ($source as $value) {
                $eventPayload = $this->decodeArrayValue($value);
                if (!$eventPayload || !isset($eventPayload['orderId'])) {
                    continue;
                }

                if ($firstEventPayload === []) {
                    $firstEventPayload = $eventPayload;
                }

                if ($this->payloadHasOrderData($eventPayload)) {
                    return $eventPayload;
                }
            }
        }

        return $firstEventPayload;
    }

    private function resolveDeliveryContext(Order $order, array $payload, array $storedState): array
    {
        $delivery = is_array($payload['order']['delivery'] ?? null) ? $payload['order']['delivery'] : [];
        $orderPayload = is_array($payload['order'] ?? null) ? $payload['order'] : [];
        $orderType = strtoupper($this->normalizeString(
            $orderPayload['orderType']
                ?? $storedState['order_type']
                ?? null
        ));
        $isTakeout = $orderType === 'TAKEOUT';
        $isDineIn = in_array($orderType, ['DINE_IN', 'INDOOR'], true);
        $isDelivery = !$isTakeout && !$isDineIn;
        $deliveredBy = strtoupper($this->normalizeString(
            $delivery['deliveredBy']
                ?? $storedState['delivered_by']
                ?? null
        ));
        $deliveryMode = strtolower($this->normalizeString(
            $delivery['mode']
                ?? $delivery['deliveryMode']
                ?? $storedState['delivery_mode']
                ?? null
        ));

        $isStoreDelivery = $isDelivery && (
            $deliveredBy === 'MERCHANT'
            || in_array($deliveryMode, ['merchant', 'store', 'self', 'self_delivery', 'own', 'own_fleet'], true)
        );
        $isPlatformDelivery = $isDelivery && (
            $deliveredBy === 'IFOOD'
            || in_array($deliveryMode, ['ifood', 'platform', 'marketplace'], true)
        );

        $deliveryLabel = null;
        if ($isDelivery) {
            $deliveryLabel = 'Entrega indefinida';
            if ($isStoreDelivery) {
                $deliveryLabel = 'Entrega da loja';
            } elseif ($isPlatformDelivery) {
                $deliveryLabel = 'Entrega iFood';
            }
        }

        $orderTypeLabel = match ($orderType) {
            'DELIVERY' => 'Entrega',
            'TAKEOUT' => 'Retirada',
            'DINE_IN', 'INDOOR' => 'Consumir no local',
            default => $deliveryLabel ?: 'Indefinido',
        };

        return [
            'delivery_label' => $deliveryLabel,
            'fulfillment_label' => $orderTypeLabel,
            'order_type' => $orderType !== '' ? $orderType : null,
            'order_type_label' => $orderTypeLabel,
            'is_delivery' => $isDelivery,
            'is_takeout' => $isTakeout,
            'is_dine_in' => $isDineIn,
            'is_store_delivery' => $isStoreDelivery,
            'is_platform_delivery' => $isPlatformDelivery,
            'delivered_by' => $deliveredBy !== '' ? $deliveredBy : null,
            'delivery_mode' => $deliveryMode !== '' ? $deliveryMode : null,
        ];
    }

    private function preferredText(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $normalized = $this->normalizeString($value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function normalizePickupCode(mixed $value, mixed $displayId = null): ?string
    {
        $normalized = $this->normalizeString($value);
        if ($normalized === '') {
            return null;
        }

        return $normalized === $this->normalizeString($displayId) ? null : $normalized;
    }

    private function preferredBool(mixed ...$values): ?bool
    {
        foreach ($values as $value) {
            if (is_bool($value)) {
                return $value;
            }

            if (is_int($value) || is_float($value)) {
                return (int) $value === 1;
            }

            $normalized = strtolower($this->normalizeString($value));
            if ($normalized === '') {
                continue;
            }

            $parsed = match ($normalized) {
                '1', 'true', 'yes', 'y', 'sim' => true,
                '0', 'false', 'no', 'n', 'nao', 'não' => false,
                default => null,
            };

            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    private function extractAddressFromOrderEntity(Order $order): array
    {
        $address = method_exists($order, 'getAddressDestination') ? $order->getAddressDestination() : null;
        if (!is_object($address)) {
            return [];
        }

        $read = function (array $methods) use ($address): ?string {
            foreach ($methods as $method) {
                if (!method_exists($address, $method)) {
                    continue;
                }

                $value = $this->normalizeString($address->{$method}());
                if ($value !== '') {
                    return $value;
                }
            }

            return null;
        };

        return [
            'display' => $this->preferredText(
                $read(['getDisplay', 'getFormattedAddress']),
                $read(['getAddress']),
            ),
            'street_name' => $read(['getStreetName', 'getStreet', 'getAddress']),
            'street_number' => $read(['getStreetNumber', 'getNumber']),
            'district' => $read(['getDistrict', 'getNeighborhood']),
            'city' => $read(['getCity']),
            'state' => $read(['getState']),
            'postal_code' => $read(['getPostalCode', 'getZipCode', 'getZip']),
            'reference' => $read(['getReference']),
            'complement' => $read(['getComplement']),
            'poi_address' => $read(['getFormattedAddress', 'getAddress']),
        ];
    }

    private function resolveTakeoutModeLabel(?string $mode): ?string
    {
        $normalized = strtoupper($this->normalizeString($mode));
        if ($normalized === '') {
            return null;
        }

        return match ($normalized) {
            'DEFAULT' => 'Balcão',
            'DRIVE_THRU', 'DRIVE_THRU_PICKUP' => 'Drive-thru',
            'CURBSIDE', 'CURBSIDE_PICKUP' => 'Retirada na vaga',
            default => ucfirst(strtolower(str_replace('_', ' ', $normalized))),
        };
    }

    private function buildFulfillmentDetail(array $deliveryContext): array
    {
        return [
            'order_type' => $deliveryContext['order_type'] ?? null,
            'order_type_label' => $deliveryContext['order_type_label'] ?? null,
            'fulfillment_label' => $deliveryContext['fulfillment_label'] ?? null,
            'is_delivery' => !empty($deliveryContext['is_delivery']),
            'is_takeout' => !empty($deliveryContext['is_takeout']),
            'is_dine_in' => !empty($deliveryContext['is_dine_in']),
        ];
    }

    private function buildTakeoutDetail(array $payload, array $storedState, array $deliveryContext): array
    {
        $orderPayload = $this->resolveOrderPayload($payload);
        $takeout = is_array($orderPayload['takeout'] ?? null) ? $orderPayload['takeout'] : [];
        $delivery = is_array($orderPayload['delivery'] ?? null) ? $orderPayload['delivery'] : [];
        $pickup = is_array($orderPayload['pickup'] ?? null) ? $orderPayload['pickup'] : [];
        $displayId = $this->normalizeString($orderPayload['displayId'] ?? null);
        $mode = $this->preferredText(
            $takeout['mode'] ?? null,
            $storedState['takeout_mode'] ?? null
        );
        $pickupAreaCode = $this->preferredText(
            $pickup['area']['code'] ?? null,
            $pickup['areaCode'] ?? null,
            $takeout['pickupArea']['code'] ?? null,
            $takeout['pickupAreaCode'] ?? null,
            $storedState['pickup_area_code'] ?? null
        );
        $pickupAreaType = $this->preferredText(
            $pickup['area']['type'] ?? null,
            $pickup['areaType'] ?? null,
            $takeout['pickupArea']['type'] ?? null,
            $takeout['pickupAreaType'] ?? null,
            $storedState['pickup_area_type'] ?? null
        );

        return [
            'is_takeout' => !empty($deliveryContext['is_takeout']),
            'mode' => $mode,
            'mode_label' => $this->resolveTakeoutModeLabel($mode),
            'takeout_date_time' => $this->preferredText(
                $takeout['takeoutDateTime'] ?? null,
                $takeout['pickupDateTime'] ?? null,
                $storedState['takeout_date_time'] ?? null
            ),
            'pickup_code' => $this->preferredText(
                $delivery['pickupCode'] ?? null,
                $delivery['pickup_code'] ?? null,
                $this->normalizePickupCode($storedState['pickup_code'] ?? null, $displayId)
            ),
            'pickup_area_code' => $pickupAreaCode,
            'pickup_area_type' => $pickupAreaType,
            'pickup_area_type_label' => $this->resolveTakeoutModeLabel($pickupAreaType),
        ];
    }

    private function buildDineInDetail(array $payload, array $storedState, array $deliveryContext): array
    {
        $orderPayload = $this->resolveOrderPayload($payload);
        $dineIn = is_array($orderPayload['dineIn'] ?? null) ? $orderPayload['dineIn'] : [];

        return [
            'is_dine_in' => !empty($deliveryContext['is_dine_in']),
            'delivery_date_time' => $this->preferredText(
                $dineIn['deliveryDateTime'] ?? null,
                $dineIn['dineInDateTime'] ?? null,
                $storedState['dine_in_date_time'] ?? null
            ),
        ];
    }

    private function buildAddressDetail(Order $order, array $payload, array $storedState, array $deliveryContext = []): array
    {
        if (!($deliveryContext['is_delivery'] ?? true)) {
            return [
                'display' => null,
                'street_name' => null,
                'street_number' => null,
                'district' => null,
                'city' => null,
                'state' => null,
                'postal_code' => null,
                'reference' => null,
                'complement' => null,
                'poi_address' => null,
            ];
        }

        $orderPayload = is_array($payload['order'] ?? null) ? $payload['order'] : [];
        $delivery = is_array($orderPayload['delivery'] ?? null) ? $orderPayload['delivery'] : [];
        $deliveryAddress = is_array($delivery['deliveryAddress'] ?? null) ? $delivery['deliveryAddress'] : [];
        $entityAddress = $this->extractAddressFromOrderEntity($order);

        $streetName = $this->preferredText(
            $deliveryAddress['streetName'] ?? null,
            $storedState['address_street_name'] ?? null,
            $entityAddress['street_name'] ?? null
        );
        $streetNumber = $this->preferredText(
            $deliveryAddress['streetNumber'] ?? null,
            $storedState['address_street_number'] ?? null,
            $entityAddress['street_number'] ?? null
        );
        $district = $this->preferredText(
            $deliveryAddress['neighborhood'] ?? null,
            $storedState['address_district'] ?? null,
            $entityAddress['district'] ?? null
        );
        $city = $this->preferredText(
            $deliveryAddress['city'] ?? null,
            $storedState['address_city'] ?? null,
            $entityAddress['city'] ?? null
        );
        $state = $this->preferredText(
            $deliveryAddress['state'] ?? null,
            $storedState['address_state'] ?? null,
            $entityAddress['state'] ?? null
        );

        $display = $this->preferredText(
            $deliveryAddress['formattedAddress'] ?? null,
            $storedState['address_display'] ?? null,
            $entityAddress['display'] ?? null
        );

        if ($display === null) {
            $addressPieces = array_filter([$streetName, $streetNumber], fn($value) => $value !== null && $value !== '');
            $neighborhoodPieces = array_filter([$district, $city], fn($value) => $value !== null && $value !== '');
            $display = trim(implode(', ', array_filter([
                implode(', ', $addressPieces),
                implode(' - ', $neighborhoodPieces),
            ], fn($value) => $value !== '')));
            $display = $display !== '' ? $display : null;
        }

        return [
            'display' => $display,
            'street_name' => $streetName,
            'street_number' => $streetNumber,
            'district' => $district,
            'city' => $city,
            'state' => $state,
            'postal_code' => $this->preferredText(
                $deliveryAddress['postalCode'] ?? null,
                $storedState['address_postal_code'] ?? null,
                $entityAddress['postal_code'] ?? null
            ),
            'reference' => $this->preferredText(
                $deliveryAddress['reference'] ?? null,
                $storedState['address_reference'] ?? null,
                $entityAddress['reference'] ?? null
            ),
            'complement' => $this->preferredText(
                $deliveryAddress['complement'] ?? null,
                $storedState['address_complement'] ?? null,
                $entityAddress['complement'] ?? null
            ),
            'poi_address' => $this->preferredText(
                $deliveryAddress['formattedAddress'] ?? null,
                $storedState['address_poi_address'] ?? null,
                $entityAddress['poi_address'] ?? null
            ),
        ];
    }

    private function buildCustomerDetail(array $payload, array $storedState, Order $order): array
    {
        $orderPayload = is_array($payload['order'] ?? null) ? $payload['order'] : [];
        $customer = is_array($orderPayload['customer'] ?? null) ? $orderPayload['customer'] : [];
        $phone = is_array($customer['phone'] ?? null) ? $customer['phone'] : [];
        $client = $order->getClient();
        $clientName = is_object($client) && method_exists($client, 'getName')
            ? $this->normalizeString($client->getName())
            : null;

        $name = $this->preferredText(
            $customer['name'] ?? null,
            $storedState['customer_name'] ?? null,
            $clientName
        );

        $customerPhone = $this->preferredText(
            $phone['number'] ?? null,
            $storedState['customer_phone'] ?? null
        );

        $documentNumber = $this->preferredText(
            $customer['documentNumber'] ?? null,
            $customer['document_number'] ?? null,
            $storedState['customer_document'] ?? null
        );
        $documentType = $this->preferredText(
            $customer['documentType'] ?? null,
            $customer['document_type'] ?? null,
            $storedState['customer_document_type'] ?? null
        );
        $taxDocumentRequested = $this->preferredBool(
            $customer['taxDocumentRequested'] ?? null,
            $customer['tax_document_requested'] ?? null,
            $customer['requiresTaxDocument'] ?? null,
            $customer['requires_tax_document'] ?? null,
            $storedState['tax_document_requested'] ?? null
        );
        if ($taxDocumentRequested === null) {
            $taxDocumentRequested = $documentNumber !== null;
        }

        return [
            'name'                 => $name,
            'phone'                => $customerPhone,
            'document_number'      => $documentNumber,
            'document_type'        => $documentType,
            'tax_document_requested' => $taxDocumentRequested,
        ];
    }

    private function normalizeMerchantStatusLabel(string $status): string
    {
        return match (strtoupper($status)) {
            'AVAILABLE', 'ONLINE', 'OPEN' => 'Online',
            'UNAVAILABLE', 'OFFLINE', 'CLOSED', 'INACTIVE' => 'Offline',
            default => 'Indefinido',
        };
    }

    private function buildMerchantStoreDetail(?array $detail, ?array $selectedStore = null): ?array
    {
        if (!is_array($detail) || !$detail) {
            return null;
        }

        $address = is_array($detail['address'] ?? null) ? $detail['address'] : [];
        $operations = array_values(array_filter(array_map(function ($operation): ?array {
            if (!is_array($operation)) {
                return null;
            }

            $salesChannels = array_values(array_filter(array_map(function ($channel): ?string {
                if (is_array($channel)) {
                    return $this->normalizeString(
                        $channel['displayName'] ?? $channel['name'] ?? $channel['salesChannel'] ?? $channel['value'] ?? null
                    );
                }

                return $this->normalizeString($channel);
            }, is_array($operation['salesChannels'] ?? null) ? $operation['salesChannels'] : [])));

            return [
                'name' => $this->normalizeString($operation['name'] ?? $operation['operation'] ?? null),
                'sales_channels' => $salesChannels,
            ];
        }, is_array($detail['operations'] ?? null) ? $detail['operations'] : [])));

        $formattedAddress = implode(', ', array_values(array_filter([
            $this->normalizeString($address['streetName'] ?? $address['street'] ?? null),
            $this->normalizeString($address['streetNumber'] ?? $address['number'] ?? null),
            $this->normalizeString($address['district'] ?? $address['neighborhood'] ?? null),
            $this->normalizeString($address['city'] ?? null),
            $this->normalizeString($address['state'] ?? null),
        ], static fn ($value) => $value !== '')));

        $status = $this->normalizeString(
            $detail['status'] ?? $detail['merchantStatus'] ?? $selectedStore['status'] ?? null
        );

        return [
            'merchant_id' => $this->normalizeString(
                $detail['id'] ?? $detail['merchantId'] ?? $selectedStore['merchant_id'] ?? null
            ),
            'name' => $this->normalizeString($detail['name'] ?? $selectedStore['name'] ?? null),
            'corporate_name' => $this->normalizeString($detail['corporateName'] ?? null),
            'description' => $this->normalizeString($detail['description'] ?? null),
            'type' => $this->normalizeString($detail['merchantType'] ?? $detail['type'] ?? null),
            'timezone' => $this->normalizeString($detail['timezone'] ?? null),
            'status' => $status,
            'status_label' => $this->normalizeMerchantStatusLabel($status),
            'average_ticket' => $detail['averageTicket'] ?? null,
            'address' => [
                'street' => $this->normalizeString($address['streetName'] ?? $address['street'] ?? null),
                'number' => $this->normalizeString($address['streetNumber'] ?? $address['number'] ?? null),
                'complement' => $this->normalizeString($address['complement'] ?? null),
                'district' => $this->normalizeString($address['district'] ?? $address['neighborhood'] ?? null),
                'city' => $this->normalizeString($address['city'] ?? null),
                'state' => $this->normalizeString($address['state'] ?? null),
                'postal_code' => $this->normalizeString($address['postalCode'] ?? $address['zipCode'] ?? null),
                'country' => $this->normalizeString($address['country'] ?? null),
                'formatted' => $formattedAddress,
            ],
            'operations' => $operations,
        ];
    }

    private function buildProviderIntegrationDetail(People $provider, bool $refreshRemote = false): array
    {
        $syncResult = null;
        if ($refreshRemote) {
            $syncResult = $this->iFoodService->syncIntegrationState($provider);
        }

        $storesResponse = $this->iFoodService->listMerchants();
        $rawStores = is_array($storesResponse['data']['merchants'] ?? null)
            ? $storesResponse['data']['merchants']
            : [];
        $stores = array_map(function (array $store): array {
            $status = strtoupper((string) ($store['status'] ?? ''));
            $store['status_label'] = $this->normalizeMerchantStatusLabel($status);
            return $store;
        }, $rawStores);

        $integrationState = $this->iFoodService->getStoredIntegrationState($provider, true);
        $merchantId = $this->normalizeString($integrationState['merchant_id'] ?? null);

        $selectedStore = null;
        if ($merchantId !== '') {
            foreach ($stores as $store) {
                if ($this->normalizeString($store['merchant_id'] ?? null) === $merchantId) {
                    $selectedStore = $store;
                    break;
                }
            }
        }

        $selectedStoreDetailResponse = $merchantId !== ''
            ? $this->iFoodService->getMerchantDetail($provider)
            : ['errno' => 0, 'errmsg' => 'ok', 'data' => null];
        $selectedStoreDetail = $this->buildMerchantStoreDetail(
            is_array($selectedStoreDetailResponse['data'] ?? null) ? $selectedStoreDetailResponse['data'] : null,
            $selectedStore
        );

        return [
            'provider' => [
                'id' => $provider->getId(),
                'name' => method_exists($provider, 'getName') ? $provider->getName() : null,
            ],
            'integration' => [
                'key' => 'ifood',
                'label' => 'iFood',
                'minimum_required_items' => 1,
                'eligible_product_count' => $this->iFoodService->countEligibleProducts($provider),
                'connected' => (bool) ($integrationState['connected'] ?? false),
                'remote_connected' => (bool) ($integrationState['remote_connected'] ?? false),
                'ifood_code' => $integrationState['ifood_code'] ?? null,
                'merchant_id' => $integrationState['merchant_id'] ?? null,
                'merchant_name' => $integrationState['merchant_name'] ?? null,
                'merchant_status' => $integrationState['merchant_status'] ?? null,
                'merchant_status_label' => $integrationState['merchant_status_label'] ?? 'Indefinido',
                'online' => (bool) ($integrationState['online'] ?? false),
                'auth_available' => (bool) ($integrationState['auth_available'] ?? false),
                'connected_at' => $integrationState['connected_at'] ?? null,
                'last_sync_at' => $integrationState['last_sync_at'] ?? null,
                'last_error_code' => $integrationState['last_error_code'] ?? null,
                'last_error_message' => $integrationState['last_error_message'] ?? null,
            ],
            'stores' => [
                'errno' => $storesResponse['errno'] ?? 1,
                'errmsg' => $storesResponse['errmsg'] ?? 'Falha ao listar lojas',
                'items' => $stores,
                'total' => count($stores),
            ],
            'selected_store' => $selectedStore,
            'selected_store_detail' => $selectedStoreDetail,
            'selected_store_detail_error' => (int) ($selectedStoreDetailResponse['errno'] ?? 0) === 0
                ? null
                : [
                    'errno' => $selectedStoreDetailResponse['errno'] ?? 1,
                    'errmsg' => $selectedStoreDetailResponse['errmsg'] ?? 'Falha ao obter detalhes da loja',
                ],
            'store_error' => (int) ($storesResponse['errno'] ?? 1) === 0 ? null : [
                'errno' => $storesResponse['errno'] ?? 1,
                'errmsg' => $storesResponse['errmsg'] ?? 'Falha ao listar lojas',
            ],
            'sync' => $syncResult,
        ];
    }

    private function buildOrderIntegrationDetail(Order $order): array
    {
        $storedState = $this->iFoodService->getStoredOrderIntegrationState($order);
        $payload = $this->resolveLatestOrderPayload($order);
        $orderPayload = $this->resolveOrderPayload($payload);
        $deliveryContext = $this->resolveDeliveryContext($order, $payload, $storedState);
        $remoteState = $this->normalizeString($storedState['remote_order_state'] ?? null);
        $capabilities = $this->resolveOrderActionCapabilities($order, $storedState, $deliveryContext, $payload);
        $orderComments = method_exists($order, 'getComments')
            ? $this->normalizeString($order->getComments())
            : '';

        $orderIndex = $this->normalizeString(
            $orderPayload['displayId']
                ?? $payload['displayId']
                ?? $storedState['ifood_code']
                ?? null
        );

        $remark = $this->normalizeString(
            $orderPayload['additionalInfo']['notes']
                ?? $orderPayload['delivery']['observations']
                ?? $orderPayload['orderComment']
                ?? $storedState['remark']
                ?? $orderComments
                ?? null
        );

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
                'key' => 'ifood',
                'ifood_id' => $storedState['ifood_id'] ?? null,
                'ifood_code' => $storedState['ifood_code'] ?? null,
                'merchant_id' => $storedState['merchant_id'] ?? null,
                'order_type' => $deliveryContext['order_type'] ?? null,
                'remote_order_state' => $remoteState,
                'remote_order_state_label' => $this->resolveRemoteOrderStateLabel($remoteState),
                'cancellation_requested' => $remoteState === 'cancellation_requested',
                'last_event_type' => $storedState['last_event_type'] ?? null,
                'last_event_at' => $storedState['last_event_at'] ?? null,
                'last_action' => $storedState['last_action'] ?? null,
                'last_action_at' => $storedState['last_action_at'] ?? null,
                'last_action_errno' => $storedState['last_action_errno'] ?? null,
                'last_action_message' => $storedState['last_action_message'] ?? null,
                'cancel_reason' => $storedState['cancel_reason'] ?? null,
                'webhook_event_id' => $storedState['webhook_event_id'] ?? null,
                'webhook_event_type' => $storedState['webhook_event_type'] ?? null,
                'webhook_event_at' => $storedState['webhook_event_at'] ?? null,
                'webhook_received_at' => $storedState['webhook_received_at'] ?? null,
                'webhook_processed_at' => $storedState['webhook_processed_at'] ?? null,
                'last_integration_id' => $storedState['last_integration_id'] ?? null,
            ],
            'customer' => $this->buildCustomerDetail($payload, $storedState, $order),
            'address' => $this->buildAddressDetail($order, $payload, $storedState, $deliveryContext),
            'fulfillment' => $this->buildFulfillmentDetail($deliveryContext),
            'takeout' => $this->buildTakeoutDetail($payload, $storedState, $deliveryContext),
            'dine_in' => $this->buildDineInDetail($payload, $storedState, $deliveryContext),
            'delivery'      => $this->buildDeliveryDetail($payload, $storedState, $remoteState, $deliveryContext, $capabilities),
            'payment'       => $this->buildPaymentDetail($payload, $storedState),
            'financial'     => $this->buildFinancialDetail($payload, $storedState),
            'negotiation'   => [
                'has_open_dispute' => $remoteState === 'handshake_dispute',
                'event_type' => $storedState['handshake_event_type'] ?? null,
                'dispute_id' => $storedState['handshake_dispute_id'] ?? null,
                'action' => $storedState['handshake_action'] ?? null,
                'type' => $storedState['handshake_type'] ?? null,
                'group' => $storedState['handshake_group'] ?? null,
                'message' => $storedState['handshake_message'] ?? null,
                'expires_at' => $storedState['handshake_expires_at'] ?? null,
                'timeout_action' => $storedState['handshake_timeout_action'] ?? null,
                'alternative' => [
                    'available' => $this->normalizeString($storedState['handshake_alternative_type'] ?? null) !== '',
                    'type' => $storedState['handshake_alternative_type'] ?? null,
                    'amount_value' => $storedState['handshake_alternative_amount_value'] ?? null,
                    'amount_currency' => $storedState['handshake_alternative_amount_currency'] ?? null,
                    'time_minutes' => $storedState['handshake_alternative_time_minutes'] ?? null,
                    'reason' => $storedState['handshake_alternative_reason'] ?? null,
                ],
                'settlement_status' => $storedState['handshake_settlement_status'] ?? null,
                'settlement_reason' => $storedState['handshake_settlement_reason'] ?? null,
            ],
            'observability' => [
                'has_action_error' => $this->hasErrnoError($storedState['last_action_errno'] ?? null),
                'is_healthy' => !$this->hasErrnoError($storedState['last_action_errno'] ?? null),
                'remote_state_age_minutes' => $this->resolveAgeInMinutes($storedState['last_event_at'] ?? null),
                'last_action_age_minutes' => $this->resolveAgeInMinutes($storedState['last_action_at'] ?? null),
            ],
            'identifiers' => [
                'order_index' => $orderIndex,
            ],
            'notes' => [
                'remark'          => $remark,
                'item_remarks'    => $this->extractItemRemarks($payload),
            ],
            'capabilities' => $capabilities,
            'scheduling'   => $this->buildSchedulingDetail($orderPayload, $storedState),
        ];
    }

    private function normalizeOrderStateValue(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }

    private function isTerminalOrderState(string $realStatus, string $remoteState): bool
    {
        if (in_array($realStatus, ['closed', 'cancelled', 'canceled'], true)) {
            return true;
        }

        return in_array($remoteState, ['concluded', 'closed', 'cancelled', 'canceled'], true);
    }

    private function resolveOrderActionCapabilities(Order $order, array $storedState, array $deliveryContext, array $payload): array
    {
        $realStatus = $this->normalizeOrderStateValue($order->getStatus()?->getRealStatus());
        $statusName = $this->normalizeOrderStateValue($order->getStatus()?->getStatus());
        $remoteState = $this->normalizeOrderStateValue($storedState['remote_order_state'] ?? null);

        $orderPayload = $this->resolveOrderPayload($payload);
        $delivery = is_array($orderPayload['delivery'] ?? null) ? $orderPayload['delivery'] : [];
        $deliveryAddress = is_array($delivery['deliveryAddress'] ?? null) ? $delivery['deliveryAddress'] : [];
        $customer = is_array($orderPayload['customer'] ?? null) ? $orderPayload['customer'] : [];
        $customerPhone = is_array($customer['phone'] ?? null) ? $customer['phone'] : [];
        $displayId = $this->normalizeString($orderPayload['displayId'] ?? null);

        $locator = $this->preferredText(
            $delivery['locator'] ?? null,
            $delivery['localizer'] ?? null,
            $deliveryAddress['locator'] ?? null,
            $deliveryAddress['localizer'] ?? null,
            $customerPhone['localizer'] ?? null,
            $storedState['locator'] ?? null,
        ) ?? '';
        $pickupCode = $this->preferredText(
            $delivery['pickupCode'] ?? null,
            $delivery['pickup_code'] ?? null,
            $this->normalizePickupCode($storedState['pickup_code'] ?? null, $displayId),
        ) ?? '';
        $handoverUrl = $this->preferredText(
            $delivery['handoverConfirmationUrl'] ?? null,
            $delivery['handover_confirmation_url'] ?? null,
            $delivery['handoverPageUrl'] ?? null,
            $delivery['handover_page_url'] ?? null,
            $deliveryAddress['handoverConfirmationUrl'] ?? null,
            $deliveryAddress['handover_confirmation_url'] ?? null,
            $deliveryAddress['handoverPageUrl'] ?? null,
            $deliveryAddress['handover_page_url'] ?? null,
            $storedState['handover_confirmation_url'] ?? null,
            $storedState['handover_page_url'] ?? null,
        ) ?? '';

        $isTerminal = $this->isTerminalOrderState($realStatus, $remoteState);
        $isPreparing = ($realStatus === 'open' && $statusName === 'preparing')
            || in_array($remoteState, ['confirmed', 'preparing'], true);
        $isReadyOrBeyond = ($realStatus === 'pending' && in_array($statusName, ['ready', 'way'], true))
            || in_array($remoteState, [
                'ready',
                'dispatching',
                'dispatched',
                'order_dispatched',
                'order_picked_up',
                'order_in_transit',
                'delivery_started',
                'delivery_collected',
                'delivery_arrived_at_destination',
                'concluded',
            ], true);
        $isDelivering = ($realStatus === 'pending' && $statusName === 'way')
            || in_array($remoteState, [
                'dispatching',
                'dispatched',
                'order_dispatched',
                'order_picked_up',
                'order_in_transit',
                'delivery_started',
                'delivery_collected',
                'delivery_arrived_at_destination',
            ], true);

        $isDeliveryFlow = !empty($deliveryContext['is_delivery']);
        $isPickupFlow = !empty($deliveryContext['is_takeout']) || !empty($deliveryContext['is_dine_in']);
        $isStoreDelivery = $isDeliveryFlow && !empty($deliveryContext['is_store_delivery']);
        $hasHandoverFlow = $isDeliveryFlow && $isStoreDelivery && ($handoverUrl !== '' || $locator !== '' || $pickupCode !== '');
        $isStoreDeliveryReady = $isStoreDelivery && (
            ($realStatus === 'pending' && in_array($statusName, ['ready', 'way'], true))
            || in_array($remoteState, ['ready', 'dispatching', 'dispatched', 'order_dispatched'], true)
        );
        $canCancel = !$isTerminal;
        $canReady = !$isTerminal && $isPreparing && !$isReadyOrBeyond;
        $canDelivered = !$isTerminal && $isDeliveryFlow && $isStoreDelivery && ($isDelivering || $isStoreDeliveryReady);

        return [
            'can_ready' => $canReady,
            'can_cancel' => $canCancel,
            'can_delivered' => $canDelivered,
            'requires_delivery_locator' => $canDelivered && $isStoreDelivery,
            'delivery_locator_length' => $isStoreDelivery ? 8 : 0,
            'requires_delivery_code' => false,
            'delivery_code_length' => 0,
            'requires_handover_confirmation' => $canDelivered && $hasHandoverFlow,
            'can_open_handover_flow' => $canDelivered && $hasHandoverFlow,
            'is_terminal' => $isTerminal,
            'is_delivering' => $isDelivering,
            'is_pickup' => $isPickupFlow,
            'is_dine_in' => !empty($deliveryContext['is_dine_in']),
            'uses_ready_to_pickup' => $isPickupFlow,
            'remote_state' => $remoteState,
        ];
    }

    private function buildSchedulingDetail(array $orderPayload, array $storedState = []): array
    {
        $orderTiming = strtoupper($this->preferredText(
            $storedState['order_timing'] ?? null,
            $orderPayload['orderTiming'] ?? null
        ) ?? '');
        $schedule = is_array($orderPayload['schedule'] ?? null)
            ? $orderPayload['schedule']
            : (is_array($orderPayload['scheduled'] ?? null) ? $orderPayload['scheduled'] : []);

        $start = $this->preferredText(
            $storedState['scheduled_start'] ?? null,
            $schedule['deliveryDateTimeStart']
                ?? $schedule['scheduledDateTimeStart']
                ?? null
        );
        $end = $this->preferredText(
            $storedState['scheduled_end'] ?? null,
            $schedule['deliveryDateTimeEnd']
                ?? $schedule['scheduledDateTimeEnd']
                ?? null
        );
        $deliveryDateTime = $this->preferredText(
            $storedState['delivery_date_time'] ?? null,
            $orderPayload['delivery']['deliveryDateTime']
                ?? $orderPayload['delivery']['estimatedDeliveryDate']
                ?? null
        );
        $preparationStart = $this->preferredText(
            $storedState['preparation_start'] ?? null,
            $orderPayload['preparationStartDateTime']
                ?? $schedule['preparationStartDateTime']
                ?? null
        );
        $isScheduled = $this->preferredBool($storedState['is_scheduled'] ?? null);
        if ($isScheduled === null) {
            $isScheduled = $orderTiming === 'SCHEDULED';
        }

        return [
            'order_timing'             => $orderTiming !== '' ? $orderTiming : null,
            'is_scheduled'             => $isScheduled,
            'scheduled_start'          => $start !== '' ? $start : null,
            'scheduled_end'            => $end !== '' ? $end : null,
            'delivery_date_time'       => $deliveryDateTime !== '' ? $deliveryDateTime : null,
            'preparation_start'        => $preparationStart !== '' ? $preparationStart : null,
        ];
    }

    #[Route('/marketplace/integrations/ifood/detail', name: 'marketplace_integrations_ifood_detail', methods: ['GET'])]
    public function getIntegrationDetail(Request $request): JsonResponse
    {
        $provider = $this->resolveProvider($request);
        if (!$provider) {
            return $this->providerNotFound();
        }

        $refreshRemote = filter_var((string) $request->query->get('refresh_remote', ''), FILTER_VALIDATE_BOOLEAN);

        return new JsonResponse($this->buildProviderIntegrationDetail($provider, $refreshRemote));
    }

    #[Route('/marketplace/integrations/ifood/stores', name: 'marketplace_integrations_ifood_stores', methods: ['GET'])]
    public function getStores(Request $request): JsonResponse
    {
        $provider = $this->resolveProvider($request);
        if (!$provider) {
            return $this->providerNotFound();
        }

        $storesResponse = $this->iFoodService->listMerchants();
        $stores = array_map(function (array $store): array {
            $status = strtoupper((string) ($store['status'] ?? ''));
            $store['status_label'] = match ($status) {
                'AVAILABLE', 'ONLINE', 'OPEN' => 'Online',
                'UNAVAILABLE', 'OFFLINE', 'CLOSED', 'INACTIVE' => 'Offline',
                default => 'Indefinido',
            };
            return $store;
        }, is_array($storesResponse['data']['merchants'] ?? null) ? $storesResponse['data']['merchants'] : []);

        return new JsonResponse([
            'provider' => [
                'id' => $provider->getId(),
                'name' => method_exists($provider, 'getName') ? $provider->getName() : null,
            ],
            'stores' => [
                'errno' => $storesResponse['errno'] ?? 1,
                'errmsg' => $storesResponse['errmsg'] ?? 'Falha ao listar lojas',
                'items' => $stores,
                'total' => count($stores),
            ],
            'integration' => $this->iFoodService->getStoredIntegrationState($provider, true),
        ]);
    }

    #[Route('/marketplace/integrations/ifood/store/connect', name: 'marketplace_integrations_ifood_store_connect', methods: ['POST'])]
    public function connectStore(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerNotFound();
        }

        $merchantId = $this->normalizeString(
            $payload['merchant_id']
                ?? $payload['ifood_code']
                ?? $payload['store_id']
                ?? null
        );

        if ($merchantId === '') {
            return new JsonResponse([
                'error' => 'merchant_id obrigatorio',
            ], Response::HTTP_BAD_REQUEST);
        }

        $storesResponse = $this->iFoodService->listMerchants();
        $storePayload = null;
        foreach ((array) ($storesResponse['data']['merchants'] ?? []) as $store) {
            if ($this->normalizeString($store['merchant_id'] ?? null) === $merchantId) {
                $storePayload = $store;
                break;
            }
        }

        $result = $this->iFoodService->connectStore($provider, $merchantId, $storePayload);

        return new JsonResponse(array_merge(
            $this->buildProviderIntegrationDetail($provider, true),
            ['action' => 'connect', 'result' => $result]
        ));
    }

    #[Route('/marketplace/integrations/ifood/store/disconnect', name: 'marketplace_integrations_ifood_store_disconnect', methods: ['POST'])]
    public function disconnectStore(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerNotFound();
        }

        $result = $this->iFoodService->disconnectStore($provider);

        return new JsonResponse(array_merge(
            $this->buildProviderIntegrationDetail($provider, false),
            ['action' => 'disconnect', 'result' => $result]
        ));
    }

    #[Route('/marketplace/integrations/ifood/sync', name: 'marketplace_integrations_ifood_sync', methods: ['POST'])]
    public function syncIntegrationState(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerNotFound();
        }

        $sync = $this->iFoodService->syncIntegrationState($provider);

        return new JsonResponse(array_merge(
            $this->buildProviderIntegrationDetail($provider, false),
            ['action' => 'sync', 'result' => $sync]
        ));
    }

    #[Route('/marketplace/integrations/ifood/store/status', name: 'marketplace_integrations_ifood_store_status', methods: ['GET'])]
    public function getStoreStatus(Request $request): JsonResponse
    {
        $provider = $this->resolveProvider($request, []);
        if (!$provider) {
            return $this->providerNotFound();
        }

        $result = $this->iFoodService->getStoreStatus($provider);

        return new JsonResponse(array_merge(
            $this->buildProviderIntegrationDetail($provider, false),
            ['action' => 'store_status', 'result' => $result]
        ));
    }

    #[Route('/marketplace/integrations/ifood/store/open', name: 'marketplace_integrations_ifood_store_open', methods: ['POST'])]
    public function openStore(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerNotFound();
        }

        $openResult = $this->iFoodService->openStore($provider);
        // Retorna o status real do iFood apos a operacao para evitar estado otimista incorreto
        $result = ($openResult['errno'] === 0)
            ? $this->iFoodService->getStoreStatus($provider)
            : $openResult;

        return new JsonResponse(array_merge(
            $this->buildProviderIntegrationDetail($provider, false),
            ['action' => 'store_open', 'result' => $result]
        ));
    }

    #[Route('/marketplace/integrations/ifood/store/close', name: 'marketplace_integrations_ifood_store_close', methods: ['POST'])]
    public function closeStore(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerNotFound();
        }

        $closeResult = $this->iFoodService->closeStore($provider);
        $result = $closeResult;
        if (($closeResult['errno'] ?? 1) === 0) {
            $statusResult = $this->iFoodService->getStoreStatus($provider);
            if (($statusResult['errno'] ?? 1) === 0) {
                $statusData = is_array($statusResult['data'] ?? null) ? $statusResult['data'] : [];
                $closeData = is_array($closeResult['data'] ?? null) ? $closeResult['data'] : [];
                $createdInterruption = is_array($closeData['interruption'] ?? null)
                    ? $closeData['interruption']
                    : null;

                if ($createdInterruption !== null) {
                    $createdInterruptionId = $this->normalizeString($createdInterruption['id'] ?? null);
                    $interruptions = is_array($statusData['interruptions'] ?? null)
                        ? $statusData['interruptions']
                        : [];

                    $alreadyPresent = false;
                    if ($createdInterruptionId !== '') {
                        foreach ($interruptions as $interruption) {
                            if ($this->normalizeString($interruption['id'] ?? null) === $createdInterruptionId) {
                                $alreadyPresent = true;
                                break;
                            }
                        }
                    }

                    if (!$alreadyPresent) {
                        $interruptions[] = $createdInterruption;
                    }

                    $statusData['interruptions'] = $interruptions;
                }

                if (($closeData['online'] ?? null) === false) {
                    $statusData['online'] = false;
                }

                $statusResult['data'] = $statusData;
                $result = $statusResult;
            }
        }

        return new JsonResponse(array_merge(
            $this->buildProviderIntegrationDetail($provider, false),
            ['action' => 'store_close', 'result' => $result]
        ));
    }

    #[Route('/marketplace/integrations/ifood/menu/products', name: 'marketplace_integrations_ifood_menu_products', methods: ['GET'])]
    public function getMenuProducts(Request $request): JsonResponse
    {
        $provider = $this->resolveProvider($request, []);
        if (!$provider) {
            return $this->providerNotFound();
        }

        try {
            $products = $this->iFoodService->listSelectableMenuProducts($provider);
        } catch (\Throwable $e) {
            $products = ['products' => [], 'eligible_product_count' => 0, 'minimum_required_items' => 1];
        }

        return new JsonResponse(array_merge(
            $this->buildProviderIntegrationDetail($provider, false),
            ['products' => $products]
        ));
    }

    #[Route('/marketplace/integrations/ifood/menu/upload', name: 'marketplace_integrations_ifood_menu_upload', methods: ['POST'])]
    public function uploadMenu(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerNotFound();
        }

        $rawIds     = $payload['product_ids'] ?? $payload['products'] ?? [];
        $productIds = is_array($rawIds) ? array_values(array_filter(array_map('intval', $rawIds))) : [];

        try {
            $result = $this->iFoodService->publishMenu($provider, $productIds);
        } catch (\Throwable $e) {
            $result = ['errno' => 1, 'errmsg' => 'Falha ao iniciar upload de cardapio iFood.'];
        }

        /* recarrega lista de produtos com status atualizado */
        try {
            $products = $this->iFoodService->listSelectableMenuProducts($provider);
        } catch (\Throwable $e) {
            $products = null;
        }

        return new JsonResponse(array_merge(
            $this->buildProviderIntegrationDetail($provider, false),
            ['action' => 'menu_upload', 'result' => $result, 'products' => $products]
        ));
    }

    #[Route('/marketplace/integrations/ifood/menu/sync', name: 'marketplace_integrations_ifood_menu_sync', methods: ['POST'])]
    public function syncMenuFromIfood(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerNotFound();
        }

        try {
            $result = $this->iFoodService->syncCatalogFromIfood($provider);
        } catch (\Throwable $e) {
            $result = ['errno' => 1, 'errmsg' => 'Falha ao sincronizar catalogo do iFood.'];
        }

        try {
            $products = $this->iFoodService->listSelectableMenuProducts($provider);
        } catch (\Throwable $e) {
            $products = null;
        }

        return new JsonResponse(array_merge(
            $this->buildProviderIntegrationDetail($provider, false),
            ['action' => 'menu_sync', 'result' => $result, 'products' => $products]
        ));
    }

    #[Route('/marketplace/integrations/ifood/menu/item/price', name: 'marketplace_integrations_ifood_menu_item_price', methods: ['PATCH'])]
    public function updateMenuItemPrice(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerNotFound();
        }

        $itemId = $this->normalizeString($payload['item_id'] ?? null);
        $price  = isset($payload['price']) ? (float) $payload['price'] : 0.0;

        if ($itemId === '') {
            return new JsonResponse(['error' => 'item_id obrigatorio'], Response::HTTP_BAD_REQUEST);
        }
        if ($price <= 0) {
            return new JsonResponse(['error' => 'price deve ser maior que zero'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->iFoodService->updateItemPrice($provider, $itemId, $price);
        } catch (\Throwable $e) {
            $result = ['errno' => 1, 'errmsg' => 'Falha ao atualizar preco no iFood.'];
        }

        return new JsonResponse(['action' => 'item_price_update', 'result' => $result]);
    }

    #[Route('/marketplace/integrations/ifood/menu/item/status', name: 'marketplace_integrations_ifood_menu_item_status', methods: ['PATCH'])]
    public function updateMenuItemStatus(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerNotFound();
        }

        $itemId = $this->normalizeString($payload['item_id'] ?? null);
        $status = $this->normalizeString($payload['status'] ?? null);

        if ($itemId === '') {
            return new JsonResponse(['error' => 'item_id obrigatorio'], Response::HTTP_BAD_REQUEST);
        }
        if (!in_array(strtoupper($status), ['AVAILABLE', 'UNAVAILABLE'], true)) {
            return new JsonResponse(['error' => 'status deve ser AVAILABLE ou UNAVAILABLE'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->iFoodService->updateItemStatus($provider, $itemId, $status);
        } catch (\Throwable $e) {
            $result = ['errno' => 1, 'errmsg' => 'Falha ao atualizar status no iFood.'];
        }

        return new JsonResponse(['action' => 'item_status_update', 'result' => $result]);
    }

    #[Route('/marketplace/integrations/ifood/store/hours', name: 'marketplace_integrations_ifood_store_hours_get', methods: ['GET'])]
    public function getStoreHours(Request $request): JsonResponse
    {
        $provider = $this->resolveProvider($request);
        if (!$provider) {
            return $this->providerNotFound();
        }

        try {
            $result = $this->iFoodService->getOpeningHours($provider);
        } catch (\Throwable $e) {
            $result = ['errno' => 1, 'errmsg' => 'Falha ao buscar horarios no iFood.'];
        }

        return new JsonResponse(['action' => 'store_hours_get', 'result' => $result]);
    }

    #[Route('/marketplace/integrations/ifood/store/hours', name: 'marketplace_integrations_ifood_store_hours_put', methods: ['PUT'])]
    public function updateStoreHours(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerNotFound();
        }

        if (!array_key_exists('shifts', $payload)) {
            return new JsonResponse(['error' => 'shifts obrigatorio'], Response::HTTP_BAD_REQUEST);
        }
        $shifts = $this->decodeArrayValue($payload['shifts'] ?? []) ?? [];

        try {
            $result = $this->iFoodService->updateOpeningHours($provider, $shifts);
        } catch (\Throwable $e) {
            $result = ['errno' => 1, 'errmsg' => 'Falha ao atualizar horarios no iFood.'];
        }

        return new JsonResponse(['action' => 'store_hours_update', 'result' => $result]);
    }

    #[Route('/marketplace/integrations/ifood/menu/option/price', name: 'marketplace_integrations_ifood_menu_option_price', methods: ['PATCH'])]
    public function updateMenuOptionPrice(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerNotFound();
        }

        $optionId = $this->normalizeString($payload['option_id'] ?? null);
        $price    = isset($payload['price']) ? (float) $payload['price'] : 0.0;

        if ($optionId === '') {
            return new JsonResponse(['error' => 'option_id obrigatorio'], Response::HTTP_BAD_REQUEST);
        }
        if ($price <= 0) {
            return new JsonResponse(['error' => 'price deve ser maior que zero'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->iFoodService->updateOptionPrice($provider, $optionId, $price);
        } catch (\Throwable $e) {
            $result = ['errno' => 1, 'errmsg' => 'Falha ao atualizar preco do complemento no iFood.'];
        }

        return new JsonResponse(['action' => 'option_price_update', 'result' => $result]);
    }

    #[Route('/marketplace/integrations/ifood/menu/option/status', name: 'marketplace_integrations_ifood_menu_option_status', methods: ['PATCH'])]
    public function updateMenuOptionStatus(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerNotFound();
        }

        $optionId = $this->normalizeString($payload['option_id'] ?? null);
        $status   = $this->normalizeString($payload['status'] ?? null);

        if ($optionId === '') {
            return new JsonResponse(['error' => 'option_id obrigatorio'], Response::HTTP_BAD_REQUEST);
        }
        if (!in_array(strtoupper($status), ['AVAILABLE', 'UNAVAILABLE'], true)) {
            return new JsonResponse(['error' => 'status deve ser AVAILABLE ou UNAVAILABLE'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->iFoodService->updateOptionStatus($provider, $optionId, $status);
        } catch (\Throwable $e) {
            $result = ['errno' => 1, 'errmsg' => 'Falha ao atualizar status do complemento no iFood.'];
        }

        return new JsonResponse(['action' => 'option_status_update', 'result' => $result]);
    }

    #[Route('/marketplace/integrations/ifood/orders/{orderId}/cancel-reasons', name: 'marketplace_integrations_ifood_order_cancel_reasons', methods: ['GET'])]
    public function cancelReasonsOrderAction(string $orderId): JsonResponse
    {
        $order = $this->resolveOrder($orderId);
        if (!$order) {
            return $this->orderNotFound();
        }

        if (!$this->isIfoodOrder($order)) {
            return new JsonResponse(['error' => 'Order is not linked to iFood'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = [
                'errno' => 0,
                'errmsg' => 'ok',
                'reasons' => $this->iFoodService->getIfoodCancellationReasons($order),
            ];
        } catch (\Throwable $e) {
            $result = [
                'errno' => 1,
                'errmsg' => 'Falha ao carregar motivos de cancelamento no iFood: ' . $this->normalizeString($e->getMessage()),
                'reasons' => [],
            ];
        }

        return new JsonResponse([
            'action' => 'cancel_reasons',
            'result' => $result,
            'state' => $this->buildOrderIntegrationDetail($order),
        ]);
    }

    #[Route('/marketplace/integrations/ifood/orders/{orderId}/negotiation/{decision}', name: 'marketplace_integrations_ifood_order_negotiation', methods: ['POST'])]
    public function respondOrderNegotiation(string $orderId, string $decision, Request $request): JsonResponse
    {
        $order = $this->resolveOrder($orderId);
        if (!$order) {
            return $this->orderNotFound();
        }

        if (!$this->isIfoodOrder($order)) {
            return new JsonResponse(['error' => 'Order is not linked to iFood'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $reason = $this->normalizeString($payload['reason'] ?? null);
        $result = $this->iFoodService->respondHandshakeDispute(
            $order,
            $decision,
            $reason !== '' ? $reason : null
        );
        $this->manager->refresh($order);

        return new JsonResponse([
            'action' => 'negotiation_' . strtolower($decision),
            'result' => $result,
            'state' => $this->buildOrderIntegrationDetail($order),
        ]);
    }

    #[Route('/marketplace/integrations/ifood/orders/{orderId}/state', name: 'marketplace_integrations_ifood_order_state', methods: ['GET'])]
    public function getOrderState(string $orderId): JsonResponse
    {
        $order = $this->resolveOrder($orderId);
        if (!$order) {
            return $this->orderNotFound();
        }

        if (!$this->isIfoodOrder($order)) {
            return new JsonResponse([
                'error' => 'Order is not linked to iFood',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse($this->buildOrderIntegrationDetail($order));
    }

    private function mapIfoodPaymentMethodLabel(string $method): string
    {
        return match (strtoupper($method)) {
            'CASH' => 'Dinheiro',
            'DEBIT' => 'Cartao de debito',
            'CREDIT' => 'Cartao de credito',
            'MEAL_VOUCHER' => 'Vale refeicao',
            'FOOD_VOUCHER' => 'Vale alimentacao',
            'PIX' => 'Pix',
            default => $method !== '' ? $method : 'Nao informado',
        };
    }

    private function buildPaymentDetail(array $payload, array $storedState = []): array
    {
        $order = $this->resolveOrderPayload($payload);
        $payments = is_array($order['payments'] ?? null) ? $order['payments'] : [];
        $methods = is_array($payments['methods'] ?? null) ? $payments['methods'] : [];

        $method = is_array($methods[0] ?? null) ? $methods[0] : [];
        $methodCode = strtoupper($this->normalizeString($method['method'] ?? null));
        $methodLabel = $this->mapIfoodPaymentMethodLabel($methodCode);
        $methodType = strtoupper($this->normalizeString($method['type'] ?? null));
        $brand = strtoupper($this->normalizeString($method['card']['brand'] ?? null));

        $amountPaid = (float) ($payments['prepaid'] ?? ($storedState['amount_paid'] ?? 0.0));
        $amountPending = (float) ($payments['pending'] ?? ($storedState['amount_pending'] ?? 0.0));
        $changeFor = (float) ($method['cash']['changeFor'] ?? ($storedState['change_for'] ?? 0.0));

        $selectedPaymentLabel = trim($methodLabel . ($brand !== '' ? " ({$brand})" : ''));
        $isPaidOnline = $amountPending <= 0.009;

        return [
            'pay_type' => $this->normalizeString($storedState['pay_type'] ?? ($methodType !== '' ? strtolower($methodType) : null)),
            'pay_type_label' => $this->normalizeString(
                $storedState['pay_type_label']
                ?? ($methodType === 'ONLINE' ? 'Pagamento online' : ($methodType === 'OFFLINE' ? 'Pagamento na entrega' : null))
            ),
            'pay_method' => $this->normalizeString($storedState['pay_method'] ?? ($methodCode !== '' ? strtolower($methodCode) : null)),
            'pay_method_label' => $this->normalizeString($storedState['pay_method_label'] ?? $methodLabel),
            'pay_channel' => $this->normalizeString($storedState['pay_channel'] ?? ($brand !== '' ? $brand : $methodCode)),
            'pay_channel_label' => $this->normalizeString($storedState['pay_channel_label'] ?? ($brand !== '' ? $brand : $methodLabel)),
            'selected_payment_label' => $this->normalizeString($storedState['selected_payment_label'] ?? $selectedPaymentLabel),
            'amount_paid' => $amountPaid,
            'amount_pending' => $amountPending,
            'customer_need_paying_money' => (float) ($storedState['customer_need_paying_money'] ?? $amountPending),
            'collect_on_delivery_amount' => (float) ($storedState['collect_on_delivery_amount'] ?? ($isPaidOnline ? 0.0 : $amountPending)),
            'shop_paid_money' => (float) ($storedState['shop_paid_money'] ?? $amountPaid),
            'change_for' => $changeFor,
            'change_amount' => max(0.0, $changeFor - $amountPending),
            'needs_change' => $changeFor > 0.009,
            'is_fully_paid' => $amountPending <= 0.009,
            'is_paid_online' => $isPaidOnline,
        ];
    }

    private function buildFinancialDetail(array $payload, array $storedState = []): array
    {
        $order    = $this->resolveOrderPayload($payload);
        $total    = is_array($order['total'] ?? null) ? $order['total'] : [];
        $methods  = is_array($order['payments']['methods'] ?? null) ? $order['payments']['methods'] : [];
        $benefits = is_array($order['benefits'] ?? null) ? $order['benefits'] : [];

        $itemsTotal    = (float) ($total['itemsPrice'] ?? $total['subTotal'] ?? ($storedState['items_total'] ?? 0.0));
        $deliveryFee   = (float) ($total['deliveryFee'] ?? ($storedState['delivery_fee'] ?? 0.0));
        $additionalFees = (float) ($total['additionalFees'] ?? ($storedState['additional_fees'] ?? 0.0));
        $orderAmount   = (float) ($total['orderAmount'] ?? ($storedState['order_amount'] ?? 0.0));

        /* descontos separados: iFood vs loja */
        $ifoodSubsidy    = 0.0;
        $merchantSubsidy = 0.0;
        $discountTotal   = 0.0;
        $voucherCodes    = [];
        foreach ($benefits as $benefit) {
            if (!is_array($benefit)) {
                continue;
            }

            $discountTotal += (float) ($benefit['value'] ?? 0.0);
            $campaign = is_array($benefit['campaign'] ?? null) ? $benefit['campaign'] : [];
            $code = $this->normalizeString(
                $benefit['code']
                    ?? $benefit['couponCode']
                    ?? $benefit['voucherCode']
                    ?? $campaign['code']
                    ?? $campaign['name']
                    ?? $campaign['id']
                    ?? null
            );
            if ($code !== '') {
                $voucherCodes[$code] = true;
            }
            foreach ((array) ($benefit['sponsorshipValues'] ?? []) as $sponsor) {
                $name  = strtoupper($this->normalizeString($sponsor['name'] ?? null));
                $value = (float) ($sponsor['value'] ?? 0.0);
                if ($name === 'IFOOD') {
                    $ifoodSubsidy += $value;
                } else {
                    $merchantSubsidy += $value;
                }
            }
        }

        /* bandeira do cartão e troco */
        $paymentBrand  = null;
        $changeFor     = null;
        $paymentLabels = [];
        foreach ($methods as $m) {
            $type  = strtoupper($this->normalizeString($m['type'] ?? null));
            $brand = $this->normalizeString($m['card']['brand'] ?? null);
            if ($brand !== '') {
                $paymentBrand = $brand;
            }
            $change = $m['cash']['changeFor'] ?? null;
            if ($change !== null) {
                $changeFor = (float) $change;
            }
            if ($type !== '') {
                $paymentLabels[] = $type . ($brand !== '' ? " ($brand)" : '');
            }
        }

        return [
            'items_total'      => $itemsTotal,
            'delivery_fee'     => $deliveryFee,
            'additional_fees'  => $additionalFees,
            'order_amount'     => $orderAmount,
            'discount_total'   => $discountTotal > 0 ? $discountTotal : (float) ($storedState['discount_total'] ?? 0.0),
            'ifood_subsidy'    => $ifoodSubsidy > 0 ? $ifoodSubsidy : (float) ($storedState['ifood_subsidy'] ?? 0.0),
            'merchant_subsidy' => $merchantSubsidy > 0 ? $merchantSubsidy : (float) ($storedState['merchant_subsidy'] ?? 0.0),
            'voucher_code'     => implode(', ', array_keys($voucherCodes)) ?: $this->normalizeString($storedState['voucher_code'] ?? null),
            'payment_brand'    => $paymentBrand,
            'change_for'       => $changeFor,
            'payment_labels'   => $paymentLabels,
        ];
    }

    private function extractItemRemarks(array $payload): array
    {
        $orderPayload = $this->resolveOrderPayload($payload);
        $items = is_array($orderPayload['items'] ?? null) ? $orderPayload['items'] : [];
        $remarks = [];
        foreach ($items as $item) {
            $obs = $this->normalizeString($item['observations'] ?? $item['notes'] ?? null);
            if ($obs !== '') {
                $remarks[] = [
                    'name'        => $this->normalizeString($item['name'] ?? null),
                    'observation' => $obs,
                ];
            }
        }
        return $remarks;
    }

    private function buildDeliveryDetail(
        array $payload,
        array $storedState,
        string $remoteState,
        array $deliveryContext,
        array $capabilities
    ): array {
        $orderPayload = $this->resolveOrderPayload($payload);
        $delivery = is_array($orderPayload['delivery'] ?? null) ? $orderPayload['delivery'] : [];
        $deliveryAddress = is_array($delivery['deliveryAddress'] ?? null) ? $delivery['deliveryAddress'] : [];
        $customer = is_array($orderPayload['customer'] ?? null) ? $orderPayload['customer'] : [];
        $customerPhone = is_array($customer['phone'] ?? null) ? $customer['phone'] : [];
        $liveTracking = is_array($delivery['liveTracking'] ?? null)
            ? $delivery['liveTracking'] : [];
        $riderData    = is_array($liveTracking['rider'] ?? null) ? $liveTracking['rider'] : [];

        $displayId = $this->normalizeString($orderPayload['displayId'] ?? null);
        $pickupCode = $this->normalizeString(
            $delivery['pickupCode']
                ?? $delivery['pickup_code']
                ?? $this->normalizePickupCode($storedState['pickup_code'] ?? null, $displayId)
                ?? null
        );
        $handoverCode = $this->normalizeString(
            $storedState['handover_code']
                ?? $pickupCode
                ?? null
        );
        $locator = $this->normalizeString(
            $delivery['locator']
                ?? $delivery['localizer']
                ?? $deliveryAddress['locator']
                ?? $deliveryAddress['localizer']
                ?? $customerPhone['localizer']
                ?? $storedState['locator']
                ?? null
        );
        $handoverPageUrl = $this->normalizeString(
            $delivery['handoverPageUrl']
                ?? $delivery['handover_page_url']
                ?? $deliveryAddress['handoverPageUrl']
                ?? $deliveryAddress['handover_page_url']
                ?? $storedState['handover_page_url']
                ?? null
        );
        $handoverConfirmationUrl = $this->normalizeString(
            $delivery['handoverConfirmationUrl']
                ?? $delivery['handover_confirmation_url']
                ?? $deliveryAddress['handoverConfirmationUrl']
                ?? $deliveryAddress['handover_confirmation_url']
                ?? $delivery['confirmationUrl']
                ?? $delivery['confirmation_url']
                ?? $deliveryAddress['confirmationUrl']
                ?? $deliveryAddress['confirmation_url']
                ?? $storedState['handover_confirmation_url']
                ?? null
        );

        if ($handoverConfirmationUrl === '' && $handoverPageUrl !== '') {
            $handoverConfirmationUrl = $handoverPageUrl;
        }
        if ($handoverPageUrl === '' && $handoverConfirmationUrl !== '') {
            $handoverPageUrl = $handoverConfirmationUrl;
        }
        if (
            $handoverConfirmationUrl === ''
            && ($deliveryContext['is_store_delivery'] ?? false)
        ) {
            $handoverConfirmationUrl = self::IFOOD_SELF_DELIVERY_CONFIRMATION_URL;
            $handoverPageUrl = $handoverPageUrl !== '' ? $handoverPageUrl : $handoverConfirmationUrl;
        }

        $virtualPhone  = $this->normalizeString(
            $customerPhone['localizer']
                ?? $deliveryAddress['localizer']
                ?? $storedState['virtual_phone']
                ?? null
        );
        $riderName     = $this->normalizeString($riderData['name'] ?? null);
        $riderPhone    = $this->normalizeString($riderData['phone']['number'] ?? null);
        $riderToStore  = $this->normalizeString($liveTracking['riderToStoreEta'] ?? null);
        $expectedEta   = $this->normalizeString(
            $delivery['deliveryDateTime']
                ?? $delivery['estimatedDeliveryDate']
                ?? $storedState['expected_arrived_eta']
                ?? null
        );

        return [
            'delivery_label'                  => $deliveryContext['delivery_label'],
            'remote_delivery_status'          => $remoteState,
            'expected_arrived_eta'            => $expectedEta !== '' ? $expectedEta : null,
            'pickup_code'                     => $pickupCode !== '' ? $pickupCode : null,
            'delivered_by'                    => $deliveryContext['delivered_by'] ?? null,
            'delivery_mode'                   => $deliveryContext['delivery_mode'] ?? null,
            'handover_code'                   => $handoverCode !== '' ? $handoverCode : null,
            'locator'                         => $locator !== '' ? $locator : null,
            'handover_page_url'               => $handoverPageUrl !== '' ? $handoverPageUrl : null,
            'handover_confirmation_url'       => $handoverConfirmationUrl !== '' ? $handoverConfirmationUrl : null,
            'virtual_phone_number'            => $virtualPhone !== '' ? $virtualPhone : null,
            'rider_name'                      => $riderName !== '' ? $riderName : null,
            'rider_phone'                     => $riderPhone !== '' ? $riderPhone : null,
            'rider_to_store_eta'              => $riderToStore !== '' ? $riderToStore : null,
            'is_store_delivery'               => $deliveryContext['is_store_delivery'],
            'is_platform_delivery'            => $deliveryContext['is_platform_delivery'],
            'allows_manual_delivery_completion' => (bool) ($capabilities['can_delivered'] ?? false),
        ];
    }
}
