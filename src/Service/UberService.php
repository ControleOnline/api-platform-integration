<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Address;
use ControleOnline\Entity\Email;
use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Phone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class UberService
{
    private const APP_CONTEXT = 'Uber';
    private const API_BASE_URL = 'https://api.uber.com';
    private const AUTHORIZATION_URL = 'https://auth.uber.com/oauth/v2/authorize';
    private const AUTH_URL = 'https://auth.uber.com/oauth/v2/token';
    private const STORE_LIST_URL = 'https://api.uber.com/v1/eats/stores';
    private const TOKEN_SCOPE = 'eats.deliveries';
    private const POS_PROVISIONING_SCOPE = 'eats.pos_provisioning';
    private const CURRENCY_CODE = 'BRL';

    private static ?array $authTokenCache = null;
    protected static $logger;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerService $loggerService,
        private readonly RequestPayloadService $requestPayloadService,
        private readonly ConfigService $configService,
    ) {
        self::$logger = $this->loggerService->getLogger(self::APP_CONTEXT);
    }

    public function integrate(Integration $integration): ?Order
    {
        $payload = $this->decodeIntegrationPayload($integration);
        if ($payload === []) {
            self::$logger?->warning('Uber webhook ignored because payload is invalid', [
                'integration_id' => $integration->getId(),
            ]);

            return null;
        }

        $order = $this->resolveOrderFromWebhookPayload($payload);
        if (!$order instanceof Order) {
            self::$logger?->warning('Uber webhook ignored because order could not be resolved', [
                'integration_id' => $integration->getId(),
                'event_id' => $this->extractValue($payload, ['event_id', 'id', 'meta.event_id']),
                'external_order_id' => $this->extractValue($payload, ['external_order_id', 'externalOrderId', 'meta.external_order_id']),
            ]);

            return null;
        }

        $state = $this->getUberState($order);
        $state['last_webhook_event_id'] = $this->extractValue($payload, ['event_id', 'id', 'meta.event_id']);
        $state['last_webhook_event_type'] = $this->extractValue($payload, ['event_type', 'kind', 'type', 'meta.event_type']);
        $state['last_webhook_received_at'] = date('Y-m-d H:i:s');
        $state['last_webhook_order_id'] = $this->extractValue($payload, ['order_id', 'orderId', 'meta.resource_id']);
        $state['last_webhook_external_order_id'] = $this->extractValue($payload, ['external_order_id', 'externalOrderId', 'meta.external_order_id']);
        $state['last_webhook_tracking_url'] = $this->extractValue($payload, ['tracking_url', 'trackingUrl', 'order_tracking_url']);
        $state['last_webhook_status'] = $this->extractValue($payload, ['status', 'order_status', 'meta.status']);
        $state['last_webhook_payload'] = $payload;

        if (isset($payload['data']) && is_array($payload['data'])) {
            $state['last_webhook_data'] = $payload['data'];
        }

        $this->storeUberState($order, $state);

        self::$logger?->info('Uber webhook processed', [
            'order_id' => $order->getId(),
            'event_id' => $state['last_webhook_event_id'] ?? null,
            'status' => $state['last_webhook_status'] ?? null,
        ]);

        return $order;
    }

    public function requestDriver(Order $order): array
    {
        $provider = $order->getProvider();
        if (!$provider instanceof People) {
            throw new \RuntimeException('Pedido sem provider vinculado.');
        }

        $sourceOrder = $this->resolveQuoteSourceOrder($order);

        $pickupAddress = $this->resolvePickupAddress($order);
        $dropoffAddress = $this->resolveDropoffAddress($order);
        if (!$pickupAddress instanceof Address) {
            $pickupAddress = $this->resolvePickupAddress($sourceOrder);
        }

        if (!$dropoffAddress instanceof Address) {
            $dropoffAddress = $this->resolveDropoffAddress($sourceOrder);
        }

        if (!$pickupAddress instanceof Address) {
            throw new \RuntimeException('Pedido sem endereco de coleta valido.');
        }

        if (!$dropoffAddress instanceof Address) {
            throw new \RuntimeException('Pedido sem endereco de entrega valido.');
        }

        $dropoffContact = $this->resolveDropoffContact($order) ?: $this->resolveDropoffContact($sourceOrder);
        if ($dropoffContact === null) {
            throw new \RuntimeException('Pedido sem contato valido para a entrega.');
        }

        $pickupContact = $this->resolvePickupContact($order) ?: $this->resolvePickupContact($sourceOrder);
        $items = $this->buildOrderItemsPayload($sourceOrder);
        if ($items === []) {
            throw new \RuntimeException('Pedido sem itens para solicitar o motoboy.');
        }

        $uberState = $this->getUberState($order);
        if (!empty($uberState['delivery_id']) || !empty($uberState['estimate_id']) || !empty($uberState['quote_id'])) {
            return [
                'already_requested' => true,
                'order_id' => $order->getId(),
                'uber' => $uberState,
            ];
        }

        $pickupLocation = $this->buildLocationPayload($pickupAddress);
        $pickupStore = $this->resolvePickupStore($pickupLocation, $uberState, $provider);
        if (isset($pickupStore['errno']) && (int) $pickupStore['errno'] !== 0) {
            return $pickupStore;
        }

        $pickupStoreId = trim((string) ($pickupStore['store_id'] ?? ''));
        if ($pickupStoreId === '') {
            return [
                'errno' => 500,
                'errmsg' => 'store_id do Uber nao configurado.',
                'store' => $pickupStore,
            ];
        }

        $dropoffPayload = $this->buildAddressPayload($dropoffAddress);
        if ($dropoffPayload === []) {
            return [
                'errno' => 500,
                'errmsg' => 'Endereco de entrega invalido para o Uber.',
            ];
        }

        $pickupInstructions = $this->resolvePickupInstructions($order);
        $dropoffInstructions = $this->resolveDropoffInstructions($order);

        $orderSummary = [
            'currency_code' => self::CURRENCY_CODE,
            'order_value' => $this->toMinorUnit((float) $sourceOrder->getPrice()),
        ];

        $estimateResponse = $this->requestDeliveryEstimate(
            $pickupStoreId,
            $dropoffPayload,
            $pickupInstructions,
            $orderSummary,
            $provider
        );
        if (($estimateResponse['status'] ?? 0) < 200 || ($estimateResponse['status'] ?? 0) >= 300) {
            return [
                'errno' => $estimateResponse['status'] ?? 500,
                'errmsg' => 'Falha ao obter estimate do Uber.',
                'estimate' => $estimateResponse,
            ];
        }

        $estimateBody = is_array($estimateResponse['body']) ? $estimateResponse['body'] : [];
        $estimateId = $this->extractValue($estimateBody, ['estimate_id', 'estimateId']);
        if ($estimateId === '') {
            return [
                'errno' => 500,
                'errmsg' => 'Estimate do Uber nao retornou identificador.',
                'estimate' => $estimateResponse,
            ];
        }

        $pickupAt = (int) ($this->extractPathValue($estimateBody, 'estimates.0.pickup_at') ?? 0);
        if ($pickupAt <= 0) {
            $pickupAt = 0;
        }

        $deliveryResponse = $this->createDelivery(
            $order,
            $estimateId,
            $pickupStoreId,
            $pickupAt,
            $dropoffPayload,
            $pickupInstructions,
            $dropoffInstructions,
            $dropoffContact,
            $items,
            $orderSummary,
            $provider
        );
        if (($deliveryResponse['status'] ?? 0) < 200 || ($deliveryResponse['status'] ?? 0) >= 300) {
            return [
                'errno' => $deliveryResponse['status'] ?? 500,
                'errmsg' => 'Falha ao criar entrega no Uber.',
                'estimate' => $estimateResponse,
                'delivery' => $deliveryResponse,
            ];
        }

        $deliveryBody = is_array($deliveryResponse['body']) ? $deliveryResponse['body'] : [];
        $deliveryId = $this->extractValue($deliveryBody, ['order_id', 'orderId', 'delivery_id', 'deliveryId', 'id']);
        $trackingUrl = $this->extractValue($deliveryBody, ['order_tracking_url', 'tracking_url', 'trackingUrl']);
        $deliveryStatus = $this->extractValue($deliveryBody, ['order_status', 'status']);

        $uberState = array_merge($uberState, [
            'provider_id' => $provider->getId(),
            'store_id' => $pickupStoreId,
            'external_store_id' => $pickupStore['external_store_id'] ?? null,
            'store_lookup_response' => $pickupStore['lookup_response'] ?? null,
            'estimate_id' => $estimateId,
            'quote_id' => $estimateId,
            'delivery_id' => $deliveryId !== '' ? $deliveryId : null,
            'tracking_url' => $trackingUrl !== '' ? $trackingUrl : null,
            'status' => $deliveryStatus !== '' ? $deliveryStatus : 'requested',
            'requested_at' => date('Y-m-d H:i:s'),
            'pickup' => [
                'address' => $pickupAddress,
                'location' => $pickupLocation,
                'contact' => $pickupContact,
                'instructions' => $pickupInstructions,
            ],
            'dropoff' => [
                'address' => $dropoffAddress,
                'payload' => $dropoffPayload,
                'contact' => $dropoffContact,
                'instructions' => $dropoffInstructions,
            ],
            'order_items' => $items,
            'estimate_response' => $estimateBody,
            'quote_response' => $estimateBody,
            'delivery_response' => $deliveryBody,
            'order_summary' => $orderSummary,
        ]);

        $this->storeUberState($order, $uberState);

        return [
            'errno' => 0,
            'errmsg' => 'ok',
            'data' => [
                'order_id' => $order->getId(),
                'estimate_id' => $estimateId,
                'delivery_id' => $deliveryId,
                'tracking_url' => $trackingUrl,
                'status' => $deliveryStatus !== '' ? $deliveryStatus : 'requested',
                'estimate' => $estimateBody,
                'delivery' => $deliveryBody,
            ],
        ];
    }

    public function getStoredIntegrationState(People $provider): array
    {
        $storeId = $this->resolveConfiguredStoreId($provider);
        $clientId = $this->resolveClientId($provider);
        $clientSecret = $this->resolveClientSecret($provider);
        $connected = $storeId !== '' && $clientId !== '' && $clientSecret !== '';

        return [
            'connected' => $connected,
            'remote_connected' => $storeId !== '',
            'online' => $connected,
            'store_id' => $storeId !== '' ? $storeId : null,
            'provider_id' => $provider->getId(),
            'client_id_configured' => $clientId !== '',
            'client_secret_configured' => $clientSecret !== '',
            'last_error_code' => null,
            'last_error_message' => null,
        ];
    }

    public function quoteDelivery(Order $order): array
    {
        $provider = $order->getProvider();
        if (!$provider instanceof People) {
            return [
                'errno' => 400,
                'errmsg' => 'Pedido sem provider vinculado.',
            ];
        }

        $sourceOrder = $this->resolveQuoteSourceOrder($order);
        $pickupAddress = $this->resolvePickupAddress($order) ?: $this->resolvePickupAddress($sourceOrder);
        $dropoffAddress = $this->resolveDropoffAddress($order) ?: $this->resolveDropoffAddress($sourceOrder);

        if (!$pickupAddress instanceof Address) {
            return [
                'errno' => 422,
                'errmsg' => 'Pedido sem endereco de coleta valido.',
            ];
        }

        if (!$dropoffAddress instanceof Address) {
            return [
                'errno' => 422,
                'errmsg' => 'Pedido sem endereco de entrega valido.',
            ];
        }

        $uberState = $this->getUberState($order);
        $pickupLocation = $this->buildLocationPayload($pickupAddress);
        $pickupStore = $this->resolvePickupStore($pickupLocation, $uberState, $provider);
        if (isset($pickupStore['errno']) && (int) $pickupStore['errno'] !== 0) {
            $this->persistQuoteState($order, $this->buildUberStateForError($uberState, $pickupStore, 'error'), [
                'flow' => 'quote',
                'provider_key' => 'uber',
                'provider_label' => self::APP_CONTEXT,
                'quote_state' => 'error',
                'quote_message' => (string) ($pickupStore['errmsg'] ?? 'Falha ao localizar store do Uber.'),
                'quote_requested_at' => date('Y-m-d H:i:s'),
                'quote_updated_at' => date('Y-m-d H:i:s'),
            ]);

            return $pickupStore;
        }

        $pickupStoreId = trim((string) ($pickupStore['store_id'] ?? ''));
        if ($pickupStoreId === '') {
            $result = [
                'errno' => 500,
                'errmsg' => 'store_id do Uber nao configurado.',
            ];
            $this->persistQuoteState($order, $this->buildUberStateForError($uberState, $result, 'error'), [
                'flow' => 'quote',
                'provider_key' => 'uber',
                'provider_label' => self::APP_CONTEXT,
                'quote_state' => 'error',
                'quote_message' => $result['errmsg'],
                'quote_requested_at' => date('Y-m-d H:i:s'),
                'quote_updated_at' => date('Y-m-d H:i:s'),
            ]);

            return $result;
        }

        $pickupContact = $this->resolvePickupContact($order) ?: $this->resolvePickupContact($sourceOrder);
        $dropoffContact = $this->resolveDropoffContact($order) ?: $this->resolveDropoffContact($sourceOrder);
        $dropoffPayload = $this->buildAddressPayload($dropoffAddress);
        if ($dropoffPayload === []) {
            $result = [
                'errno' => 422,
                'errmsg' => 'Endereco de entrega invalido para o Uber.',
            ];
            $this->persistQuoteState($order, $this->buildUberStateForError($uberState, $result, 'error'), [
                'flow' => 'quote',
                'provider_key' => 'uber',
                'provider_label' => self::APP_CONTEXT,
                'quote_state' => 'error',
                'quote_message' => $result['errmsg'],
                'quote_requested_at' => date('Y-m-d H:i:s'),
                'quote_updated_at' => date('Y-m-d H:i:s'),
            ]);

            return $result;
        }

        $pickupInstructions = $this->resolvePickupInstructions($order) ?: $this->resolvePickupInstructions($sourceOrder);
        $dropoffInstructions = $this->resolveDropoffInstructions($order) ?: $this->resolveDropoffInstructions($sourceOrder);
        $orderSummary = [
            'currency_code' => self::CURRENCY_CODE,
            'order_value' => $this->toMinorUnit((float) $sourceOrder->getPrice()),
        ];

        $estimateResponse = $this->requestDeliveryEstimate(
            $pickupStoreId,
            $dropoffPayload,
            $pickupInstructions,
            $orderSummary,
            $provider
        );
        if (($estimateResponse['status'] ?? 0) < 200 || ($estimateResponse['status'] ?? 0) >= 300) {
            $result = [
                'errno' => $estimateResponse['status'] ?? 500,
                'errmsg' => 'Falha ao obter estimate do Uber.',
                'estimate' => $estimateResponse,
            ];
            $this->persistQuoteState($order, $this->buildUberStateForError($uberState, $result, 'error'), [
                'flow' => 'quote',
                'provider_key' => 'uber',
                'provider_label' => self::APP_CONTEXT,
                'quote_state' => 'error',
                'quote_message' => $result['errmsg'],
                'quote_requested_at' => date('Y-m-d H:i:s'),
                'quote_updated_at' => date('Y-m-d H:i:s'),
                'quote_response' => is_array($estimateResponse['body'] ?? null) ? $estimateResponse['body'] : [],
            ]);

            return $result;
        }

        $estimateBody = is_array($estimateResponse['body']) ? $estimateResponse['body'] : [];
        $estimateId = $this->extractValue($estimateBody, ['estimate_id', 'estimateId']);
        if ($estimateId === '') {
            $result = [
                'errno' => 500,
                'errmsg' => 'Estimate do Uber nao retornou identificador.',
                'estimate' => $estimateResponse,
            ];
            $this->persistQuoteState($order, $this->buildUberStateForError($uberState, $result, 'error'), [
                'flow' => 'quote',
                'provider_key' => 'uber',
                'provider_label' => self::APP_CONTEXT,
                'quote_state' => 'error',
                'quote_message' => $result['errmsg'],
                'quote_requested_at' => date('Y-m-d H:i:s'),
                'quote_updated_at' => date('Y-m-d H:i:s'),
                'quote_response' => $estimateBody,
            ]);

            return $result;
        }

        $pickupAt = (int) ($this->extractPathValue($estimateBody, 'estimates.0.pickup_at') ?? 0);
        if ($pickupAt < 0) {
            $pickupAt = 0;
        }

        $quotePrice = $this->normalizeMoneyValue(
            $this->extractPathValue($estimateBody, 'estimates.0.delivery_fee.total')
        );
        if ($quotePrice === null || $quotePrice <= 0) {
            $result = [
                'errno' => 422,
                'errmsg' => 'Estimate do Uber nao retornou valor de cotacao valido.',
                'estimate' => $estimateResponse,
            ];
            $this->persistQuoteState($order, $this->buildUberStateForError($uberState, $result, 'error'), [
                'flow' => 'quote',
                'provider_key' => 'uber',
                'provider_label' => self::APP_CONTEXT,
                'quote_state' => 'error',
                'quote_message' => $result['errmsg'],
                'quote_requested_at' => date('Y-m-d H:i:s'),
                'quote_updated_at' => date('Y-m-d H:i:s'),
                'quote_response' => $estimateBody,
                'estimate_response' => $estimateBody,
            ]);

            return $result;
        }
        $eta = $this->formatUberEstimateEta($estimateBody);
        $quoteRequestedAt = date('Y-m-d H:i:s');
        $uberState = array_merge($uberState, [
            'provider_id' => $provider->getId(),
            'store_id' => $pickupStoreId,
            'external_store_id' => $pickupStore['external_store_id'] ?? null,
            'store_lookup_response' => $pickupStore['lookup_response'] ?? null,
            'estimate_id' => $estimateId,
            'quote_id' => $estimateId,
            'quote_state' => 'ready',
            'quote_requested_at' => $quoteRequestedAt,
            'quote_updated_at' => $quoteRequestedAt,
            'pickup_at' => $pickupAt,
            'price' => $quotePrice,
            'eta' => $eta,
            'tracking_url' => null,
            'delivery_id' => null,
            'delivery_response' => null,
            'quote_response' => $estimateBody,
            'estimate_response' => $estimateBody,
            'order_summary' => $orderSummary,
            'pickup' => [
                'address' => $pickupAddress,
                'location' => $pickupLocation,
                'contact' => $pickupContact ?? null,
                'instructions' => $pickupInstructions,
            ],
            'dropoff' => [
                'address' => $dropoffAddress,
                'payload' => $dropoffPayload,
                'contact' => $dropoffContact ?? null,
                'instructions' => $dropoffInstructions,
            ],
        ]);

        $this->persistQuoteState($order, $uberState, [
            'flow' => 'quote',
            'provider_key' => 'uber',
            'provider_label' => self::APP_CONTEXT,
            'quote_state' => 'ready',
            'quote_id' => $estimateId,
            'estimate_id' => $estimateId,
            'quote_requested_at' => $quoteRequestedAt,
            'quote_updated_at' => $quoteRequestedAt,
            'price' => $quotePrice,
            'eta' => $eta,
            'tracking_url' => null,
            'delivery_response' => null,
            'quote_response' => $estimateBody,
            'estimate_response' => $estimateBody,
            'pickup_at' => $pickupAt,
            'store_id' => $pickupStoreId,
            'pickup_address_id' => $pickupAddress->getId(),
            'dropoff_address_id' => $dropoffAddress->getId(),
            'main_order_id' => $order->getMainOrderId(),
            'source_order_id' => $sourceOrder->getId(),
            'order_summary' => $orderSummary,
        ]);

        return [
            'errno' => 0,
            'errmsg' => 'ok',
            'data' => [
                'order_id' => $order->getId(),
                'estimate_id' => $estimateId,
                'quote_id' => $estimateId,
                'price' => $quotePrice,
                'eta' => $eta,
                'estimate' => $estimateBody,
            ],
        ];
    }

    public function requestDeliveryFromQuote(Order $order): array
    {
        $provider = $order->getProvider();
        if (!$provider instanceof People) {
            return [
                'errno' => 400,
                'errmsg' => 'Pedido sem provider vinculado.',
            ];
        }

        $uberState = $this->getUberState($order);
        if (!empty($uberState['delivery_id']) || !empty($uberState['tracking_url']) || in_array((string) ($uberState['quote_state'] ?? ''), ['selected', 'requested'], true)) {
            return [
                'errno' => 0,
                'errmsg' => 'ok',
                'already_requested' => true,
                'data' => [
                    'order_id' => $order->getId(),
                    'delivery_id' => $uberState['delivery_id'] ?? null,
                    'tracking_url' => $uberState['tracking_url'] ?? null,
                    'status' => $uberState['status'] ?? 'requested',
                ],
            ];
        }

        $sourceOrder = $this->resolveQuoteSourceOrder($order);
        $pickupAddress = $this->resolvePickupAddress($order) ?: $this->resolvePickupAddress($sourceOrder);
        $dropoffAddress = $this->resolveDropoffAddress($order) ?: $this->resolveDropoffAddress($sourceOrder);

        if (!$pickupAddress instanceof Address) {
            return [
                'errno' => 422,
                'errmsg' => 'Pedido sem endereco de coleta valido.',
            ];
        }

        if (!$dropoffAddress instanceof Address) {
            return [
                'errno' => 422,
                'errmsg' => 'Pedido sem endereco de entrega valido.',
            ];
        }

        $dropoffContact = $this->resolveDropoffContact($order) ?: $this->resolveDropoffContact($sourceOrder);
        if ($dropoffContact === null) {
            return [
                'errno' => 422,
                'errmsg' => 'Pedido sem contato valido para a entrega.',
            ];
        }

        $pickupContact = $this->resolvePickupContact($order) ?: $this->resolvePickupContact($sourceOrder);
        $items = $this->buildOrderItemsPayload($sourceOrder);
        if ($items === []) {
            return [
                'errno' => 422,
                'errmsg' => 'Pedido sem itens para solicitar o motoboy.',
            ];
        }

        $pickupLocation = $this->buildLocationPayload($pickupAddress);
        $pickupStore = $this->resolvePickupStore($pickupLocation, $uberState, $provider);
        if (isset($pickupStore['errno']) && (int) $pickupStore['errno'] !== 0) {
            return $pickupStore;
        }

        $pickupStoreId = trim((string) ($pickupStore['store_id'] ?? ''));
        if ($pickupStoreId === '') {
            return [
                'errno' => 500,
                'errmsg' => 'store_id do Uber nao configurado.',
            ];
        }

        $estimateId = trim((string) ($uberState['estimate_id'] ?? $uberState['quote_id'] ?? ''));
        if ($estimateId === '') {
            return [
                'errno' => 422,
                'errmsg' => 'Cotacao do Uber nao encontrada.',
            ];
        }

        $dropoffPayload = $this->buildAddressPayload($dropoffAddress);
        if ($dropoffPayload === []) {
            return [
                'errno' => 422,
                'errmsg' => 'Endereco de entrega invalido para o Uber.',
            ];
        }

        $pickupInstructions = $this->resolvePickupInstructions($order) ?: $this->resolvePickupInstructions($sourceOrder);
        $dropoffInstructions = $this->resolveDropoffInstructions($order) ?: $this->resolveDropoffInstructions($sourceOrder);
        $orderSummary = is_array($uberState['order_summary'] ?? null)
            ? $uberState['order_summary']
            : [
                'currency_code' => self::CURRENCY_CODE,
                'order_value' => $this->toMinorUnit((float) $sourceOrder->getPrice()),
            ];
        $pickupAt = (int) ($uberState['pickup_at'] ?? 0);

        $deliveryResponse = $this->createDelivery(
            $order,
            $estimateId,
            $pickupStoreId,
            $pickupAt,
            $dropoffPayload,
            $pickupInstructions,
            $dropoffInstructions,
            $dropoffContact,
            $items,
            $orderSummary,
            $provider
        );
        if (($deliveryResponse['status'] ?? 0) < 200 || ($deliveryResponse['status'] ?? 0) >= 300) {
            $deliveryBody = is_array($deliveryResponse['body'] ?? null) ? $deliveryResponse['body'] : [];
            $this->persistQuoteState($order, array_merge($uberState, [
                'quote_state' => 'error',
                'quote_message' => 'Falha ao criar entrega no Uber.',
                'delivery_response' => $deliveryBody,
                'quote_updated_at' => date('Y-m-d H:i:s'),
            ]), [
                'flow' => 'quote',
                'provider_key' => 'uber',
                'provider_label' => self::APP_CONTEXT,
                'quote_state' => 'error',
                'quote_message' => 'Falha ao criar entrega no Uber.',
                'quote_updated_at' => date('Y-m-d H:i:s'),
                'delivery_response' => $deliveryBody,
                'quote_response' => $uberState['quote_response'] ?? [],
                'estimate_response' => $uberState['estimate_response'] ?? [],
            ]);

            return [
                'errno' => $deliveryResponse['status'] ?? 500,
                'errmsg' => 'Falha ao criar entrega no Uber.',
                'estimate' => $uberState['estimate_response'] ?? null,
                'delivery' => $deliveryResponse,
            ];
        }

        $deliveryBody = is_array($deliveryResponse['body']) ? $deliveryResponse['body'] : [];
        $deliveryId = $this->extractValue($deliveryBody, ['order_id', 'orderId', 'delivery_id', 'deliveryId', 'id']);
        $trackingUrl = $this->extractValue($deliveryBody, ['order_tracking_url', 'tracking_url', 'trackingUrl']);
        $deliveryStatus = $this->extractValue($deliveryBody, ['order_status', 'status']);
        $quoteRequestedAt = date('Y-m-d H:i:s');

        $uberState = array_merge($uberState, [
            'quote_state' => 'selected',
            'selected_at' => $quoteRequestedAt,
            'requested_at' => $quoteRequestedAt,
            'delivery_id' => $deliveryId !== '' ? $deliveryId : null,
            'tracking_url' => $trackingUrl !== '' ? $trackingUrl : null,
            'status' => $deliveryStatus !== '' ? $deliveryStatus : 'requested',
            'delivery_response' => $deliveryBody,
            'quote_updated_at' => $quoteRequestedAt,
        ]);
        $this->persistQuoteState($order, $uberState, [
            'flow' => 'quote',
            'provider_key' => 'uber',
            'provider_label' => self::APP_CONTEXT,
            'quote_state' => 'selected',
            'selected_at' => $quoteRequestedAt,
            'requested_at' => $quoteRequestedAt,
            'delivery_id' => $deliveryId !== '' ? $deliveryId : null,
            'tracking_url' => $trackingUrl !== '' ? $trackingUrl : null,
            'status' => $deliveryStatus !== '' ? $deliveryStatus : 'requested',
            'delivery_response' => $deliveryBody,
            'quote_response' => $uberState['quote_response'] ?? [],
            'estimate_response' => $uberState['estimate_response'] ?? [],
            'price' => $this->normalizeMoneyValue($this->extractPathValue($uberState['quote_response'] ?? [], 'estimates.0.delivery_fee.total')),
        ]);

        return [
            'errno' => 0,
            'errmsg' => 'ok',
            'data' => [
                'order_id' => $order->getId(),
                'delivery_id' => $deliveryId !== '' ? $deliveryId : null,
                'tracking_url' => $trackingUrl !== '' ? $trackingUrl : null,
                'status' => $deliveryStatus !== '' ? $deliveryStatus : 'requested',
                'delivery' => $deliveryBody,
            ],
        ];
    }

    public function buildAuthorizationUrl(string $redirectUri, string $state): array
    {
        $clientId = $this->resolveClientId();
        if ($clientId === '') {
            throw new \RuntimeException('Uber app_id nao configurado.');
        }

        $authorizationUrl = self::AUTHORIZATION_URL . '?' . http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'scope' => self::POS_PROVISIONING_SCOPE,
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return [
            'authorization_url' => $authorizationUrl,
            'url' => $authorizationUrl,
            'auth_url' => $authorizationUrl,
            'redirect_uri' => $redirectUri,
        ];
    }

    public function connectStoreViaOAuth(People $provider, string $authorizationCode, string $redirectUri): array
    {
        $authorizationCode = trim($authorizationCode);
        if ($authorizationCode === '') {
            return [
                'errno' => 422,
                'errmsg' => 'Codigo OAuth do Uber nao informado.',
            ];
        }

        $token = $this->exchangeAuthorizationCodeForToken($authorizationCode, $redirectUri);
        if ($token === null) {
            return [
                'errno' => 500,
                'errmsg' => 'Token OAuth do Uber indisponivel.',
            ];
        }

        $storesResponse = $this->listAuthorizedStores($token);
        if (($storesResponse['status'] ?? 0) < 200 || ($storesResponse['status'] ?? 0) >= 300) {
            return [
                'errno' => $storesResponse['status'] ?? 500,
                'errmsg' => 'Falha ao listar stores do Uber.',
                'stores' => $storesResponse,
            ];
        }

        $storesBody = is_array($storesResponse['body'] ?? null) ? $storesResponse['body'] : [];
        $stores = is_array($storesBody['stores'] ?? null) ? $storesBody['stores'] : [];
        $selectedStore = $this->selectAuthorizationStore($stores, $provider);
        if (!is_array($selectedStore)) {
            return [
                'errno' => 422,
                'errmsg' => 'Nenhuma store do Uber foi encontrada para a conta autorizada.',
                'stores' => $stores,
            ];
        }

        $storeId = trim((string) ($selectedStore['store_id'] ?? ''));
        if ($storeId === '') {
            return [
                'errno' => 422,
                'errmsg' => 'Store do Uber sem identificador valido.',
                'stores' => $stores,
            ];
        }

        $activationResponse = $this->activateStore($storeId, $token, $provider);
        if (($activationResponse['status'] ?? 0) < 200 || ($activationResponse['status'] ?? 0) >= 300) {
            return [
                'errno' => $activationResponse['status'] ?? 500,
                'errmsg' => 'Falha ao ativar a store do Uber.',
                'store_id' => $storeId,
                'activation' => $activationResponse,
            ];
        }

        $this->persistConfiguredStoreId($provider, $storeId);

        return [
            'errno' => 0,
            'errmsg' => 'ok',
            'data' => [
                'store_id' => $storeId,
                'store_name' => $selectedStore['name'] ?? null,
                'stores_count' => count($stores),
            ],
        ];
    }

    public function buildWebhookSignature(string $rawBody, string $signingKey): string
    {
        return hash_hmac('sha256', $rawBody, $signingKey);
    }

    private function decodeIntegrationPayload(Integration $integration): array
    {
        $payload = json_decode((string) $integration->getBody(), true);
        if (!is_array($payload) || $payload === []) {
            return [];
        }

        if (array_is_list($payload)) {
            foreach ($payload as $item) {
                if (is_array($item)) {
                    return $item;
                }
            }

            return [];
        }

        return $payload;
    }

    private function resolveOrderFromWebhookPayload(array $payload): ?Order
    {
        $localOrderId = $this->extractValue($payload, ['external_order_id', 'externalOrderId', 'meta.external_order_id']);
        if ($localOrderId === '') {
            $localOrderId = $this->extractValue($payload, ['order_id', 'orderId', 'meta.order_id']);
        }

        $normalizedOrderId = $this->requestPayloadService->normalizeOptionalNumericId($localOrderId);
        if (!$normalizedOrderId) {
            return null;
        }

        $order = $this->entityManager->getRepository(Order::class)->find($normalizedOrderId);

        return $order instanceof Order ? $order : null;
    }

    private function resolvePickupAddress(Order $order): ?Address
    {
        $address = $order->getAddressOrigin();
        if ($address instanceof Address) {
            return $address;
        }

        return $this->resolvePeoplePrimaryAddress($order->getProvider());
    }

    private function resolveDropoffAddress(Order $order): ?Address
    {
        $address = $order->getAddressDestination();

        return $address instanceof Address ? $address : null;
    }

    private function resolvePickupStore(?array $pickupLocation, array $uberState, ?People $provider = null): array
    {
        $configuredStoreId = $this->resolveConfiguredStoreId($provider);
        if ($configuredStoreId !== '') {
            return [
                'store_id' => $configuredStoreId,
                'external_store_id' => null,
                'lookup_response' => null,
                'source' => 'config',
            ];
        }

        $cachedStoreId = trim((string) ($uberState['store_id'] ?? ''));
        if ($cachedStoreId !== '') {
            return [
                'store_id' => $cachedStoreId,
                'external_store_id' => $uberState['external_store_id'] ?? null,
                'lookup_response' => $uberState['store_lookup_response'] ?? null,
                'source' => 'cache',
            ];
        }

        if (!$pickupLocation || ($pickupLocation['latitude'] ?? null) === null || ($pickupLocation['longitude'] ?? null) === null) {
            return [
                'errno' => 422,
                'errmsg' => 'Endereco de coleta sem coordenadas para localizar store do Uber.',
            ];
        }

        $response = $this->requestDeliverableStores($pickupLocation, $provider);
        if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300) {
            return [
                'errno' => $response['status'] ?? 500,
                'errmsg' => 'Falha ao localizar store do Uber.',
                'store' => $response,
            ];
        }

        $body = is_array($response['body']) ? $response['body'] : [];
        $store = $body['stores'][0] ?? null;
        if (!is_array($store)) {
            return [
                'errno' => 422,
                'errmsg' => 'Uber nao retornou nenhuma store para a coleta informada.',
                'store' => $response,
            ];
        }

        $storeId = trim((string) ($store['store_id'] ?? ''));
        if ($storeId === '') {
            return [
                'errno' => 422,
                'errmsg' => 'Store do Uber sem identificador valido.',
                'store' => $response,
            ];
        }

        return [
            'store_id' => $storeId,
            'external_store_id' => $store['external_store_id'] ?? null,
            'lookup_response' => $body,
            'source' => 'lookup',
        ];
    }

    private function requestDeliverableStores(array $pickupLocation, ?People $provider = null): array
    {
        $token = $this->getAccessToken($provider);
        if ($token === null) {
            return [
                'status' => 500,
                'body' => ['message' => 'Token OAuth do Uber indisponivel.'],
            ];
        }

        $latitude = $pickupLocation['latitude'] ?? null;
        $longitude = $pickupLocation['longitude'] ?? null;
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return [
                'status' => 422,
                'body' => ['message' => 'Coordenadas invalidas para localizar store.'],
            ];
        }

        try {
            $response = $this->httpClient->request('GET', self::API_BASE_URL . '/v1/eats/deliveries/stores', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ],
                'query' => [
                    'latitude' => (float) $latitude,
                    'longitude' => (float) $longitude,
                    'pickup_at' => 0,
                ],
                'timeout' => 20,
                'max_duration' => 30,
            ]);

            return [
                'status' => $response->getStatusCode(),
                'body' => $this->decodeResponseBody((string) $response->getContent(false)),
            ];
        } catch (\Throwable $exception) {
            self::$logger?->error('Uber store lookup failed', [
                'error' => $exception->getMessage(),
            ]);

            return [
                'status' => 500,
                'body' => ['message' => $exception->getMessage()],
            ];
        }
    }

    private function requestDeliveryEstimate(
        string $storeId,
        array $dropoffAddress,
        ?string $pickupInstructions = null,
        ?array $orderSummary = null,
        ?People $provider = null
    ): array {
        $token = $this->getAccessToken($provider);
        if ($token === null) {
            return [
                'status' => 500,
                'body' => ['message' => 'Token OAuth do Uber indisponivel.'],
            ];
        }

        $payload = [
            'pickup' => [
                'store_id' => $storeId,
                'instructions' => $pickupInstructions,
            ],
            'dropoff_address' => $dropoffAddress,
            'pickup_times' => [0],
        ];

        if (is_array($orderSummary)) {
            $payload['order_summary'] = $orderSummary;
        }

        try {
            $response = $this->httpClient->request('POST', self::API_BASE_URL . '/v1/eats/deliveries/estimates', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => $this->cleanupPayload($payload),
                'timeout' => 20,
                'max_duration' => 30,
            ]);

            return [
                'status' => $response->getStatusCode(),
                'body' => $this->decodeResponseBody((string) $response->getContent(false)),
            ];
        } catch (\Throwable $exception) {
            self::$logger?->error('Uber estimate request failed', [
                'error' => $exception->getMessage(),
            ]);

            return [
                'status' => 500,
                'body' => ['message' => $exception->getMessage()],
            ];
        }
    }

    private function createDelivery(
        Order $order,
        string $estimateId,
        string $storeId,
        int $pickupAt,
        array $dropoffAddress,
        ?string $pickupInstructions,
        ?string $dropoffInstructions,
        array $dropoffContact,
        array $items,
        array $orderSummary,
        ?People $provider = null
    ): array {
        $token = $this->getAccessToken($provider);
        if ($token === null) {
            return [
                'status' => 500,
                'body' => ['message' => 'Token OAuth do Uber indisponivel.'],
            ];
        }

        $client = $order->getClient();
        $externalUserId = $client instanceof People
            ? (string) $client->getId()
            : (string) $order->getId();

        $payload = [
            'estimate_id' => $estimateId,
            'pickup_at' => $pickupAt,
            'pickup' => [
                'store_id' => $storeId,
                'instructions' => $pickupInstructions,
            ],
            'dropoff' => [
                'address' => $dropoffAddress,
                'contact' => $dropoffContact,
                'instructions' => $dropoffInstructions,
                'type' => 'DOOR',
            ],
            'external_order_id' => (string) $order->getId(),
            'external_user_id' => $externalUserId,
            'order_items' => $items,
            'order_summary' => $orderSummary,
        ];

        try {
            $response = $this->httpClient->request('POST', self::API_BASE_URL . '/v1/eats/deliveries/orders', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => $this->cleanupPayload($payload),
                'timeout' => 20,
                'max_duration' => 30,
            ]);

            return [
                'status' => $response->getStatusCode(),
                'body' => $this->decodeResponseBody((string) $response->getContent(false)),
            ];
        } catch (\Throwable $exception) {
            self::$logger?->error('Uber delivery request failed', [
                'order_id' => $order->getId(),
                'error' => $exception->getMessage(),
            ]);

            return [
                'status' => 500,
                'body' => ['message' => $exception->getMessage()],
            ];
        }
    }

    private function getAccessToken(?People $provider = null): ?string
    {
        $clientId = $this->resolveClientId($provider);
        $clientSecret = $this->resolveClientSecret($provider);

        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        $cacheKey = $clientId . '|' . $clientSecret;
        return $this->requestOAuthToken([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'client_credentials',
            'scope' => self::TOKEN_SCOPE,
        ], $cacheKey);
    }

    private function resolveClientId(?People $provider = null): string
    {
        return $this->resolveEnvironmentValue([
            'OAUTH_UBER_APP_ID',
            'UBER_CLIENT_ID',
        ]);
    }

    private function resolveClientSecret(?People $provider = null): string
    {
        return $this->resolveEnvironmentValue([
            'OAUTH_UBER_CLIENT_SECRET',
            'UBER_CLIENT_SECRET',
        ]);
    }

    private function resolveConfiguredStoreId(?People $provider = null): string
    {
        if (!$provider instanceof People) {
            return '';
        }

        $value = trim((string) ($this->configService->getConfig($provider, 'OAUTH_UBER_STORE_ID') ?? ''));

        return $value !== '' ? $value : '';
    }

    private function resolveEnvironmentValue(array $environmentKeys): string
    {
        foreach ($environmentKeys as $environmentKey) {
            $value = trim((string) (
                $_ENV[$environmentKey]
                ?? $_SERVER[$environmentKey]
                ?? getenv($environmentKey)
                ?? ''
            ));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function requestOAuthToken(array $formFields, ?string $cacheKey = null): ?string
    {
        if ($cacheKey !== null) {
            $cached = self::$authTokenCache[$cacheKey] ?? null;
            $expiresAt = is_array($cached) ? (int) ($cached['expires_at'] ?? 0) : 0;
            if (is_array($cached) && !empty($cached['access_token']) && $expiresAt > (time() + 60)) {
                return (string) $cached['access_token'];
            }
        }

        try {
            $response = $this->httpClient->request('POST', self::AUTH_URL, [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept' => 'application/json',
                ],
                'body' => http_build_query($formFields, '', '&', PHP_QUERY_RFC3986),
                'timeout' => 20,
                'max_duration' => 30,
            ]);

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                return null;
            }

            $body = $this->decodeResponseBody((string) $response->getContent(false));
            $token = $this->extractValue($body, ['access_token']);
            $expiresIn = (int) ($body['expires_in'] ?? 0);

            if ($token === '') {
                return null;
            }

            if ($cacheKey !== null) {
                self::$authTokenCache[$cacheKey] = [
                    'access_token' => $token,
                    'expires_at' => time() + max(60, $expiresIn),
                ];
            }

            return $token;
        } catch (\Throwable $exception) {
            self::$logger?->error('Uber access token request failed', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function exchangeAuthorizationCodeForToken(string $authorizationCode, string $redirectUri): ?string
    {
        $clientId = $this->resolveClientId();
        $clientSecret = $this->resolveClientSecret();

        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        return $this->requestOAuthToken([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
            'code' => $authorizationCode,
        ]);
    }

    private function listAuthorizedStores(string $token): array
    {
        $stores = [];
        $startKey = null;
        $pageCount = 0;

        try {
            do {
                $query = ['limit' => 100];
                if (is_string($startKey) && trim($startKey) !== '') {
                    $query['start_key'] = $startKey;
                }

                $response = $this->httpClient->request('GET', self::STORE_LIST_URL, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Accept' => 'application/json',
                        'Accept-Encoding' => 'gzip',
                    ],
                    'query' => $query,
                    'timeout' => 20,
                    'max_duration' => 30,
                ]);

                $statusCode = $response->getStatusCode();
                if ($statusCode < 200 || $statusCode >= 300) {
                    return [
                        'status' => $statusCode,
                        'body' => $this->decodeResponseBody((string) $response->getContent(false)),
                    ];
                }

                $body = $this->decodeResponseBody((string) $response->getContent(false));
                $pageStores = is_array($body['stores'] ?? null) ? $body['stores'] : [];
                $stores = array_merge($stores, $pageStores);
                $startKey = trim((string) ($body['next_key'] ?? ''));
                $pageCount++;
            } while ($startKey !== '' && $pageCount < 10 && count($stores) < 1000);
        } catch (\Throwable $exception) {
            self::$logger?->error('Uber store listing failed', [
                'error' => $exception->getMessage(),
            ]);

            return [
                'status' => 500,
                'body' => ['message' => $exception->getMessage()],
            ];
        }

        return [
            'status' => 200,
            'body' => [
                'stores' => $stores,
                'next_key' => $startKey,
            ],
        ];
    }

    private function selectAuthorizationStore(array $stores, ?People $provider = null): ?array
    {
        $normalizedStores = array_values(array_filter($stores, static function (mixed $store): bool {
            return is_array($store) && trim((string) ($store['store_id'] ?? '')) !== '';
        }));

        if ($normalizedStores === []) {
            return null;
        }

        $providerLocation = $this->resolveAuthorizationProviderLocation($provider);
        if ($providerLocation !== null) {
            $bestStore = null;
            $bestDistance = null;

            foreach ($normalizedStores as $store) {
                $storeLocation = is_array($store['location'] ?? null) ? $store['location'] : [];
                $distance = $this->calculateDistanceKm(
                    $providerLocation['latitude'],
                    $providerLocation['longitude'],
                    $this->normalizeCoordinate($storeLocation['latitude'] ?? null),
                    $this->normalizeCoordinate($storeLocation['longitude'] ?? null)
                );

                if ($distance === null) {
                    continue;
                }

                if ($bestDistance === null || $distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestStore = $store;
                }
            }

            if (is_array($bestStore)) {
                return $bestStore;
            }
        }

        foreach ($normalizedStores as $store) {
            $status = strtolower(trim((string) ($store['status'] ?? '')));
            if ($status === 'active') {
                return $store;
            }
        }

        return $normalizedStores[0] ?? null;
    }

    private function resolveAuthorizationProviderLocation(?People $provider = null): ?array
    {
        $address = $this->resolvePeoplePrimaryAddress($provider);
        if (!$address instanceof Address) {
            return null;
        }

        return $this->resolvePickupAddressLocation($address);
    }

    private function activateStore(string $storeId, string $token, ?People $provider = null): array
    {
        $payload = [];
        if ($provider instanceof People) {
            $payload['integrator_store_id'] = (string) $provider->getId();
        }

        try {
            $response = $this->httpClient->request('POST', self::STORE_LIST_URL . '/' . rawurlencode($storeId) . '/pos_data', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => $this->cleanupPayload($payload),
                'timeout' => 20,
                'max_duration' => 30,
            ]);

            return [
                'status' => $response->getStatusCode(),
                'body' => $this->decodeResponseBody((string) $response->getContent(false)),
            ];
        } catch (\Throwable $exception) {
            self::$logger?->error('Uber store activation failed', [
                'store_id' => $storeId,
                'error' => $exception->getMessage(),
            ]);

            return [
                'status' => 500,
                'body' => ['message' => $exception->getMessage()],
            ];
        }
    }

    private function persistConfiguredStoreId(People $provider, string $storeId): void
    {
        $config = $this->configService->discoveryConfig($provider, 'OAUTH_UBER_STORE_ID');
        if (!$config) {
            return;
        }

        $config->setConfigValue($storeId);
        $this->entityManager->persist($config);
        $this->entityManager->flush();
    }

    private function calculateDistanceKm(
        ?float $lat1,
        ?float $lon1,
        ?float $lat2,
        ?float $lon2
    ): ?float {
        if ($lat1 === null || $lon1 === null || $lat2 === null || $lon2 === null) {
            return null;
        }

        $earthRadiusKm = 6371.0;
        $latFrom = deg2rad($lat1);
        $lonFrom = deg2rad($lon1);
        $latTo = deg2rad($lat2);
        $lonTo = deg2rad($lon2);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $a = sin($latDelta / 2) ** 2 + cos($latFrom) * cos($latTo) * sin($lonDelta / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }

    private function resolvePickupAddressLocation(Address $address): ?array
    {
        $latitude = $this->normalizeCoordinate($address->getLatitude());
        $longitude = $this->normalizeCoordinate($address->getLongitude());

        if ($latitude === null || $longitude === null) {
            return null;
        }

        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];
    }

    private function resolvePeoplePrimaryAddress(?People $people): ?Address
    {
        if (!$people instanceof People) {
            return null;
        }

        $addresses = $people->getAddress();
        foreach ($addresses as $address) {
            if ($address instanceof Address) {
                return $address;
            }
        }

        return null;
    }

    private function resolvePickupContact(Order $order): ?array
    {
        $person = $order->getRetrieveContact();
        if (!$person instanceof People) {
            $person = $order->getProvider();
        }

        if (!$person instanceof People) {
            return null;
        }

        return $this->buildContactPayload($person);
    }

    private function resolveDropoffContact(Order $order): ?array
    {
        $person = $order->getDeliveryContact();
        if (!$person instanceof People) {
            $person = $order->getClient();
        }

        if (!$person instanceof People) {
            return null;
        }

        return $this->buildContactPayload($person);
    }

    private function buildContactPayload(People $person): ?array
    {
        $displayName = $this->resolvePeopleDisplayName($person);
        $phone = $this->resolvePeoplePhone($person);
        if ($displayName === '' || $phone === null) {
            return null;
        }

        [$firstName, $lastName] = $this->splitName($displayName);
        $payload = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $phone,
        ];

        $email = $this->resolvePeopleEmail($person);
        if ($email !== null) {
            $payload['email'] = $email;
        }

        return $payload;
    }

    private function splitName(string $displayName): array
    {
        $parts = preg_split('/\s+/', trim($displayName));
        if (!is_array($parts) || $parts === []) {
            return ['Uber', 'Delivery'];
        }

        $parts = array_values(array_filter($parts, static fn (string $part): bool => trim($part) !== ''));
        if ($parts === []) {
            return ['Uber', 'Delivery'];
        }

        $firstName = array_shift($parts);
        $lastName = trim(implode(' ', $parts));
        if ($lastName === '') {
            $lastName = $firstName;
        }

        return [
            $firstName !== '' ? $firstName : 'Uber',
            $lastName !== '' ? $lastName : 'Delivery',
        ];
    }

    private function resolvePeopleDisplayName(People $people): string
    {
        $name = trim((string) $people->getName());
        $alias = trim((string) $people->getAlias());

        if ($people->isPerson()) {
            return trim($name . ' ' . $alias);
        }

        return $alias !== '' ? $alias : $name;
    }

    private function resolvePeoplePhone(People $people): ?string
    {
        $phone = $people->getPhone()->first();
        if (!$phone instanceof Phone) {
            return null;
        }

        $ddi = preg_replace('/\D+/', '', (string) $phone->getDdi());
        $ddd = preg_replace('/\D+/', '', (string) $phone->getDdd());
        $number = preg_replace('/\D+/', '', (string) $phone->getPhone());

        if ($number === '' || $ddd === '') {
            return null;
        }

        if ($ddi === '') {
            $ddi = '55';
        }

        return '+' . trim($ddi . $ddd . $number);
    }

    private function resolvePeopleEmail(People $people): ?string
    {
        $emails = $people->getEmail();
        foreach ($emails as $email) {
            if ($email instanceof Email) {
                $value = trim((string) $email->getEmail());
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function resolvePickupInstructions(Order $order): ?string
    {
        $comments = trim((string) $order->getComments());
        if ($comments === '') {
            return null;
        }

        return $comments;
    }

    private function resolveDropoffInstructions(Order $order): ?string
    {
        $comments = trim((string) $order->getComments());
        if ($comments === '') {
            return null;
        }

        return $comments;
    }

    private function buildOrderItemsPayload(Order $order): array
    {
        $items = [];
        foreach ($order->getOrderProducts() as $orderProduct) {
            if (!$orderProduct instanceof OrderProduct) {
                continue;
            }

            if ($orderProduct->getOrderProduct() instanceof OrderProduct) {
                continue;
            }

            $product = $orderProduct->getProduct();
            $name = trim((string) ($product?->getProduct() ?? ''));
            if ($name === '') {
                $name = 'Item #' . ($orderProduct->getId() ?? '0');
            }

            $items[] = [
                'name' => $name,
                'description' => trim((string) ($product?->getDescription() ?? $orderProduct->getComment() ?? '')),
                'external_id' => (string) ($product?->getId() ?? $orderProduct->getId() ?? ''),
                'quantity' => max(1, (int) round((float) $orderProduct->getQuantity())),
                'price' => $this->toMinorUnit((float) $orderProduct->getPrice()),
                'currency_code' => self::CURRENCY_CODE,
                'item_type' => 'food',
            ];
        }

        return $items;
    }

    private function buildAddressPayload(Address $address): array
    {
        $formattedAddress = $this->buildFormattedAddress($address);
        $payload = [];

        if ($formattedAddress !== '') {
            $payload['formatted_address'] = $formattedAddress;
        }

        $aptFloorSuite = trim((string) $address->getComplement());
        if ($aptFloorSuite !== '') {
            $payload['apt_floor_suite'] = $aptFloorSuite;
        }

        $location = $this->resolvePickupAddressLocation($address);
        if (is_array($location)) {
            $payload['location'] = $location;
        }

        return $payload;
    }

    private function buildFormattedAddress(Address $address): string
    {
        $street = $address->getStreet();
        $district = $street?->getDistrict();
        $city = $district?->getCity();
        $state = $city?->getState();
        $cep = $street?->getCep();

        return $this->joinText([
            $this->joinText([
                $street?->getStreet(),
                $address->getNumber(),
            ], ', '),
            $district?->getDistrict(),
            $city?->getCity(),
            $state?->getUf() ?: $state?->getState(),
            $cep?->getCep(),
        ], ' - ');
    }

    private function buildLocationPayload(Address $address): ?array
    {
        return $this->resolvePickupAddressLocation($address);
    }

    private function cleanupPayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->cleanupPayload($value);
                if ($payload[$key] === []) {
                    unset($payload[$key]);
                }

                continue;
            }

            if ($value === null || $value === '') {
                unset($payload[$key]);
            }
        }

        return $payload;
    }

    private function decodeResponseBody(string $rawBody): array
    {
        $decoded = json_decode($rawBody, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function getUberState(Order $order): array
    {
        $otherInformations = $order->getOtherInformations(true);
        if (is_object($otherInformations)) {
            $otherInformations = (array) $otherInformations;
        }

        if (!is_array($otherInformations)) {
            $otherInformations = [];
        }

        $uber = $otherInformations[self::APP_CONTEXT] ?? [];

        return is_array($uber) ? $uber : [];
    }

    private function storeUberState(Order $order, array $state): void
    {
        $otherInformations = $order->getOtherInformations(true);
        if (is_object($otherInformations)) {
            $otherInformations = (array) $otherInformations;
        }

        if (!is_array($otherInformations)) {
            $otherInformations = [];
        }

        $otherInformations[self::APP_CONTEXT] = $state;
        $order->setOtherInformations($otherInformations);
        $this->entityManager->persist($order);
        $this->entityManager->flush();
    }

    private function persistQuoteState(Order $order, array $uberState, array $logisticsState): void
    {
        $otherInformations = $order->getOtherInformations(true);
        if (is_object($otherInformations)) {
            $otherInformations = (array) $otherInformations;
        }

        if (!is_array($otherInformations)) {
            $otherInformations = [];
        }

        $otherInformations[self::APP_CONTEXT] = $uberState;
        $otherInformations['logistics'] = $logisticsState;

        $order->setOtherInformations($otherInformations);
        $order->setAlterDate(new \DateTime('now'));
        if (isset($logisticsState['price']) && is_numeric($logisticsState['price'])) {
            $order->setPrice((float) $logisticsState['price']);
        }

        $this->entityManager->persist($order);
        $this->entityManager->flush();
    }

    private function resolveQuoteSourceOrder(Order $order): Order
    {
        $mainOrder = $order->getMainOrder();
        if ($mainOrder instanceof Order) {
            return $mainOrder;
        }

        $mainOrderId = $order->getMainOrderId();
        if ($mainOrderId) {
            $resolved = $this->entityManager->getRepository(Order::class)->find((int) $mainOrderId);
            if ($resolved instanceof Order) {
                return $resolved;
            }
        }

        return $order;
    }

    private function buildUberStateForError(array $uberState, array $response, string $quoteState = 'error'): array
    {
        return array_merge($uberState, [
            'quote_state' => $quoteState,
            'last_error_code' => $response['errno'] ?? null,
            'last_error_message' => $response['errmsg'] ?? null,
            'quote_message' => $response['errmsg'] ?? ($uberState['quote_message'] ?? null),
            'quote_updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function normalizeMoneyValue(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return round(((float) $value) / 100, 2);
    }

    private function formatUberEstimateEta(array $estimateBody): ?string
    {
        $etd = (int) ($this->extractPathValue($estimateBody, 'estimates.0.etd') ?? 0);
        if ($etd <= 0) {
            return null;
        }

        $minutes = (int) round(max(0, ($etd - (int) round(microtime(true) * 1000)) / 60000));
        if ($minutes <= 0) {
            return null;
        }

        return sprintf('%d min', $minutes);
    }

    private function extractValue(array $payload, array $paths): string
    {
        foreach ($paths as $path) {
            $value = $this->extractPathValue($payload, $path);
            if ($value === null) {
                continue;
            }

            $normalized = trim((string) $value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    private function extractPathValue(array $payload, string $path): mixed
    {
        $segments = explode('.', $path);
        $current = $payload;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    private function joinText(array $parts, string $separator = ' '): string
    {
        $filtered = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $parts
        ), static fn (string $value): bool => $value !== ''));

        return implode($separator, $filtered);
    }

    private function normalizeCoordinate(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function toMinorUnit(float $value): int
    {
        return (int) round($value * 100);
    }
}
