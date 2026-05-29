<?php

namespace ControleOnline\Service\Marketplace;

use ControleOnline\Service\AddressService;
use ControleOnline\Service\Client\IfoodClient;
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
use ControleOnline\Service\Marketplace\AbstractMarketplaceService;
use ControleOnline\Service\iFoodService;
use ControleOnline\Service\Marketplace\IfoodStoreOperationsService;
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

class IfoodOrderOperationsService extends AbstractMarketplaceService
{
    private const APP_CONTEXT = Order::APP_IFOOD;

    private ?iFoodService $iFoodService = null;

    protected function getMarketplaceApp(): string
    {
        return self::APP_CONTEXT;
    }

    #[Required]
    public function setIfoodService(iFoodService $iFoodService): void
    {
        $this->iFoodService = $iFoodService;
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

    private function decodeOrderOtherInformationsValue(mixed $value): array
    {
        $service = $this->iFoodService;
        $decoded = $service?->decodeOrderOtherInformationsValue($value);

        return is_array($decoded) ? $decoded : [];
    }

    private function getDecodedOrderOtherInformations(Order $order): array
    {
        $service = $this->iFoodService;
        $decoded = $service?->getDecodedOrderOtherInformations($order);

        return is_array($decoded) ? $decoded : [];
    }

    private function findStoredIfoodOrderDetails(Order $order): array
    {
        $service = $this->iFoodService;
        $details = $service?->findStoredIfoodOrderDetails($order);

        return is_array($details) ? $details : [];
    }

    private function extractOrderBenefitSnapshot(array $orderPayload): array
    {
        $service = $this->iFoodService;
        $snapshot = $service?->extractOrderBenefitSnapshot($orderPayload);

        return is_array($snapshot) ? $snapshot : [];
    }

    private function extractAdditionalFeeSnapshot(array $additionalFees): array
    {
        $service = $this->iFoodService;
        $snapshot = $service?->extractAdditionalFeeSnapshot($additionalFees);

        return is_array($snapshot) ? $snapshot : [];
    }

    private function extractOrderRemarkFromPayload(array $orderPayload): string
    {
        $service = $this->iFoodService;
        $remark = $service?->extractOrderRemarkFromPayload($orderPayload);

        return is_string($remark) ? $remark : '';
    }

    private function isMerchantDeliveryContext(string $deliveredBy, string $deliveryMode): bool
    {
        $service = $this->iFoodService;
        $resolved = $service?->isMerchantDeliveryContext($deliveredBy, $deliveryMode);

        return (bool) $resolved;
    }

    public function getStoredOrderIntegrationState(Order $order): array
    {
        $this->init();

        $orderId = (int) $order->getId();
        $state = [
            'ifood_id' => $this->getIfoodExtraDataValue('Order', $orderId, 'id'),
            'ifood_code' => $this->getIfoodExtraDataValue('Order', $orderId, 'code'),
            'merchant_id' => $this->getIfoodExtraDataValue('Order', $orderId, 'merchant_id'),
            'remote_order_state' => $this->getIfoodExtraDataValue('Order', $orderId, 'remote_order_state'),
            'order_type' => $this->getIfoodExtraDataValue('Order', $orderId, 'order_type'),
            'order_timing' => $this->getIfoodExtraDataValue('Order', $orderId, 'order_timing'),
            'delivered_by' => $this->getIfoodExtraDataValue('Order', $orderId, 'delivered_by'),
            'delivery_mode' => $this->getIfoodExtraDataValue('Order', $orderId, 'delivery_mode'),
            'takeout_mode' => $this->getIfoodExtraDataValue('Order', $orderId, 'takeout_mode'),
            'takeout_date_time' => $this->getIfoodExtraDataValue('Order', $orderId, 'takeout_date_time'),
            'dine_in_date_time' => $this->getIfoodExtraDataValue('Order', $orderId, 'dine_in_date_time'),
            'pickup_code' => $this->getIfoodExtraDataValue('Order', $orderId, 'pickup_code'),
            'pickup_area_code' => $this->getIfoodExtraDataValue('Order', $orderId, 'pickup_area_code'),
            'pickup_area_type' => $this->getIfoodExtraDataValue('Order', $orderId, 'pickup_area_type'),
            'handover_code' => $this->getIfoodExtraDataValue('Order', $orderId, 'handover_code'),
            'locator' => $this->getIfoodExtraDataValue('Order', $orderId, 'locator'),
            'handover_page_url' => $this->getIfoodExtraDataValue('Order', $orderId, 'handover_page_url'),
            'handover_confirmation_url' => $this->getIfoodExtraDataValue('Order', $orderId, 'handover_confirmation_url'),
            'virtual_phone' => $this->getIfoodExtraDataValue('Order', $orderId, 'virtual_phone'),
            'customer_name' => $this->getIfoodExtraDataValue('Order', $orderId, 'customer_name'),
            'customer_phone' => $this->getIfoodExtraDataValue('Order', $orderId, 'customer_phone'),
            'customer_document' => $this->getIfoodExtraDataValue('Order', $orderId, 'customer_document'),
            'customer_document_type' => $this->getIfoodExtraDataValue('Order', $orderId, 'customer_document_type'),
            'tax_document_requested' => $this->getIfoodExtraDataValue('Order', $orderId, 'tax_document_requested'),
            'address_display' => $this->getIfoodExtraDataValue('Order', $orderId, 'address_display'),
            'address_poi_address' => $this->getIfoodExtraDataValue('Order', $orderId, 'address_poi_address'),
            'address_street_name' => $this->getIfoodExtraDataValue('Order', $orderId, 'address_street_name'),
            'address_street_number' => $this->getIfoodExtraDataValue('Order', $orderId, 'address_street_number'),
            'address_district' => $this->getIfoodExtraDataValue('Order', $orderId, 'address_district'),
            'address_city' => $this->getIfoodExtraDataValue('Order', $orderId, 'address_city'),
            'address_state' => $this->getIfoodExtraDataValue('Order', $orderId, 'address_state'),
            'address_postal_code' => $this->getIfoodExtraDataValue('Order', $orderId, 'address_postal_code'),
            'address_reference' => $this->getIfoodExtraDataValue('Order', $orderId, 'address_reference'),
            'address_complement' => $this->getIfoodExtraDataValue('Order', $orderId, 'address_complement'),
            'remark' => $this->getIfoodExtraDataValue('Order', $orderId, 'remark'),
            'payment_liability' => $this->getIfoodExtraDataValue('Order', $orderId, 'payment_liability'),
            'payment_wallet_name' => $this->getIfoodExtraDataValue('Order', $orderId, 'payment_wallet_name'),
            'voucher_code' => $this->getIfoodExtraDataValue('Order', $orderId, 'voucher_code'),
            'discount_total' => $this->getIfoodExtraDataValue('Order', $orderId, 'discount_total'),
            'ifood_subsidy' => $this->getIfoodExtraDataValue('Order', $orderId, 'ifood_subsidy'),
            'merchant_subsidy' => $this->getIfoodExtraDataValue('Order', $orderId, 'merchant_subsidy'),
            'scheduled_start' => $this->getIfoodExtraDataValue('Order', $orderId, 'scheduled_start'),
            'scheduled_end' => $this->getIfoodExtraDataValue('Order', $orderId, 'scheduled_end'),
            'delivery_date_time' => $this->getIfoodExtraDataValue('Order', $orderId, 'delivery_date_time'),
            'preparation_start' => $this->getIfoodExtraDataValue('Order', $orderId, 'preparation_start'),
            'is_scheduled' => $this->getIfoodExtraDataValue('Order', $orderId, 'is_scheduled'),
            'handshake_event_type' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_event_type'),
            'handshake_dispute_id' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_dispute_id'),
            'handshake_created_at' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_created_at'),
            'handshake_action' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_action'),
            'handshake_type' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_type'),
            'handshake_group' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_group'),
            'handshake_message' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_message'),
            'handshake_expires_at' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_expires_at'),
            'handshake_timeout_action' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_timeout_action'),
            'handshake_accept_reasons' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_accept_reasons'),
            'handshake_alternatives_json' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_alternatives_json'),
            'handshake_alternative_id' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_alternative_id'),
            'handshake_alternative_type' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_alternative_type'),
            'handshake_alternative_amount_value' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_alternative_amount_value'),
            'handshake_alternative_amount_currency' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_alternative_amount_currency'),
            'handshake_alternative_time_minutes' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_alternative_time_minutes'),
            'handshake_alternative_reason' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_alternative_reason'),
            'handshake_evidences_json' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_evidences_json'),
            'handshake_evidence_url' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_evidence_url'),
            'handshake_evidence_content_type' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_evidence_content_type'),
            'handshake_selected_alternative_json' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_selected_alternative_json'),
            'handshake_settlement_status' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_settlement_status'),
            'handshake_settlement_reason' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_settlement_reason'),
            'last_event_type' => $this->getIfoodExtraDataValue('Order', $orderId, 'last_event_type'),
            'last_event_at' => $this->getIfoodExtraDataValue('Order', $orderId, 'last_event_at'),
            'last_action' => $this->getIfoodExtraDataValue('Order', $orderId, 'last_action'),
            'last_action_at' => $this->getIfoodExtraDataValue('Order', $orderId, 'last_action_at'),
            'last_action_errno' => $this->getIfoodExtraDataValue('Order', $orderId, 'last_action_errno'),
            'last_action_message' => $this->getIfoodExtraDataValue('Order', $orderId, 'last_action_message'),
            'cancel_reason' => $this->getIfoodExtraDataValue('Order', $orderId, 'cancel_reason'),
            'webhook_event_id' => $this->getIfoodExtraDataValue('Order', $orderId, 'webhook_event_id'),
            'webhook_event_type' => $this->getIfoodExtraDataValue('Order', $orderId, 'webhook_event_type'),
            'webhook_event_at' => $this->getIfoodExtraDataValue('Order', $orderId, 'webhook_event_at'),
            'webhook_received_at' => $this->getIfoodExtraDataValue('Order', $orderId, 'webhook_received_at'),
            'webhook_processed_at' => $this->getIfoodExtraDataValue('Order', $orderId, 'webhook_processed_at'),
            'last_integration_id' => $this->getIfoodExtraDataValue('Order', $orderId, 'last_integration_id'),
        ];

        $otherInformations = $this->getDecodedOrderOtherInformations($order);
        $context = $this->decodeOrderOtherInformationsValue($otherInformations[self::$app] ?? null);
        foreach ($context as $fieldName => $fieldValue) {
            if (($state[$fieldName] ?? '') !== '' || $fieldValue === null || $fieldValue === '') {
                continue;
            }

            $state[$fieldName] = $fieldValue;
        }

        $storedRemoteId = $this->normalizeString($state['ifood_id'] ?? null);
        $storedDisplayId = $this->normalizeString($state['ifood_code'] ?? null);
        if ($storedDisplayId !== '' && ($storedDisplayId === $storedRemoteId || str_contains($storedDisplayId, '-'))) {
            $state['ifood_code'] = '';
        }

        $latestEventType = $this->normalizeString($otherInformations['latest_event_type'] ?? null);
        if ($latestEventType !== '' && is_array($otherInformations[$latestEventType] ?? null)) {
            $payload = $otherInformations[$latestEventType];
            $state['last_event_type'] = $state['last_event_type'] ?: $latestEventType;
            $state['last_event_at'] = $state['last_event_at'] ?: $this->extractEventTimestamp($payload);
            $state['ifood_id'] = $state['ifood_id'] ?: $this->normalizeString($payload['orderId'] ?? null);
            $state['merchant_id'] = $state['merchant_id'] ?: $this->normalizeString($payload['merchantId'] ?? null);
            $state['remote_order_state'] = $state['remote_order_state'] ?: $this->resolveRemoteOrderStateByEventCode($latestEventType);

            if (is_array($payload['order'] ?? null)) {
                $snapshot = $this->extractOrderDetailSnapshot($payload['order']);
                $state['ifood_code'] = $state['ifood_code'] ?: $this->normalizeString($snapshot['code'] ?? null);
                foreach ($snapshot as $fieldName => $fieldValue) {
                    if (($state[$fieldName] ?? '') === '' && $fieldValue !== '') {
                        $state[$fieldName] = $fieldValue;
                    }
                }
            }
        }

        $storedOrderDetails = $this->findStoredIfoodOrderDetails($order);
        if ($storedOrderDetails !== []) {
            $state['ifood_code'] = $state['ifood_code'] ?: $this->normalizeString($storedOrderDetails['displayId'] ?? null);
            foreach ($this->extractOrderDetailSnapshot($storedOrderDetails) as $fieldName => $fieldValue) {
                if (($state[$fieldName] ?? '') === '' && $fieldValue !== '') {
                    $state[$fieldName] = $fieldValue;
                }
            }
        }

        return $state;
    }

    public function getOrderHomologationSnapshot(Order $order): array
    {
        $this->init();

        $otherInformations = $this->getDecodedOrderOtherInformations($order);
        $latestEventType = $this->normalizeString($otherInformations['latest_event_type'] ?? null);
        $payload = $latestEventType !== '' && is_array($otherInformations[$latestEventType] ?? null)
            ? $otherInformations[$latestEventType]
            : [];
        $orderPayload = is_array($payload['order'] ?? null) ? $payload['order'] : [];

        if ($orderPayload === []) {
            $orderPayload = $this->findStoredIfoodOrderDetails($order);
        }

        if ($orderPayload === []) {
            return [
                'financial' => null,
                'payment' => null,
                'customer' => null,
                'delivery' => null,
                'address' => null,
                'notes' => null,
                'identifiers' => null,
                'raw_payload_available' => false,
            ];
        }

        $delivery = is_array($orderPayload['delivery'] ?? null) ? $orderPayload['delivery'] : [];
        $deliveryAddress = is_array($delivery['deliveryAddress'] ?? null) ? $delivery['deliveryAddress'] : [];
        $customer = is_array($orderPayload['customer'] ?? null) ? $orderPayload['customer'] : [];
        $phone = is_array($customer['phone'] ?? null) ? $customer['phone'] : [];
        $payments = is_array($orderPayload['payments'] ?? null) ? $orderPayload['payments'] : [];
        $methods = is_array($payments['methods'] ?? null) ? $payments['methods'] : [];
        $firstMethod = is_array($methods[0] ?? null) ? $methods[0] : [];
        $total = is_array($orderPayload['total'] ?? null) ? $orderPayload['total'] : [];
        $additionalFees = is_array($orderPayload['additionalFees'] ?? null) ? $orderPayload['additionalFees'] : [];
        $benefitSnapshot = $this->extractOrderBenefitSnapshot($orderPayload);
        $additionalFeeSnapshot = $this->extractAdditionalFeeSnapshot($additionalFees);

        $itemsTotal = round((float) ($total['subTotal'] ?? 0), 2);
        $deliveryFee = round((float) ($total['deliveryFee'] ?? 0), 2);
        $additionalFeesTotal = round(
            (float) ($total['additionalFees'] ?? $additionalFeeSnapshot['total']),
            2
        );
        $serviceFee = $additionalFeeSnapshot['merchant_service_fee'];
        $smallOrderFee = $additionalFeeSnapshot['merchant_small_order_fee'];
        $mealTopUpFee = $additionalFeeSnapshot['merchant_meal_top_up_fee'];
        $discountTotal = round((float) (($total['benefits'] ?? null) ?: ($benefitSnapshot['discount_total'] ?? 0)), 2);
        $ifoodSubsidy = round((float) ($benefitSnapshot['ifood_subsidy'] ?? 0), 2);
        $merchantSubsidy = round((float) ($benefitSnapshot['merchant_subsidy'] ?? 0), 2);
        $customerTotal = round(
            (float) ($total['orderAmount'] ?? max(0, $itemsTotal + $deliveryFee + $additionalFeesTotal - $discountTotal)),
            2
        );
        $amountPaid = round((float) ($payments['prepaid'] ?? 0), 2);
        $amountPending = round((float) ($payments['pending'] ?? 0), 2);
        $customerNeedPayingMoney = $amountPending > 0 ? $amountPending : $customerTotal;
        $changeFor = round((float) ($firstMethod['cash']['changeFor'] ?? 0), 2);
        $changeAmount = $changeFor > $customerNeedPayingMoney
            ? round(max(0, $changeFor - $customerNeedPayingMoney), 2)
            : 0.0;
        $isPaidOnline = $amountPaid > 0 && $amountPending <= 0.009;
        $deliveredBy = strtoupper($this->normalizeString($delivery['deliveredBy'] ?? null));
        $deliveryMode = $this->normalizeString($delivery['mode'] ?? ($delivery['deliveryMode'] ?? null));
        $isStoreDelivery = $this->isMerchantDeliveryContext($deliveredBy, $deliveryMode);
        $merchantAdditionalFeeTotal = $additionalFeeSnapshot['merchant_total'];
        $storeReceivableTotal = round(max(
            0,
            $itemsTotal
                + ($isStoreDelivery ? $deliveryFee : 0.0)
                - $merchantSubsidy
                - $merchantAdditionalFeeTotal
        ), 2);

        return [
            'financial' => [
                'currency' => 'BRL',
                'items_total' => $itemsTotal,
                'delivery_fee' => $deliveryFee,
                'service_fee' => $serviceFee,
                'small_order_fee' => $smallOrderFee,
                'meal_top_up_fee' => $mealTopUpFee,
                'additional_fees_total' => $additionalFeesTotal,
                'merchant_additional_fee_total' => $merchantAdditionalFeeTotal,
                'tip_total' => 0.0,
                'subtotal_before_discounts' => round($itemsTotal + $deliveryFee + $additionalFeesTotal, 2),
                'discount_total' => $discountTotal,
                'store_discount_total' => $merchantSubsidy,
                'platform_discount_total' => $ifoodSubsidy,
                'store_non_delivery_discount_total' => round((float) ($benefitSnapshot['store_non_delivery_discount_total'] ?? 0), 2),
                'platform_non_delivery_discount_total' => round((float) ($benefitSnapshot['platform_non_delivery_discount_total'] ?? 0), 2),
                'store_delivery_discount_total' => round((float) ($benefitSnapshot['store_delivery_discount_total'] ?? 0), 2),
                'platform_delivery_discount_total' => round((float) ($benefitSnapshot['platform_delivery_discount_total'] ?? 0), 2),
                'promotions_total' => $discountTotal,
                'items_discount_total' => 0.0,
                'delivery_discount_total' => round(
                    (float) ($benefitSnapshot['store_delivery_discount_total'] ?? 0)
                        + (float) ($benefitSnapshot['platform_delivery_discount_total'] ?? 0),
                    2
                ),
                'coupon_discount_total' => 0.0,
                'customer_total' => $customerTotal,
                'customer_need_paying_money' => $customerNeedPayingMoney,
                'store_receivable_total' => $storeReceivableTotal,
                'real_pay_total' => $amountPaid,
                'refund_total' => 0.0,
                'store_charged_delivery_price' => $deliveryFee,
                'shop_paid_money' => 0.0,
                'ifood_subsidy' => $ifoodSubsidy,
                'merchant_subsidy' => $merchantSubsidy,
                'payment_brand' => $this->normalizeString($firstMethod['card']['brand'] ?? null),
                'change_for' => $changeFor,
            ],
            'payment' => [
                'pay_type' => $this->normalizeString($firstMethod['type'] ?? null),
                'pay_method' => $this->normalizeString($firstMethod['method'] ?? null),
                'pay_channel' => $this->normalizeString($firstMethod['card']['brand'] ?? ($firstMethod['method'] ?? null)),
                'selected_payment_label' => $this->normalizeString($firstMethod['method'] ?? null),
                'amount_paid' => $amountPaid,
                'amount_pending' => $amountPending,
                'collect_on_delivery_amount' => $amountPending,
                'customer_need_paying_money' => $customerNeedPayingMoney,
                'shop_paid_money' => 0.0,
                'change_for' => $changeFor,
                'change_amount' => $changeAmount,
                'needs_change' => $changeAmount > 0.009,
                'is_fully_paid' => $amountPending <= 0.009,
                'is_paid_online' => $isPaidOnline,
            ],
            'customer' => [
                'name' => $this->normalizeString($customer['name'] ?? null),
                'phone' => $this->normalizeString($phone['number'] ?? null),
            ],
            'delivery' => [
                'delivered_by' => $deliveredBy,
                'delivery_mode' => $deliveryMode,
                'is_store_delivery' => $isStoreDelivery,
                'is_platform_delivery' => !$isStoreDelivery,
            ],
            'address' => [
                'display' => $this->normalizeString($deliveryAddress['formattedAddress'] ?? null),
                'street_name' => $this->normalizeString($deliveryAddress['streetName'] ?? null),
                'street_number' => $this->normalizeString($deliveryAddress['streetNumber'] ?? null),
                'district' => $this->normalizeString($deliveryAddress['neighborhood'] ?? null),
                'city' => $this->normalizeString($deliveryAddress['city'] ?? null),
                'state' => $this->normalizeString($deliveryAddress['state'] ?? null),
                'postal_code' => $this->normalizeString($deliveryAddress['postalCode'] ?? null),
                'reference' => $this->normalizeString($deliveryAddress['reference'] ?? null),
                'complement' => $this->normalizeString($deliveryAddress['complement'] ?? null),
            ],
            'notes' => [
                'remark' => $this->extractOrderRemarkFromPayload($orderPayload),
            ],
            'identifiers' => [
                'ifood_code' => $this->normalizeString($orderPayload['displayId'] ?? null),
                'ifood_id' => $this->normalizeString($payload['orderId'] ?? null),
            ],
            'raw_payload_available' => true,
        ];
    }

    public function getSelfDeliveryConfirmationUrl(): string
    {
        return self::SELF_DELIVERY_CONFIRMATION_URL;
    }

    public function performCancelAction(Order $order, ?string $reason = null, ?string $cancellationCode = null): array
    {
        $this->init();
        $orderId = $this->resolveRemoteOrderId($order);
        if (!$orderId) {
            return $this->buildUnavailableOrderActionResponse('Pedido iFood sem identificador remoto.');
        }

        $normalizedReason = $this->normalizeString($reason);
        $normalizedCancellationCode = $this->normalizeString($cancellationCode);
        if ($normalizedCancellationCode === '') {
            $normalizedCancellationCode = $this->resolveDefaultIfoodCancellationCode($orderId) ?? '';
        }

        $result = $this->persistOrderActionResult(
            $order,
            'cancel',
            $this->cancelByShop(
                $orderId,
                $normalizedCancellationCode !== '' ? $normalizedCancellationCode : null
            ),
            'cancellation_requested'
        );

        if ((string) ($result['errno'] ?? '') === '0' && ($normalizedReason !== '' || $normalizedCancellationCode !== '')) {
            try {
                $service = $this->iFoodService;
                if ($service instanceof iFoodService) {
                    $service->persistOrderIntegrationState($order, [
                        'cancel_reason' => $normalizedCancellationCode !== '' ? $normalizedCancellationCode : $normalizedReason,
                    ]);
                }
                $this->entityManager->flush();
            } catch (\Throwable $e) {
                self::$logger->error('iFood cancel reason persist failed', [
                    'order_id' => $order->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    public function respondHandshakeDispute(Order $order, string $decision, ?string $reason = null, ?string $alternativeId = null): array
    {
        $this->init();

        $storedState = $this->getStoredOrderIntegrationState($order);
        $disputeId = $this->normalizeString($storedState['handshake_dispute_id'] ?? null);
        if ($disputeId === '') {
            return $this->buildUnavailableOrderActionResponse('Pedido iFood sem negociacao aberta.');
        }

        $normalizedDecision = strtolower($this->normalizeString($decision));
        if (!in_array($normalizedDecision, ['accept', 'reject', 'alternative'], true)) {
            return $this->buildUnavailableOrderActionResponse('Acao de negociacao iFood invalida.');
        }

        $validNegotiationReasons = [
            'HIGH_STORE_DEMAND',
            'UNKNOWN_ISSUE',
            'CUSTOMER_SATISFACTION',
            'INVENTORY_CHECK',
            'SYSTEM_ISSUE',
            'WRONG_ORDER',
            'PRODUCT_QUALITY',
            'LATE_DELIVERY',
            'CUSTOMER_REQUEST',
        ];
        $payload = [];
        $normalizedReason = $this->normalizeString($reason);
        $normalizedReasonCode = strtoupper($normalizedReason);
        $normalizedAlternativeId = $this->normalizeString($alternativeId);

        if ($normalizedDecision === 'accept') {
            $acceptReasons = array_filter(array_map(
                static fn($value) => strtoupper(trim((string) $value)),
                explode(',', (string) ($storedState['handshake_accept_reasons'] ?? ''))
            ));
            if ($acceptReasons !== []) {
                $payload['reason'] = in_array($normalizedReasonCode, $acceptReasons, true)
                    ? $normalizedReasonCode
                    : reset($acceptReasons);
            }
        }

        if ($normalizedDecision === 'reject') {
            if (!in_array($normalizedReasonCode, $validNegotiationReasons, true)) {
                return $this->buildUnavailableOrderActionResponse('Informe um motivo valido para rejeitar a negociacao iFood.');
            }

            $payload['reason'] = $normalizedReasonCode;
        }

        if ($normalizedDecision === 'alternative') {
            $storedAlternatives = $this->decodeOrderOtherInformationsValue($storedState['handshake_alternatives_json'] ?? null);
            $selectedAlternative = [];
            $selectedAlternativeId = $this->normalizeString($storedState['handshake_alternative_id'] ?? null);
            foreach ($storedAlternatives as $alternative) {
                if (!is_array($alternative)) {
                    continue;
                }

                $currentAlternativeId = $this->normalizeString($alternative['id'] ?? null);
                if ($selectedAlternative === [] || ($normalizedAlternativeId !== '' && $currentAlternativeId === $normalizedAlternativeId)) {
                    $selectedAlternative = $alternative;
                    $selectedAlternativeId = $currentAlternativeId !== '' ? $currentAlternativeId : $selectedAlternativeId;
                }
                if ($normalizedAlternativeId !== '' && $currentAlternativeId === $normalizedAlternativeId) {
                    break;
                }
            }

            $alternativeMetadata = is_array($selectedAlternative['metadata'] ?? null)
                ? $selectedAlternative['metadata']
                : [];
            $alternativeAmount = is_array($alternativeMetadata['maxAmount'] ?? null)
                ? $alternativeMetadata['maxAmount']
                : (is_array($alternativeMetadata['amount'] ?? null) ? $alternativeMetadata['amount'] : []);
            $alternativeTimes = is_array($alternativeMetadata['allowedsAdditionalTimeInMinutes'] ?? null)
                ? $alternativeMetadata['allowedsAdditionalTimeInMinutes']
                : (is_array($alternativeMetadata['allowedAdditionalTimeInMinutes'] ?? null) ? $alternativeMetadata['allowedAdditionalTimeInMinutes'] : []);
            $alternativeReasons = is_array($alternativeMetadata['allowedsAdditionalTimeReasons'] ?? null)
                ? $alternativeMetadata['allowedsAdditionalTimeReasons']
                : (is_array($alternativeMetadata['allowedAdditionalTimeReasons'] ?? null) ? $alternativeMetadata['allowedAdditionalTimeReasons'] : []);

            $alternativeType = strtoupper($this->normalizeString($selectedAlternative['type'] ?? ($storedState['handshake_alternative_type'] ?? null)));
            $payload['type'] = $alternativeType;
            $payload['metadata'] = [];

            if (in_array($alternativeType, ['REFUND', 'BENEFIT'], true)) {
                $amountValue = $this->normalizeString($alternativeAmount['value'] ?? ($storedState['handshake_alternative_amount_value'] ?? null));
                $amountCurrency = $this->normalizeString($alternativeAmount['currency'] ?? ($storedState['handshake_alternative_amount_currency'] ?? null)) ?: 'BRL';
                if ($amountValue !== '') {
                    $payload['metadata']['amount'] = [
                        'value' => $amountValue,
                        'currency' => $amountCurrency,
                    ];
                }
            }

            if ($alternativeType === 'ADDITIONAL_TIME') {
                $minutes = (int) $this->normalizeString($alternativeTimes[0] ?? ($storedState['handshake_alternative_time_minutes'] ?? null));
                $timeReason = $normalizedReason !== ''
                    ? $normalizedReason
                    : $this->normalizeString($alternativeReasons[0] ?? ($storedState['handshake_alternative_reason'] ?? null));
                if ($minutes > 0 && $timeReason !== '') {
                    $payload['metadata']['additionalTimeInMinutes'] = $minutes;
                    $payload['metadata']['additionalTimeReason'] = $timeReason;
                }
            }

            if ($payload['type'] === '' || $payload['metadata'] === []) {
                if (in_array($alternativeType, ['REFUND', 'BENEFIT'], true)) {
                    return $this->buildUnavailableOrderActionResponse('Pedido iFood sem valor permitido para contraproposta de reembolso.');
                }

                if ($alternativeType === 'ADDITIONAL_TIME') {
                    return $this->buildUnavailableOrderActionResponse('Pedido iFood sem tempo permitido para contraproposta.');
                }

                return $this->buildUnavailableOrderActionResponse('Pedido iFood sem alternativa valida para contraproposta.');
            }

            if ($selectedAlternativeId === '') {
                return $this->buildUnavailableOrderActionResponse('Pedido iFood sem identificador da alternativa para contraproposta.');
            }
        }

        $actionPath = $normalizedDecision === 'alternative'
            ? '/alternatives/' . rawurlencode($selectedAlternativeId)
            : '/' . $normalizedDecision;

        return $this->persistOrderActionResult(
            $order,
            'handshake_' . $normalizedDecision,
            $this->callIfoodDisputeAction($disputeId, $actionPath, $payload),
            null,
            null
        );
    }

    public function performReadyAction(Order $order): array
    {
        $this->init();
        $orderId = $this->resolveRemoteOrderId($order);
        if (!$orderId) {
            return $this->buildUnavailableOrderActionResponse('Pedido iFood sem identificador remoto.');
        }

        $storedState = $this->getStoredOrderIntegrationState($order);
        $remoteState = strtolower($this->normalizeString($storedState['remote_order_state'] ?? null));
        $shouldAutoConfirmBeforeDispatch = in_array($remoteState, [
            '',
            'new',
            'placed',
            'order_created',
            'pending',
        ], true);

        if ($shouldAutoConfirmBeforeDispatch) {
            $confirmResult = $this->persistOrderActionResult(
                $order,
                'confirm',
                $this->confirmOrder($orderId),
                'confirmed',
                ['realStatus' => 'open', 'status' => 'preparing']
            );

            if ((string) ($confirmResult['errno'] ?? '') !== '0') {
                return [
                    'errno' => $confirmResult['errno'] ?? 1,
                    'errmsg' => 'Falha ao confirmar pedido antes do despacho: ' . $this->normalizeString($confirmResult['errmsg'] ?? null),
                    'status' => (int) ($confirmResult['status'] ?? 500),
                    'data' => is_array($confirmResult['data'] ?? null) ? $confirmResult['data'] : [],
                ];
            }
        }

        $dispatchFlow = $this->resolveDispatchFlowForOrder($order);
        $isMerchantDelivery = $dispatchFlow === 'merchant';
        $isPickupFlow = $dispatchFlow === 'pickup';
        $stateOnSuccess = $isMerchantDelivery ? 'dispatching' : 'ready';
        $localStatusOnSuccess = $isMerchantDelivery
            ? ['realStatus' => 'pending', 'status' => 'way']
            : ['realStatus' => 'pending', 'status' => 'ready'];
        $actionResponse = ($isPickupFlow || !$isMerchantDelivery)
            ? $this->readyOrder($orderId)
            : $this->dispatchOrderByDeliveryMode($order, $orderId);

        return $this->persistOrderActionResult(
            $order,
            'ready',
            $actionResponse,
            $stateOnSuccess,
            $localStatusOnSuccess
        );
    }

    public function performDeliveredAction(Order $order, ?string $deliveryCode = null, ?string $locator = null): array
    {
        $this->init();
        $orderId = $this->resolveRemoteOrderId($order);
        if (!$orderId) {
            return $this->buildUnavailableOrderActionResponse('Pedido iFood sem identificador remoto.');
        }

        $dispatchFlow = $this->resolveDispatchFlowForOrder($order);
        $storedState = $this->getStoredOrderIntegrationState($order);
        $remoteState = strtolower($this->normalizeString($storedState['remote_order_state'] ?? null));
        $realStatus = strtolower($this->normalizeString($order->getStatus()?->getRealStatus()));
        $statusName = strtolower($this->normalizeString($order->getStatus()?->getStatus()));
        $alreadyDispatched = $dispatchFlow === 'merchant'
            && (
                ($realStatus === 'pending' && $statusName === 'way')
                || in_array($remoteState, ['dispatching', 'dispatched', 'order_dispatched'], true)
            );

        if ($alreadyDispatched) {
            $normalizedDeliveryCode = $this->normalizeString($deliveryCode);
            if ($normalizedDeliveryCode === '') {
                return $this->buildUnavailableOrderActionResponse(
                    'Entrega propria iFood deve ser concluida pelo link de confirmacao ou por codigo de entrega valido.'
                );
            }

            return $this->persistOrderActionResult(
                $order,
                'delivered',
                $this->verifyDeliveryCode($orderId, $normalizedDeliveryCode),
                'concluded',
                ['realStatus' => 'closed', 'status' => 'closed']
            );
        }

        return $this->persistOrderActionResult(
            $order,
            'delivered',
            $this->dispatchOrderByDeliveryMode($order, $orderId),
            'dispatching'
        );
    }

    public function performStartPreparationAction(Order $order): array
    {
        $this->init();
        $orderId = $this->resolveRemoteOrderId($order);
        if (!$orderId) {
            return $this->buildUnavailableOrderActionResponse('Pedido iFood sem identificador remoto.');
        }

        return $this->persistOrderActionResult(
            $order,
            'start_preparation',
            $this->callIfoodOrderAction($orderId, '/startPreparation'),
            'preparing',
            ['realStatus' => 'open', 'status' => 'preparing']
        );
    }

    private function decodeIfoodActionResponseBody(string $rawBody): array
    {
        if ($rawBody === '') {
            return [];
        }

        $decoded = json_decode($rawBody, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return ['message' => $rawBody];
    }

    private function normalizeIfoodRequestPayload(array $payload): mixed
    {
        if (empty($payload)) {
            return (object) [];
        }

        return $payload;
    }

    private function callIfoodOrderAction(string $orderId, string $actionPath, array $payload = []): ?array
    {
        try {
            $token = $this->getAccessToken();
            if (!$token) {
                self::$logger->warning('iFood order action skipped because token is unavailable', [
                    'order_id' => $orderId,
                    'action' => $actionPath,
                ]);
                return null;
            }

            $encodedOrderId = rawurlencode($orderId);
            $endpoint = '/order/v1.0/orders/' . $encodedOrderId . $actionPath;

            try {
                self::$logger->info('iFood order action request', [
                    'order_id' => $orderId,
                    'action' => $actionPath,
                    'payload' => $payload,
                ]);

                $response = $this->ifoodClient->requestOrderEndpoint('POST', '/orders/' . $encodedOrderId . $actionPath, [
                    'json' => $this->normalizeIfoodRequestPayload($payload),
                    'timeout' => 15,
                    'max_duration' => 20,
                ]);

                $statusCode = $response->getStatusCode();
                $rawBody = $response->getContent(false);
                $body = $this->decodeIfoodActionResponseBody((string) $rawBody);

                self::$logger->info('iFood order action response', [
                    'order_id' => $orderId,
                    'action' => $actionPath,
                    'status_code' => $statusCode,
                    'response' => $body,
                ]);

                return [
                    'status' => $statusCode,
                    'body' => $body,
                ];
            } catch (\Throwable $e) {
                self::$logger->error('iFood order action endpoint error', [
                    'order_id' => $orderId,
                    'action' => $actionPath,
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                ]);

                return [
                    'status' => 500,
                    'body' => [
                        'message' => $e->getMessage(),
                    ],
                ];
            }
        } catch (\Throwable $e) {
            self::$logger->error('iFood order action error', [
                'order_id' => $orderId,
                'action' => $actionPath,
                'error' => $e->getMessage(),
            ]);
            return [
                'status' => 500,
                'body' => [
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }

    private function callIfoodDisputeAction(string $disputeId, string $actionPath, array $payload = []): ?array
    {
        try {
            $token = $this->getAccessToken();
            if (!$token) {
                self::$logger->warning('iFood dispute action skipped because token is unavailable', [
                    'dispute_id' => $disputeId,
                    'action' => $actionPath,
                ]);
                return null;
            }

            $endpoint = '/order/v1.0/disputes/' . rawurlencode($disputeId) . $actionPath;

            try {
                self::$logger->info('iFood dispute action request', [
                    'dispute_id' => $disputeId,
                    'action' => $actionPath,
                    'payload' => $payload,
                ]);

                $response = $this->ifoodClient->requestOrderEndpoint('POST', '/disputes/' . rawurlencode($disputeId) . $actionPath, [
                    'json' => $this->normalizeIfoodRequestPayload($payload),
                    'timeout' => 15,
                    'max_duration' => 20,
                ]);

                $statusCode = $response->getStatusCode();
                $body = $this->decodeIfoodActionResponseBody((string) $response->getContent(false));

                self::$logger->info('iFood dispute action response', [
                    'dispute_id' => $disputeId,
                    'action' => $actionPath,
                    'status_code' => $statusCode,
                    'response' => $body,
                ]);

                return [
                    'status' => $statusCode,
                    'body' => $body,
                ];
            } catch (\Throwable $e) {
                self::$logger->error('iFood dispute action endpoint error', [
                    'dispute_id' => $disputeId,
                    'action' => $actionPath,
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                ]);

                return [
                    'status' => 500,
                    'body' => [
                        'message' => $e->getMessage(),
                    ],
                ];
            }
        } catch (\Throwable $e) {
            self::$logger->error('iFood dispute action error', [
                'dispute_id' => $disputeId,
                'action' => $actionPath,
                'error' => $e->getMessage(),
            ]);
            return [
                'status' => 500,
                'body' => [
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }

    private function callIfoodShippingAction(string $orderId, string $actionPath, array $payload = []): ?array
    {
        try {
            $token = $this->getAccessToken();
            if (!$token) {
                self::$logger->warning('iFood shipping action skipped because token is unavailable', [
                    'order_id' => $orderId,
                    'action' => $actionPath,
                ]);
                return null;
            }

            self::$logger->info('iFood shipping action request', [
                'order_id' => $orderId,
                'action' => $actionPath,
                'payload' => $payload,
            ]);

            $response = $this->ifoodClient->requestShippingEndpoint('POST', '/orders/' . rawurlencode($orderId) . $actionPath, [
                'json' => $this->normalizeIfoodRequestPayload($payload),
                'timeout' => 15,
                'max_duration' => 20,
            ]);

            $statusCode = $response->getStatusCode();
            $rawBody = $response->getContent(false);
            $body = $this->decodeIfoodActionResponseBody((string) $rawBody);

            self::$logger->info('iFood shipping action response', [
                'order_id' => $orderId,
                'action' => $actionPath,
                'status_code' => $statusCode,
                'response' => $body,
            ]);

            return [
                'status' => $statusCode,
                'body' => $body,
            ];
        } catch (\Throwable $e) {
            self::$logger->error('iFood shipping action error', [
                'order_id' => $orderId,
                'action' => $actionPath,
                'error' => $e->getMessage(),
            ]);
            return [
                'status' => 500,
                'body' => [
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }

    private function callIfoodShippingMerchantAction(
        string $merchantId,
        string $method,
        string $path,
        array $query = [],
        array $payload = []
    ): ?array {
        try {
            $token = $this->getAccessToken();
            if (!$token) {
                self::$logger->warning('iFood shipping merchant action skipped because token is unavailable', [
                    'merchant_id' => $merchantId,
                    'method' => $method,
                    'path' => $path,
                ]);

                return null;
            }

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'query' => $query,
                'timeout' => 20,
                'max_duration' => 30,
            ];

            if ($payload !== []) {
                $options['json'] = $this->normalizeIfoodRequestPayload($payload);
            }

            self::$logger->info('iFood shipping merchant action request', [
                'merchant_id' => $merchantId,
                'method' => $method,
                'path' => $path,
                'query' => $query,
                'payload' => $payload,
            ]);

            $response = $this->ifoodClient->requestShippingEndpoint(
                strtoupper($method),
                '/merchants/' . rawurlencode($merchantId) . $path,
                $options
            );

            $statusCode = $response->getStatusCode();
            $rawBody = $response->getContent(false);
            $body = $this->decodeIfoodActionResponseBody((string) $rawBody);

            self::$logger->info('iFood shipping merchant action response', [
                'merchant_id' => $merchantId,
                'method' => $method,
                'path' => $path,
                'status_code' => $statusCode,
                'response' => $body,
            ]);

            return [
                'status' => $statusCode,
                'body' => $body,
            ];
        } catch (\Throwable $e) {
            self::$logger->error('iFood shipping merchant action error', [
                'merchant_id' => $merchantId,
                'method' => $method,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 500,
                'body' => [
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }

    private function shouldFallbackActionEndpoint(?array $response): bool
    {
        if (!$response) {
            return true;
        }

        $statusCode = (int) ($response['status'] ?? 0);
        if ($statusCode >= 200 && $statusCode < 300) {
            return false;
        }

        return in_array($statusCode, [404, 405, 500, 502, 503, 504], true);
    }

    private function resolveDispatchFlowForOrder(Order $order): string
    {
        $storedState = $this->getStoredOrderIntegrationState($order);
        $orderType = strtoupper($this->normalizeString($storedState['order_type'] ?? null));
        if (in_array($orderType, ['TAKEOUT', 'DINE_IN'], true)) {
            return 'pickup';
        }

        $deliveredBy = strtoupper($this->normalizeString($storedState['delivered_by'] ?? null));
        if ($deliveredBy === 'MERCHANT') {
            return 'merchant';
        }

        if ($deliveredBy === 'IFOOD') {
            return 'ifood';
        }

        $deliveryMode = strtolower($this->normalizeString($storedState['delivery_mode'] ?? null));
        if (in_array($deliveryMode, ['merchant', 'store', 'self', 'self_delivery', 'own', 'own_fleet'], true)) {
            return 'merchant';
        }

        return 'ifood';
    }

    private function dispatchOrderByDeliveryMode(Order $order, string $orderId): ?array
    {
        $flow = $this->resolveDispatchFlowForOrder($order);

        if ($flow === 'merchant') {
            $payload = ['deliveredBy' => 'MERCHANT'];
            $shippingResponse = $this->callIfoodShippingAction($orderId, '/dispatch', $payload);
            if (!$this->shouldFallbackActionEndpoint($shippingResponse)) {
                return $shippingResponse;
            }

            $orderResponse = $this->callIfoodOrderAction($orderId, '/dispatch', $payload);
            return $orderResponse ?: $shippingResponse;
        }

        $orderResponse = $this->callIfoodOrderAction($orderId, '/dispatch');
        if (!$this->shouldFallbackActionEndpoint($orderResponse)) {
            return $orderResponse;
        }

        $shippingResponse = $this->callIfoodShippingAction($orderId, '/dispatch');
        return $shippingResponse ?: $orderResponse;
    }

    private function cancelByShop(string $orderId, ?string $cancellationCode = null): ?array
    {
        $payload = [];
        if ($cancellationCode !== null && $cancellationCode !== '') {
            $payload['cancellationCode'] = $cancellationCode;
            $payload['reason'] = $cancellationCode;
        }
        return $this->callIfoodOrderAction($orderId, '/requestCancellation', $payload);
    }

    private function resolveDefaultIfoodCancellationCode(?string $orderId = null): ?string
    {
        $rawReasons = $this->fetchIfoodCancellationReasons($orderId);
        foreach ($rawReasons as $reason) {
            if (!is_array($reason)) {
                continue;
            }

            $code = $this->normalizeString(
                $reason['cancelCodeId'] ?? $reason['cancelCode'] ?? $reason['code'] ?? null
            );
            if ($code !== '') {
                return $code;
            }
        }

        return null;
    }

    private function confirmOrder(string $orderId): ?array
    {
        $response = $this->callIfoodOrderAction($orderId, '/confirm');
        if (!$this->shouldFallbackActionEndpoint($response)) {
            return $response;
        }

        $fallbackResponse = $this->callIfoodOrderAction($orderId, '/accept');
        return $fallbackResponse ?: $response;
    }

    private function extractCancellationReasonListFromResponse(array $data): array
    {
        if (array_is_list($data)) {
            $hasReasonShape = false;
            foreach ($data as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $code = $this->normalizeString(
                    $entry['cancelCodeId'] ?? $entry['cancelCode'] ?? $entry['code'] ?? null
                );
                $description = $this->normalizeString(
                    $entry['description'] ?? $entry['reason'] ?? $entry['title'] ?? $entry['name'] ?? null
                );
                if ($code !== '' || $description !== '') {
                    $hasReasonShape = true;
                    break;
                }
            }

            if ($hasReasonShape) {
                return $data;
            }
        }

        if (is_array($data['cancellationReasons'] ?? null)) {
            return $data['cancellationReasons'];
        }

        if (is_array($data['reasons'] ?? null)) {
            return $data['reasons'];
        }

        if (is_array($data['data'] ?? null)) {
            if (is_array($data['data']['cancellationReasons'] ?? null)) {
                return $data['data']['cancellationReasons'];
            }

            if (is_array($data['data']['reasons'] ?? null)) {
                return $data['data']['reasons'];
            }
        }

        return [];
    }

    private function fetchIfoodCancellationReasons(?string $orderId = null): array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return [];
        }

        $endpoints = [];
        $normalizedOrderId = $this->normalizeString($orderId);
        if ($normalizedOrderId !== '') {
            $encodedOrderId = rawurlencode($normalizedOrderId);
            $endpoints[] = '/order/v1.0/orders/' . $encodedOrderId . '/cancellationReasons';
        }
        $endpoints[] = '/order/v1.0/cancellation/reasons';

        try {
            foreach ($endpoints as $endpoint) {
                $response = $this->ifoodClient->requestOrderEndpoint('GET', $endpoint, [
                    'timeout' => 15,
                    'max_duration' => 20,
                ]);
                if ($response->getStatusCode() !== 200) {
                    continue;
                }

                $data = $response->toArray(false);
                $reasons = $this->extractCancellationReasonListFromResponse($data);
                if ($reasons !== []) {
                    return $reasons;
                }
            }

            return [];
        } catch (\Throwable $e) {
            self::$logger->error('iFood cancellation reasons fetch failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getIfoodCancellationReasons(?Order $order = null): array
    {
        $this->init();
        $orderId = $order ? $this->resolveRemoteOrderId($order) : null;
        $raw = $this->fetchIfoodCancellationReasons($orderId);

        $mapped = array_map(fn(array $r) => [
            'reason_id'            => $this->normalizeString(
                $r['cancelCodeId'] ?? $r['cancelCode'] ?? $r['code'] ?? null
            ),
            'description'          => $this->normalizeString(
                $r['description'] ?? $r['reason'] ?? $r['title'] ?? $r['name'] ?? null
            ),
            'applicable'           => true,
            'requires_description' => false,
        ], $raw);

        return array_values(array_filter(
            $mapped,
            static fn(array $reason): bool => $reason['reason_id'] !== ''
        ));
    }

    public function performConfirmAction(Order $order): array
    {
        $this->init();
        $orderId = $this->resolveRemoteOrderId($order);
        if (!$orderId) {
            return $this->buildUnavailableOrderActionResponse('Pedido iFood sem identificador remoto.');
        }
        return $this->persistOrderActionResult(
            $order,
            'confirm',
            $this->confirmOrder($orderId),
            'confirmed',
            ['realStatus' => 'open', 'status' => 'preparing']
        );
    }

    private function readyOrder(string $orderId): ?array
    {
        return $this->callIfoodOrderAction($orderId, '/readyToPickup');
    }

    private function verifyDeliveryCode(string $orderId, string $deliveryCode): ?array
    {
        return $this->callIfoodOrderAction($orderId, '/verifyDeliveryCode', [
            'code' => $deliveryCode,
        ]);
    }

    private function deliveredOrder(string $orderId): ?array
    {
        return $this->callIfoodOrderAction($orderId, '/dispatch');
    }

    // SINCRONIZACAO DE STATUS COM iFOOD
    // Envia para iFood o novo status do pedido (pronto, entregue, cancelado)
    public function changeStatus(Order $order)
    {
        $action = $this->extractPendingOrderAction($order);
        if (($action['remote_sync'] ?? false) !== true) {
            return null;
        }

        $payload = is_array($action['payload'] ?? null) ? $action['payload'] : [];
        $reason = $this->normalizeString($payload['reason'] ?? null);
        $cancellationCode = $this->normalizeString(
            $payload['cancellation_code'] ?? ($payload['reason_id'] ?? null)
        );

        match ($action['name'] ?? '') {
            'cancel' => $this->performCancelAction(
                $order,
                $reason !== '' ? $reason : null,
                $cancellationCode !== '' ? $cancellationCode : null
            ),
            'ready' => $this->performReadyAction($order),
            'delivered' => $this->performDeliveredAction(
                $order,
                $this->normalizeString($payload['delivery_code'] ?? null) ?: null,
                $this->normalizeString($payload['locator'] ?? null) ?: null
            ),
            'confirm' => $this->performConfirmAction($order),
            default => null,
        };

        return null;
    }

    // ATUALIZA PRECO DO ITEM NO CATALOGO IFOOD
    // PATCH /catalog/v2.0/merchants/{merchantId}/items/price

}
