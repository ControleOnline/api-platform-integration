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
use ControleOnline\Service\Marketplace\MarketplaceIntegrationHandlerInterface;
use ControleOnline\Service\Marketplace\AbstractMarketplaceService;
use ControleOnline\Service\Marketplace\MarketplaceIntegrationStateProviderInterface;
use ControleOnline\Service\Marketplace\MarketplaceLogisticsQuoteProviderInterface;
use ControleOnline\Service\Marketplace\MarketplaceOrderSnapshotProviderInterface;
use ControleOnline\Event\EntityChangedEvent;
use DateTime;
use DateTimeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

class Food99PeopleOperationsService extends AbstractMarketplaceService
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

    private function searchPayloadValueByKeys(mixed $payload, array $keys): ?string
    {
        if (!is_array($payload)) {
            return null;
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $payload) && !is_array($payload[$key])) {
                $value = $this->normalizeIncomingFood99Value($payload[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        foreach ($payload as $value) {
            if (!is_array($value)) {
                continue;
            }

            $resolved = $this->searchPayloadValueByKeys($value, $keys);
            if ($resolved !== null && $resolved !== '') {
                return $resolved;
            }
        }

        return null;
    }

    private function buildFood99AddressDisplay(array $address): ?string
    {
        $parts = array_filter([
            $this->normalizeIncomingFood99Value($address['poi_address'] ?? null),
            $this->normalizeIncomingFood99Value($address['street_name'] ?? null),
            $this->normalizeIncomingFood99Value($address['street_number'] ?? null),
            $this->normalizeIncomingFood99Value($address['district'] ?? null),
            $this->normalizeIncomingFood99Value($address['city'] ?? null),
            $this->normalizeIncomingFood99Value($address['state'] ?? null),
            $this->normalizeIncomingFood99Value($address['postal_code'] ?? null),
            $this->normalizeIncomingFood99Value($address['reference'] ?? null),
        ], static fn($value) => $value !== null && $value !== '');

        if (empty($parts)) {
            return null;
        }

        return implode(', ', array_values(array_unique($parts)));
    }

    private function isFood99PrivacyPlaceholder(mixed $value): bool
    {
        $normalized = strtolower(trim((string) $this->normalizeIncomingFood99Value($value)));
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, [
            'privacy protection',
            'privacy_protection',
            'privacy-protection',
            'protected',
        ], true);
    }

    private function sanitizeFood99IdentityValue(mixed $value): ?string
    {
        $normalized = $this->normalizeIncomingFood99Value($value);
        if ($normalized === '' || $this->isFood99PrivacyPlaceholder($normalized)) {
            return null;
        }

        return $normalized;
    }

    private function resolveFood99CustomerName(array $address, string $fallback = 'Cliente Food99'): string
    {
        $nameParts = array_filter([
            $this->sanitizeFood99IdentityValue($address['name'] ?? null),
            $this->sanitizeFood99IdentityValue($address['first_name'] ?? null),
            $this->sanitizeFood99IdentityValue($address['last_name'] ?? null),
        ], static fn($value) => $value !== null && $value !== '');

        $resolved = trim(implode(' ', array_values(array_unique($nameParts))));

        if ($resolved !== '') {
            return $resolved;
        }

        return $fallback;
    }

    private function resolveFood99RemoteClientId(array $address, array $payload = []): string
    {
        $clientId = $this->searchPayloadValueByKeys($address, ['uid']);
        $normalizedClientId = $this->normalizeIncomingFood99Value($clientId);
        if ($normalizedClientId !== '' && $normalizedClientId !== '0') {
            return $normalizedClientId;
        }

        return '';
    }

    private function buildFood99ClientLookupPayloads(array $address, array $payload): array
    {
        $candidatePayloads = [];

        if (!empty($address)) {
            $candidatePayloads[] = $address;
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $orderInfo = is_array($data['order_info'] ?? null) ? $data['order_info'] : [];

        foreach ([$payload, $data, $orderInfo] as $parentPayload) {
            if (!is_array($parentPayload) || $parentPayload === []) {
                continue;
            }

            foreach ($this->extractFood99CustomerPayloads($parentPayload) as $candidatePayload) {
                $candidatePayloads[] = $candidatePayload;
            }
        }

        return $candidatePayloads;
    }

    private function extractFood99CustomerPayloads(array $payload): array
    {
        $customerKeys = [
            'customer',
            'customer_info',
            'customerInfo',
            'customer_data',
            'customerData',
            'buyer',
            'consumer',
            'receiver',
            'recipient',
            'client',
            'client_info',
            'clientInfo',
            'user',
            'user_info',
            'userInfo',
        ];

        $candidatePayloads = [];

        foreach ($payload as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            if (in_array($key, $customerKeys, true)) {
                $candidatePayloads[] = $value;
            }

            foreach ($this->extractFood99CustomerPayloads($value) as $nestedPayload) {
                $candidatePayloads[] = $nestedPayload;
            }
        }

        return $candidatePayloads;
    }

    private function extractFood99CourierPayloads(array $payload): array
    {
        $courierKeys = [
            'courier',
            'courier_info',
            'courierInfo',
            'courier_data',
            'courierData',
            'rider',
            'rider_info',
            'riderInfo',
            'rider_data',
            'riderData',
            'driver',
            'driver_info',
            'driverInfo',
            'driver_data',
            'driverData',
            'delivery_courier',
            'deliveryCourier',
            'delivery_person',
            'deliveryPerson',
            'delivery_person_info',
            'deliveryPersonInfo',
            'food99courier',
            'food99Courier',
            'FOOD99COURIER',
        ];

        $candidatePayloads = [];

        foreach ($payload as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            if (in_array($key, $courierKeys, true)) {
                $candidatePayloads[] = $value;
            }

            foreach ($this->extractFood99CourierPayloads($value) as $nestedPayload) {
                $candidatePayloads[] = $nestedPayload;
            }
        }

        return $candidatePayloads;
    }

    private function extractFood99PayloadValueFromNestedSections(array $json, array $directKeys, array $nestedKeys): ?string
    {
        $directValue = $this->searchPayloadValueByKeys($json, $directKeys);
        if ($directValue !== null && $directValue !== '') {
            return $directValue;
        }

        foreach ($this->extractFood99CourierPayloads($json) as $courierPayload) {
            $resolvedValue = $this->searchPayloadValueByKeys($courierPayload, $nestedKeys);
            if ($resolvedValue !== null && $resolvedValue !== '') {
                return $resolvedValue;
            }
        }

        return null;
    }

    private function resolveFood99ClientPhone(array $address): array
    {
        $rawPhone = $this->sanitizeFood99IdentityValue($address['phone'] ?? null);
        if ($rawPhone === null) {
            return [];
        }

        $digits = preg_replace('/\D+/', '', $rawPhone);
        if ($digits === null || $digits === '') {
            return [];
        }

        if (str_starts_with($digits, '55') && strlen($digits) > 11) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) > 11) {
            $digits = substr($digits, -11);
        }

        if (strlen($digits) < 10) {
            return [];
        }

        $ddd = substr($digits, 0, 2);
        $phone = substr($digits, 2);

        if ($ddd === '' || $phone === '') {
            return [];
        }

        return [
            'ddi' => 55,
            'ddd' => (int) $ddd,
            'phone' => (int) $phone,
        ];
    }

    private function shouldUpdateFood99ClientName(People $client, string $resolvedName): bool
    {
        $candidateName = trim($resolvedName);
        if ($candidateName === '') {
            return false;
        }

        $currentName = strtolower(trim((string) $client->getName()));
        $normalizedCandidateName = strtolower($candidateName);

        if ($currentName === $normalizedCandidateName) {
            return false;
        }

        return $currentName === ''
            || $currentName === 'name not given'
            || $currentName === 'cliente food99'
            || str_starts_with($currentName, 'cliente food99 ');
    }

    private function syncFood99ClientData(
        People $client,
        People $provider,
        array $address,
        string $remoteClientId = ''
    ): People {
        $resolvedName = $this->resolveFood99CustomerName($address, '');
        if ($this->shouldUpdateFood99ClientName($client, $resolvedName)) {
            $client->setName($resolvedName);
            $this->entityManager->persist($client);
        }

        $phone = $this->resolveFood99ClientPhone($address);
        if (!empty($phone)) {
            try {
                $this->peopleService->addPhone($client, $phone);
            } catch (\Throwable $exception) {
                self::$logger->warning('Food99 client phone could not be synced', [
                    'client_id' => $client->getId(),
                    'provider_id' => $provider->getId(),
                    'phone' => $phone,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($remoteClientId !== '') {
            $this->extraDataService->discoveryExtraData($client, self::APP_CONTEXT, 'code', $remoteClientId, self::APP_CONTEXT);
        }

        $this->peopleService->discoveryLink($provider, $client, 'client');

        return $client;
    }

    private function resolveFood99CourierPeople(?string $courierName, ?string $courierPhone): ?People
    {
        $resolvedName = trim((string) $courierName);
        $resolvedPhone = trim((string) $courierPhone);

        if ($resolvedName === '' && $resolvedPhone === '') {
            return null;
        }

        $phone = $resolvedPhone !== ''
            ? $this->resolveFood99ClientPhone(['phone' => $resolvedPhone])
            : [];

        if ($resolvedName !== '') {
            $existingCourier = $this->entityManager->getRepository(People::class)->findOneBy([
                'name' => $resolvedName,
                'peopleType' => 'F',
            ]);

            if ($existingCourier instanceof People) {
                if (!empty($phone)) {
                    try {
                        $this->peopleService->addPhone($existingCourier, $phone);
                    } catch (\Throwable $exception) {
                        self::$logger->warning('Food99 courier phone could not be synced to an existing people record', [
                            'courier_id' => $existingCourier->getId(),
                            'courier_name' => $resolvedName,
                            'courier_phone' => $resolvedPhone,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }

                return $existingCourier;
            }
        }

        if (!empty($phone)) {
            return $this->peopleService->discoveryPeople(
                null,
                null,
                $phone,
                $resolvedName !== '' ? $resolvedName : 'Motoboy 99 Food',
                'F'
            );
        }

        return $this->peopleService->discoveryPeople(
            null,
            null,
            [],
            $resolvedName !== '' ? $resolvedName : 'Motoboy 99 Food',
            'F'
        );
    }

    private function shouldUpdateFood99CourierName(People $courier, string $resolvedName): bool
    {
        $candidateName = trim($resolvedName);
        if ($candidateName === '') {
            return false;
        }

        $currentName = strtolower(trim((string) $courier->getName()));
        $normalizedCandidateName = strtolower($candidateName);

        if ($currentName === $normalizedCandidateName) {
            return false;
        }

        return $currentName === ''
            || $currentName === 'name not given'
            || $currentName === 'motoboy marketplace'
            || $currentName === 'motoboy 99 food'
            || $currentName === 'motoboy 99food'
            || $currentName === 'food99 courier';
    }

    private function syncFood99CourierFromDeliveryState(Order $order, array $deliveryState): ?People
    {
        $courierName = $this->sanitizeFood99IdentityValue($deliveryState['rider_name'] ?? null);
        $courierPhone = $this->sanitizeFood99IdentityValue($deliveryState['rider_phone'] ?? null);

        if (($courierName === null || $courierName === '') && ($courierPhone === null || $courierPhone === '')) {
            return null;
        }

        $courier = $this->resolveFood99CourierPeople($courierName, $courierPhone);
        if (!$courier instanceof People) {
            return null;
        }

        $courierTouched = false;
        if ($courierName !== null && $courierName !== '' && $this->shouldUpdateFood99CourierName($courier, $courierName)) {
            $courier->setName($courierName);
            $this->entityManager->persist($courier);
            $courierTouched = true;
        }

        if ($order->getDeliveryPeople()?->getId() !== $courier->getId()) {
            $order->setDeliveryPeople($courier);
            $courierTouched = true;
        }

        if ($courierTouched) {
            $order->setAlterDate(new DateTime('now'));
        }

        return $courier;
    }
}
