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
use ControleOnline\Service\Marketplace\AbstractMarketplaceService;
use ControleOnline\Service\Client\WebsocketClient;
use ControleOnline\Service\Marketplace\MarketplaceIntegrationHandlerInterface;
use ControleOnline\Service\Marketplace\MarketplaceIntegrationStateProviderInterface;
use ControleOnline\Service\Marketplace\MarketplaceLogisticsQuoteProviderInterface;
use ControleOnline\Service\Marketplace\MarketplaceOrderSnapshotProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use ControleOnline\Service\LoggerService;
use DateTime;
use ControleOnline\Event\EntityChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class IfoodPeopleOperationsService extends AbstractMarketplaceService
{
    private const APP_CONTEXT = Order::APP_IFOOD;

    protected function getMarketplaceApp(): string
    {
        return self::APP_CONTEXT;
    }

    public function discoveryClient(People $provider, array $customerData): ?People
    {
        $customerName = $this->normalizeString($customerData['name'] ?? null);
        $codClienteiFood = $this->normalizeString($customerData['id'] ?? null);
        $document = $this->resolveCustomerDocumentNumber($customerData);
        $phone = $this->resolveCustomerPhoneForDiscovery($customerData);

        self::$logger->info('iFood client discovery started', [
            'provider_id' => $provider->getId(),
            'ifood_customer_id' => $codClienteiFood,
            'customer_name' => $customerName,
            'document' => $document,
            'has_phone_for_discovery' => !empty($phone),
            'raw_phone_number' => $this->normalizeString($customerData['phone']['number'] ?? null),
            'raw_phone_localizer' => $this->normalizeString($customerData['phone']['localizer'] ?? null),
        ]);

        if ($customerName === '' && $document === null && $codClienteiFood === '') {
            self::$logger->warning('Dados do cliente incompletos', ['customer' => $customerData]);
            return null;
        }

        $documentType = $this->resolveCustomerDocumentType($customerData, $document);
        $clientByCode = $codClienteiFood !== ''
            ? $this->extraDataService->getEntityByExtraData(self::APP_CONTEXT, 'code', $codClienteiFood, People::class)
            : null;
        $clientByDocument = null;
        $client = null;

        if ($clientByCode instanceof People) {
            self::$logger->info('iFood client discovery matched by remote code', [
                'ifood_customer_id' => $codClienteiFood,
                'people_id' => $clientByCode->getId(),
            ]);
        }

        if ($document !== null) {
            try {
                $documentEntity = $this->peopleService->getDocument($document, $documentType);
                $clientByDocument = $documentEntity?->getPeople();
                if ($clientByDocument instanceof People) {
                    self::$logger->info('iFood client discovery matched by document', [
                        'ifood_customer_id' => $codClienteiFood,
                        'people_id' => $clientByDocument->getId(),
                        'document' => $document,
                    ]);
                }
            } catch (\Throwable $exception) {
                self::$logger->warning('iFood client document lookup failed', [
                    'ifood_customer_id' => $codClienteiFood,
                    'document' => $document,
                    'document_type' => $documentType,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($clientByCode instanceof People) {
            $client = $clientByCode;
        }

        if (!$client instanceof People && $clientByDocument instanceof People) {
            $client = $clientByDocument;
        }

        if (!$client instanceof People) {
            try {
                $client = $this->peopleService->discoveryPeople(null, null, $phone, $customerName !== '' ? $customerName : null);
                if ($client instanceof People) {
                    self::$logger->info('iFood client discovery resolved via standard discoveryPeople', [
                        'ifood_customer_id' => $codClienteiFood,
                        'people_id' => $client->getId(),
                        'document' => $document,
                        'used_phone' => !empty($phone),
                    ]);
                }
            } catch (\Throwable $exception) {
                self::$logger->warning('iFood client standard discovery failed', [
                    'ifood_customer_id' => $codClienteiFood,
                    'customer_name' => $customerName,
                    'document' => $document,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if (!$client instanceof People && $customerName !== '') {
            $client = $this->peopleService->discoveryPeople(null, null, null, $customerName);
            if ($client instanceof People) {
                self::$logger->info('iFood client discovery fell back to name-only lookup', [
                    'ifood_customer_id' => $codClienteiFood,
                    'people_id' => $client->getId(),
                    'customer_name' => $customerName,
                ]);
            }
        }

        if (!$client instanceof People) {
            self::$logger->warning('iFood client could not be resolved after discovery attempts', [
                'ifood_customer_id' => $codClienteiFood,
                'customer_name' => $customerName,
                'document' => $document,
            ]);
            return null;
        }

        if ($clientByCode instanceof People && $document !== null && $clientByCode->getId() !== $client->getId()) {
            self::$logger->warning('iFood client mismatch detected between code and document mapping', [
                'ifood_customer_id' => $codClienteiFood,
                'people_by_code' => $clientByCode->getId(),
                'people_by_document' => $client->getId(),
                'document' => $document,
            ]);
        }

        return $this->syncIfoodClientData(
            $client,
            $provider,
            $customerName,
            $phone,
            $document,
            $documentType,
            $codClienteiFood
        );
    }

    public function resolveCustomerDocumentNumber(array $customerData): ?string
    {
        $digits = $this->normalizeDigits($this->normalizeString(
            $customerData['documentNumber']
                ?? $customerData['document_number']
                ?? null
        ));
        if ($digits === '') {
            return null;
        }

        $length = strlen($digits);
        if ($length !== 11 && $length !== 14) {
            return null;
        }

        return $digits;
    }

    public function resolveCustomerDocumentType(array $customerData, ?string $documentNumber = null): ?string
    {
        $documentType = strtoupper($this->normalizeString(
            $customerData['documentType']
                ?? $customerData['document_type']
                ?? null
        ));
        if ($documentType !== '') {
            return $documentType;
        }

        if ($documentNumber === null || $documentNumber === '') {
            return null;
        }

        return strlen($documentNumber) > 11 ? 'CNPJ' : 'CPF';
    }

    private function resolveBooleanFlag(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower($this->normalizeString($value));
        if ($normalized === '') {
            return null;
        }

        $parsed = match ($normalized) {
            '1', 'true', 'yes', 'y', 'sim' => true,
            '0', 'false', 'no', 'n', 'nao', 'não' => false,
            default => null,
        };

        if ($parsed !== null) {
            return $parsed;
        }

        return null;
    }

    public function resolveTaxDocumentRequested(array $customerData, ?string $documentNumber = null): bool
    {
        $explicitFlag = $this->resolveBooleanFlag(
            $customerData['taxDocumentRequested']
                ?? $customerData['tax_document_requested']
                ?? $customerData['requiresTaxDocument']
                ?? $customerData['requires_tax_document']
                ?? $customerData['issueTaxDocument']
                ?? $customerData['issue_tax_document']
                ?? null
        );

        if ($explicitFlag !== null) {
            return $explicitFlag;
        }

        return !empty($documentNumber);
    }

    private function resolveCustomerPhoneForDiscovery(array $customerData): ?array
    {
        $phoneData = is_array($customerData['phone'] ?? null) ? $customerData['phone'] : [];
        $rawNumber = $this->normalizeString($phoneData['number'] ?? null);
        $localizer = $this->normalizeString($phoneData['localizer'] ?? null);
        $digits = $this->normalizeDigits($rawNumber);

        if ($digits === '') {
            return null;
        }

        // O iFood costuma fornecer um telefone operacional mascarado (0800 + localizer).
        // Esse numero nao deve ser usado para identificar/reaproveitar o cliente local.
        if ($localizer !== '' || str_starts_with($digits, '0800')) {
            return null;
        }

        $ddi = '55';
        if (str_starts_with($digits, '55') && (strlen($digits) === 12 || strlen($digits) === 13)) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) !== 10 && strlen($digits) !== 11) {
            return null;
        }

        $ddd = substr($digits, 0, 2);
        $phone = substr($digits, 2);
        if ($ddd === '' || $phone === '') {
            return null;
        }

        return [
            'ddi' => $ddi,
            'ddd' => $ddd,
            'phone' => $phone,
        ];
    }

    private function shouldUpdateIfoodClientName(People $client, string $resolvedName): bool
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
            || $currentName === 'cliente ifood'
            || str_starts_with($currentName, 'cliente ifood ');
    }

    private function syncIfoodClientData(
        People $client,
        People $provider,
        string $resolvedName,
        ?array $phone,
        ?string $document = null,
        ?string $documentType = null,
        string $remoteClientId = ''
    ): People {
        if ($this->shouldUpdateIfoodClientName($client, $resolvedName)) {
            $client->setName($resolvedName);
            $this->entityManager->persist($client);
        }

        if (!empty($phone)) {
            try {
                $this->peopleService->addPhone($client, $phone);
            } catch (\Throwable $exception) {
                self::$logger->warning('iFood client phone could not be synced', [
                    'client_id' => $client->getId(),
                    'provider_id' => $provider->getId(),
                    'phone' => $phone,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if (!empty($document)) {
            try {
                $existingDocument = $this->peopleService->getDocument($document, $documentType);
                if ($existingDocument && $existingDocument->getPeople()->getId() !== $client->getId()) {
                    self::$logger->warning('iFood client document already belongs to another people record', [
                        'client_id' => $client->getId(),
                        'provider_id' => $provider->getId(),
                        'document' => $document,
                        'document_type' => $documentType,
                        'document_people_id' => $existingDocument->getPeople()->getId(),
                    ]);
                } else {
                    $this->peopleService->addDocument($client, $document, $documentType);
                }
            } catch (\Throwable $exception) {
                self::$logger->warning('iFood client document could not be synced', [
                    'client_id' => $client->getId(),
                    'provider_id' => $provider->getId(),
                    'document' => $document,
                    'document_type' => $documentType,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($remoteClientId !== '') {
            $this->bindIfoodCodeToPeople($client, $remoteClientId);
        }

        $this->peopleService->discoveryLink($provider, $client, 'client');

        return $client;
    }

    private function bindIfoodCodeToPeople(People $people, string $code, string $fieldName = 'code'): People
    {
        $code = $this->normalizeString($code);
        if ($code === '') {
            return $people;
        }

        $currentBinding = $this->findEntityByExtraData('People', $fieldName, $code, People::class);
        if ($currentBinding instanceof People && $currentBinding->getId() === $people->getId()) {
            return $people;
        }

        $this->extraDataService->upsertExtraDataValue(
            self::APP_CONTEXT,
            'People',
            (int) $people->getId(),
            $fieldName,
            $code,
            'text',
            self::APP_CONTEXT
        );

        if ($currentBinding instanceof People && $currentBinding->getId() !== $people->getId()) {
            self::$logger->warning('iFood client code rebound to a different local people record', [
                'ifood_customer_id' => $code,
                'previous_people_id' => $currentBinding->getId(),
                'current_people_id' => $people->getId(),
            ]);
        }

        return $people;
    }

    private function findEntityByExtraData(string $entityName, string $fieldName, string $value, string $entityClass): ?object
    {
        $normalizedValue = trim((string) $value);
        if ($normalizedValue === '') {
            return null;
        }

        $entity = $this->extraDataService->getEntityByExtraData(
            self::APP_CONTEXT,
            $fieldName,
            $normalizedValue,
            $entityClass
        );

        return is_object($entity) ? $entity : null;
    }


}
