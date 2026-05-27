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
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

class Food99FinancialOperationsService extends AbstractMarketplaceService
{
    private const APP_CONTEXT = Order::APP_FOOD99;
    private const FOOD99_COMMISSION_RATE = 0.0790207;
    private const FOOD99_PAYMENT_PROCESSING_RATE = 0.032;
    private const FOOD99_LOGISTICS_COST_RATE = 0.60;
    private const FOOD99_MIN_LOGISTICS_COST = 4.50;

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

    private function normalizeFood99Money(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return round(((float) $value) / 100, 2);
    }

    private function normalizeFood99Boolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        return in_array($normalized, ['1', 'true', 'yes', 'y', 'sim', 's'], true);
    }

    private function callFood99ServiceMethod(string $method, array $arguments = []): mixed
    {
        $service = $this->resolveMarketplaceServiceInstance(Food99Service::class);
        if (!is_object($service)) {
            return null;
        }

        return $this->invokeMarketplaceServiceMethod($service, $method, $arguments);
    }

    private function decodeOrderOtherInformationsValue(mixed $value): array
    {
        $decoded = $this->callFood99ServiceMethod(__FUNCTION__, [$value]);

        return is_array($decoded) ? $decoded : [];
    }

    private function getDecodedOrderOtherInformations(Order $order): array
    {
        $decoded = $this->callFood99ServiceMethod(__FUNCTION__, [$order]);

        return is_array($decoded) ? $decoded : [];
    }

    private function resolveBestStoredOrderPayload(Order $order): array
    {
        $payload = $this->callFood99ServiceMethod(__FUNCTION__, [$order]);

        return is_array($payload) ? $payload : [];
    }

    private function resolveFood99CustomerName(array $address, string $fallback = 'Cliente Food99'): string
    {
        $name = $this->callFood99ServiceMethod(__FUNCTION__, [$address, $fallback]);

        return is_string($name) ? $name : $fallback;
    }

    private function extractOrderPromotionList(array $payload): array
    {
        $promotions = $this->callFood99ServiceMethod(__FUNCTION__, [$payload]);

        return is_array($promotions) ? $promotions : [];
    }

    private function extractFood99StoredSnapshotSection(array $payload, string $section): array
    {
        $snapshot = $this->callFood99ServiceMethod(__FUNCTION__, [$payload, $section]);

        return is_array($snapshot) ? $snapshot : [];
    }

    private function buildFood99AddressDisplay(array $address): ?string
    {
        $service = $this->resolveMarketplaceServiceInstance(Food99PeopleOperationsService::class);
        if (!is_object($service)) {
            return null;
        }

        $display = $this->invokeMarketplaceServiceMethod($service, __FUNCTION__, [$address]);

        return is_string($display) && $display !== '' ? $display : null;
    }

    private function resolveFood99SnapshotMoneyValue(array $source, mixed $fallback, string ...$keys): float
    {
        $value = $this->callFood99ServiceMethod(__FUNCTION__, [$source, $fallback, ...$keys]);

        return is_numeric($value) ? round((float) $value, 2) : 0.0;
    }

    private function resolveFood99SnapshotBooleanValue(array $source, ?bool $fallback, string ...$keys): bool
    {
        $value = $this->callFood99ServiceMethod(__FUNCTION__, [$source, $fallback, ...$keys]);

        return (bool) $value;
    }

    private function sumPromotionTotalDiscount(array $promotions): float
    {
        $value = $this->callFood99ServiceMethod(__FUNCTION__, [$promotions]);

        return is_numeric($value) ? round((float) $value, 2) : 0.0;
    }

    private function buildPromotionFundingBreakdown(array $promotions): array
    {
        $value = $this->callFood99ServiceMethod(__FUNCTION__, [$promotions]);

        return is_array($value) ? $value : [];
    }

    private function resolveFood99PaymentTypeLabel(?string $payType, ?string $deliveryType): string
    {
        $normalizedPayType = trim((string) $payType);

        return match ($normalizedPayType) {
            '1' => 'Pagamento online',
            '2' => 'Dinheiro',
            '3' => 'POS',
            '4' => 'Carteira / 99Pay',
            '5' => 'PayPay sem senha',
            '6' => 'PayPay com senha',
            default => trim((string) $deliveryType) === '1'
                ? 'Pagamento processado pela 99Food'
                : 'Pagamento nao mapeado',
        };
    }

    private function resolveFood99PaymentMethodLabel(?string $payMethod): string
    {
        return match (trim((string) $payMethod)) {
            '1' => 'Pagamento online',
            '2' => 'Pagamento offline',
            '0' => 'Nao informado pela 99',
            default => 'Metodo nao mapeado',
        };
    }

    private function resolveFood99PaymentChannelLabel(?string $payChannel, ?string $payMethod, ?string $deliveryType): string
    {
        $normalizedPayChannel = trim((string) $payChannel);
        $normalizedPayMethod = trim((string) $payMethod);
        $normalizedDeliveryType = trim((string) $deliveryType);

        if ($normalizedPayChannel === '') {
            return '';
        }

        return match ($normalizedPayChannel) {
            '0' => 'Nao informado pela 99',
            '110' => 'Cupom',
            '120' => '99Food Wallet',
            '150' => 'Cartao de credito / debito',
            '153' => 'Dinheiro',
            '154' => 'POS',
            '167' => 'Preauth',
            '182' => 'PayPay sem senha',
            '184' => 'PayPay com senha',
            '190' => '99Pay',
            '212' => 'PIX',
            '219' => '99Food Cuenta',
            '229' => 'NuPay',
            '234' => 'Apple Pay (pre-auth)',
            '235' => 'Apple Pay',
            '257' => 'Vale Refeicao Pluxee',
            '258' => 'Vale Refeicao Ticket',
            '259' => 'Vale Refeicao VR',
            '260' => 'Vale Refeicao Alelo',
            '261' => 'NEQUI',
            '262' => 'POS cartao de credito',
            '263' => 'POS cartao de debito',
            '264' => 'POS vale refeicao',
            '272' => 'Google Pay',
            '273' => 'Google Pay (pre-auth)',
            '310' => 'Yape',
            '311' => 'Plin',
            '901' => 'Beneficio',
            '2008' => 'Marketing',
            default => match ($normalizedPayMethod) {
                '1' => $normalizedDeliveryType === '1'
                    ? 'Pagamento online'
                    : 'Pagamento online selecionado pelo cliente',
                '2' => 'Pagamento offline',
                default => 'Canal nao mapeado',
            },
        };
    }

    private function resolveFood99SelectedPaymentLabel(
        string $paymentChannelLabel,
        string $paymentTypeLabel,
        string $paymentMethodLabel
    ): string {
        $preferredLabels = [
            $paymentChannelLabel,
            $paymentTypeLabel,
            $paymentMethodLabel,
        ];

        foreach ($preferredLabels as $label) {
            $normalizedLabel = trim((string) $label);
            if ($normalizedLabel === '') {
                continue;
            }

            if (in_array($normalizedLabel, [
                'Nao informado pela 99',
                'Canal nao mapeado',
                'Metodo nao mapeado',
                'Pagamento nao mapeado',
            ], true)) {
                continue;
            }

            return $normalizedLabel;
        }

        return trim((string) ($paymentChannelLabel ?: $paymentTypeLabel ?: $paymentMethodLabel));
    }

    private function resolveFood99InvoicePaymentTypeData(
        ?string $payType,
        ?string $payMethod,
        ?string $payChannel,
        ?string $deliveryType
    ): array {
        $normalizedPayType = trim((string) $payType);
        $normalizedPayMethod = trim((string) $payMethod);
        $normalizedPayChannel = trim((string) $payChannel);

        $paymentTypeLabel = $this->resolveFood99PaymentTypeLabel($normalizedPayType, $deliveryType);
        $paymentMethodLabel = $this->resolveFood99PaymentMethodLabel($normalizedPayMethod);
        $paymentChannelLabel = $this->resolveFood99PaymentChannelLabel($normalizedPayChannel, $normalizedPayMethod, $deliveryType);
        $selectedPaymentLabel = $this->resolveFood99SelectedPaymentLabel(
            $paymentChannelLabel,
            $paymentTypeLabel,
            $paymentMethodLabel
        );

        $paymentTypeData = match (true) {
            $normalizedPayChannel === '212' => [
                'paymentType' => 'PIX',
                'aliases' => [],
            ],
            $normalizedPayChannel === '153' || $normalizedPayType === '2' => [
                'paymentType' => 'Dinheiro',
                'aliases' => [],
            ],
            $normalizedPayChannel === '154' || $normalizedPayType === '3' => [
                'paymentType' => 'POS',
                'aliases' => [],
            ],
            $normalizedPayChannel === '263' => [
                'paymentType' => 'Débito',
                'aliases' => ['Debito'],
            ],
            $normalizedPayChannel === '262' => [
                'paymentType' => 'Crédito à Vista',
                'aliases' => ['Credito a Vista'],
            ],
            in_array($normalizedPayChannel, ['257', '258', '259', '260', '264'], true) => [
                'paymentType' => 'Refeição',
                'aliases' => ['Refeicao'],
            ],
            $normalizedPayChannel === '901' => [
                'paymentType' => 'Benefício',
                'aliases' => ['Beneficio'],
            ],
            in_array($normalizedPayChannel, ['120', '190', '219'], true) || $normalizedPayType === '4' => [
                'paymentType' => '99Pay',
                'aliases' => ['Carteira / 99Pay', '99Food Wallet'],
            ],
            in_array($normalizedPayChannel, ['229'], true) => [
                'paymentType' => 'NuPay',
                'aliases' => [],
            ],
            in_array($normalizedPayChannel, ['234', '235'], true) => [
                'paymentType' => 'Apple Pay',
                'aliases' => [],
            ],
            in_array($normalizedPayChannel, ['272', '273'], true) => [
                'paymentType' => 'Google Pay',
                'aliases' => [],
            ],
            $selectedPaymentLabel !== '' => [
                'paymentType' => $selectedPaymentLabel,
                'aliases' => [],
            ],
            default => [
                'paymentType' => '99Food',
                'aliases' => ['Food99'],
            ],
        };

        $paymentTypeData['frequency'] = 'single';
        $paymentTypeData['installments'] = 'single';
        $paymentTypeData['paymentCode'] = $normalizedPayChannel !== ''
            ? $normalizedPayChannel
            : ($normalizedPayType !== '' ? $normalizedPayType : null);
        $paymentTypeData['pay_type'] = $normalizedPayType;
        $paymentTypeData['pay_method'] = $normalizedPayMethod;
        $paymentTypeData['pay_channel'] = $normalizedPayChannel;
        $paymentTypeData['pay_type_label'] = $paymentTypeLabel;
        $paymentTypeData['pay_method_label'] = $paymentMethodLabel;
        $paymentTypeData['pay_channel_label'] = $paymentChannelLabel;
        $paymentTypeData['selected_payment_label'] = $selectedPaymentLabel;

        return $paymentTypeData;
    }

    private function resolveFood99ProviderPaymentType(People $provider, array $paymentTypeData, ?Wallet $wallet = null): PaymentType
    {
        $candidateNames = array_values(array_unique(array_filter(array_merge(
            [(string) ($paymentTypeData['paymentType'] ?? '')],
            is_array($paymentTypeData['aliases'] ?? null) ? $paymentTypeData['aliases'] : []
        ))));

        foreach ($candidateNames as $candidateName) {
            $paymentType = $this->entityManager->getRepository(PaymentType::class)->findOneBy([
                'people' => $provider,
                'paymentType' => $candidateName,
            ]);

            if (!$paymentType instanceof PaymentType) {
                continue;
            }

            if ($wallet instanceof Wallet) {
                $this->walletService->discoverWalletPaymentType(
                    $wallet,
                    $paymentType,
                    $paymentTypeData['paymentCode'] ?? null
                );
            }

            return $paymentType;
        }

        $paymentType = $this->walletService->discoverPaymentType($provider, [
            'paymentType' => $candidateNames[0] ?? '99Food',
            'frequency' => $paymentTypeData['frequency'] ?? 'single',
            'installments' => $paymentTypeData['installments'] ?? 'single',
        ]);

        if ($wallet instanceof Wallet) {
            $this->walletService->discoverWalletPaymentType(
                $wallet,
                $paymentType,
                $paymentTypeData['paymentCode'] ?? null
            );
        }

        return $paymentType;
    }

    private function resolveFood99SettlementPaymentType(People $provider, ?Wallet $wallet = null): PaymentType
    {
        return $this->resolveFood99ProviderPaymentType($provider, [
            'paymentType' => '99Food',
            'aliases' => ['Food99'],
            'frequency' => 'single',
            'installments' => 'single',
            'paymentCode' => self::APP_CONTEXT,
        ], $wallet);
    }

    private function shouldFood99UseMarketplaceWalletForReceivable(array $paymentTypeData): bool
    {
        $paymentType = strtolower(trim((string) ($paymentTypeData['paymentType'] ?? '')));

        return !in_array($paymentType, [
            'dinheiro',
            'pos',
            'débito',
            'debito',
            'crédito à vista',
            'credito a vista',
        ], true);
    }

    private function resolveFood99ReceivableWallet(
        Order $order,
        PaymentType $paymentType,
        array $paymentTypeData
    ): Wallet {
        $walletName = $this->shouldFood99UseMarketplaceWalletForReceivable($paymentTypeData)
            ? self::$app
            : trim((string) $paymentType->getPaymentType());

        if ($walletName === '') {
            $walletName = self::$app;
        }

        return $this->walletService->discoverWallet($order->getProvider(), $walletName);
    }

    private function applyFood99InvoiceContract(
        Invoice $invoice,
        PaymentType $paymentType,
        array $metadata,
        ?Status $status = null,
        ?Wallet $sourceWallet = null,
        ?Wallet $destinationWallet = null
    ): void {
        if ($status instanceof Status) {
            $invoice->setStatus($status);
        }

        if ($sourceWallet instanceof Wallet || $invoice->getSourceWallet() !== $sourceWallet) {
            $invoice->setSourceWallet($sourceWallet);
        }

        if ($destinationWallet instanceof Wallet || $invoice->getDestinationWallet() !== $destinationWallet) {
            $invoice->setDestinationWallet($destinationWallet);
        }

        $invoice->setPaymentType($paymentType);

        $otherInformations = $invoice->getOtherInformations(true);
        $serializedInformations = $otherInformations instanceof \stdClass
            ? (array) $otherInformations
            : (is_array($otherInformations) ? $otherInformations : []);
        $currentFood99Data = $serializedInformations[self::APP_CONTEXT] ?? [];

        if ($currentFood99Data instanceof \stdClass) {
            $currentFood99Data = (array) $currentFood99Data;
        }

        $serializedInformations[self::APP_CONTEXT] = array_merge(
            is_array($currentFood99Data) ? $currentFood99Data : [],
            $metadata
        );

        $invoice->setOtherInformations($serializedInformations);

        $this->entityManager->persist($invoice);
        $this->entityManager->flush();
    }

    private function createFood99PayableInvoice(
        Order $order,
        PaymentType $paymentType,
        float $amount,
        Status $status,
        Wallet $providerWallet,
        Wallet $food99Wallet,
        People $food99People,
        DateTime $dueDate,
        string $purpose,
        array $metadata = []
    ): ?Invoice {
        $normalizedAmount = round($amount, 2);
        if ($normalizedAmount <= 0) {
            return null;
        }

        $invoice = $this->invoiceService->createInvoice(
            $order,
            $order->getProvider(),
            $food99People,
            $normalizedAmount,
            $status,
            $dueDate,
            $providerWallet,
            $food99Wallet
        );

        $this->applyFood99InvoiceContract(
            $invoice,
            $paymentType,
            array_merge([
                'financial_kind' => 'account_payable',
                'invoice_purpose' => $purpose,
                'marketplace' => self::APP_CONTEXT,
            ], $metadata),
            $status,
            $providerWallet,
            $food99Wallet
        );

        return $invoice;
    }

    private function resolveFood99MarketplacePeople(): People
    {
        return $this->peopleService->discoveryPeople('6012920000123', null, null, '99 Food', 'J');
    }

    private function resolveOrderReferenceDate(Order $order): DateTime
    {
        $orderDate = $order->getOrderDate();

        if ($orderDate instanceof \DateTimeInterface) {
            return new DateTime($orderDate->format('Y-m-d'));
        }

        return new DateTime('now');
    }

    private function resolveFood99WeeklyDueDate(Order $order): DateTime
    {
        $reference = $this->resolveOrderReferenceDate($order);
        $dueDate = new DateTime($reference->format('Y-m-d'));
        $weekday = (int) $dueDate->format('N');
        $daysUntilSunday = 7 - $weekday;

        if ($daysUntilSunday > 0) {
            $dueDate->modify(sprintf('+%d days', $daysUntilSunday));
        }

        $dueDate->modify('+3 days');

        return $dueDate;
    }

    public function getOrderHomologationSnapshot(Order $order): array
    {
        $this->init();

        $payload = $this->resolveBestStoredOrderPayload($order);
        if (empty($payload)) {
            return [
                'financial' => null,
                'payment' => null,
                'customer' => null,
                'address' => null,
                'notes' => null,
                'identifiers' => null,
                'raw_payload_available' => false,
            ];
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $orderInfo = is_array($data['order_info'] ?? null) ? $data['order_info'] : [];
        $price = is_array($orderInfo['price'] ?? null) ? $orderInfo['price'] : (is_array($data['price'] ?? null) ? $data['price'] : []);
        $otherFees = is_array($price['others_fees'] ?? null) ? $price['others_fees'] : [];
        $promotions = $this->extractOrderPromotionList($payload);
        $receiveAddress = is_array($data['receive_address'] ?? null) ? $data['receive_address'] : [];
        $deliveryType = $this->normalizeIncomingFood99Value($orderInfo['delivery_type'] ?? $data['delivery_type'] ?? null);
        $payType = $this->normalizeIncomingFood99Value($orderInfo['pay_type'] ?? $data['pay_type'] ?? null);
        $payMethod = $this->normalizeIncomingFood99Value($orderInfo['pay_method'] ?? $data['pay_method'] ?? null);
        $payChannel = $this->normalizeIncomingFood99Value($orderInfo['pay_channel'] ?? $data['pay_channel'] ?? null);
        $storedFinancial = $this->extractFood99StoredSnapshotSection($payload, 'financial');
        $storedPayment = $this->extractFood99StoredSnapshotSection($payload, 'payment');
        $promotionFundingBreakdown = $this->buildPromotionFundingBreakdown($promotions);
        $storeDiscountTotal = $promotionFundingBreakdown['store_total'];
        $itemsDiscountTotal = $this->normalizeFood99Money($price['items_discount'] ?? null);
        $deliveryDiscountTotal = $this->normalizeFood99Money($price['delivery_discount'] ?? null);
        $couponDiscountTotal = $this->normalizeFood99Money($otherFees['coupon_discount'] ?? null);
        $promotionsTotal = $this->sumPromotionTotalDiscount($promotions);
        $originalDeliveryFee = $this->normalizeFood99Money($price['store_charged_delivery_price'] ?? $price['delivery_price'] ?? null);
        $changeFor = $this->normalizeFood99Money($orderInfo['change_for'] ?? $data['change_for'] ?? null);
        $shopPaidMoney = $this->normalizeFood99Money($price['shop_paid_money'] ?? null);

        $itemsTotal = $this->normalizeFood99Money($price['order_price'] ?? null);
        $deliveryFee = $originalDeliveryFee;
        $serviceFee = $this->normalizeFood99Money($otherFees['service_price'] ?? null);
        $smallOrderFee = $this->normalizeFood99Money($otherFees['small_order_price'] ?? null);
        $tipTotal = $this->normalizeFood99Money($otherFees['total_tip_money'] ?? null);
        $mealTopUpFee = $this->normalizeFood99Money($otherFees['meal_top_up_price'] ?? null);
        $subtotalBeforeDiscounts = round($itemsTotal + $deliveryFee + $serviceFee + $smallOrderFee + $tipTotal + $mealTopUpFee, 2);
        $explicitCustomerTotal = $this->normalizeFood99Money(
            $price['customer_need_paying_money'] ?? $price['real_pay_price'] ?? $price['real_price'] ?? null
        );
        $knownDiscountTotal = max(
            $itemsDiscountTotal + $deliveryDiscountTotal + $couponDiscountTotal,
            $promotionsTotal
        );
        $customerTotal = $explicitCustomerTotal > 0
            ? $explicitCustomerTotal
            : round(max(0, $subtotalBeforeDiscounts - $knownDiscountTotal), 2);
        $discountTotal = round(max(0, $subtotalBeforeDiscounts - $customerTotal), 2);
        $platformDiscountTotal = $promotionFundingBreakdown['platform_total'] > 0
            ? $promotionFundingBreakdown['platform_total']
            : round(max(0, $discountTotal - $storeDiscountTotal), 2);
        $isPlatformDelivery = $this->resolveFood99SnapshotBooleanValue(
            $storedPayment,
            $deliveryType === '1',
            'delivery_99_always_paid_rule',
            'is_platform_delivery'
        );
        $isPaidOnline = $this->resolveFood99SnapshotBooleanValue(
            $storedPayment,
            $isPlatformDelivery,
            'is_paid_online'
        );
        $chargeBaseAmount = round(max(0, $itemsTotal - $promotionFundingBreakdown['store_non_delivery_total']), 2);
        $commissionDistributionAmount = $chargeBaseAmount > 0
            ? round($chargeBaseAmount * self::FOOD99_COMMISSION_RATE, 2)
            : 0.0;
        $paymentProcessingAmount = $isPaidOnline && $chargeBaseAmount > 0
            ? round($chargeBaseAmount * self::FOOD99_PAYMENT_PROCESSING_RATE, 2)
            : 0.0;
        $logisticsCostAmount = $isPlatformDelivery && $originalDeliveryFee > 0
            ? max(
                round($originalDeliveryFee * self::FOOD99_LOGISTICS_COST_RATE, 2),
                self::FOOD99_MIN_LOGISTICS_COST
            )
            : 0.0;
        $platformChargesAmount = round(
            $commissionDistributionAmount
                + $paymentProcessingAmount
                + $serviceFee
                + $logisticsCostAmount,
            2
        );
        $weeklySettlementAmount = round(
            max(0, $itemsTotal - $storeDiscountTotal - $platformChargesAmount),
            2
        );
        $paymentTypeLabel = $this->resolveFood99PaymentTypeLabel($payType, $deliveryType);
        $paymentMethodLabel = $this->resolveFood99PaymentMethodLabel($payMethod);
        $paymentChannelLabel = $this->resolveFood99PaymentChannelLabel($payChannel, $payMethod, $deliveryType);
        $selectedPaymentLabel = $this->resolveFood99SelectedPaymentLabel(
            $paymentChannelLabel,
            $paymentTypeLabel,
            $paymentMethodLabel
        );
        $itemsTotal = $this->resolveFood99SnapshotMoneyValue($storedFinancial, $itemsTotal, 'items_total');
        $deliveryFee = $this->resolveFood99SnapshotMoneyValue($storedFinancial, $deliveryFee, 'delivery_fee', 'delivery_fee_amount');
        $serviceFee = $this->resolveFood99SnapshotMoneyValue($storedFinancial, $serviceFee, 'service_fee_amount', 'service_fee');
        $smallOrderFee = $this->resolveFood99SnapshotMoneyValue($storedFinancial, $smallOrderFee, 'small_order_fee_amount', 'small_order_fee');
        $mealTopUpFee = $this->resolveFood99SnapshotMoneyValue($storedFinancial, $mealTopUpFee, 'meal_top_up_fee_amount', 'meal_top_up_fee');
        $tipTotal = $this->resolveFood99SnapshotMoneyValue($storedFinancial, $tipTotal, 'tip_total', 'total_tip_money');
        $subtotalBeforeDiscounts = $this->resolveFood99SnapshotMoneyValue(
            $storedFinancial,
            $subtotalBeforeDiscounts,
            'subtotal_before_discounts'
        );
        $discountTotal = $this->resolveFood99SnapshotMoneyValue($storedFinancial, $discountTotal, 'discount_total');
        $storeDiscountTotal = $this->resolveFood99SnapshotMoneyValue($storedFinancial, $storeDiscountTotal, 'store_discount_total');
        $platformDiscountTotal = $this->resolveFood99SnapshotMoneyValue($storedFinancial, $platformDiscountTotal, 'platform_discount_total');
        $promotionFundingBreakdown['store_non_delivery_total'] = $this->resolveFood99SnapshotMoneyValue(
            $storedFinancial,
            $promotionFundingBreakdown['store_non_delivery_total'],
            'store_non_delivery_discount_total'
        );
        $promotionFundingBreakdown['platform_non_delivery_total'] = $this->resolveFood99SnapshotMoneyValue(
            $storedFinancial,
            $promotionFundingBreakdown['platform_non_delivery_total'],
            'platform_non_delivery_discount_total'
        );
        $promotionFundingBreakdown['store_delivery_total'] = $this->resolveFood99SnapshotMoneyValue(
            $storedFinancial,
            $promotionFundingBreakdown['store_delivery_total'],
            'store_delivery_discount_total'
        );
        $promotionFundingBreakdown['platform_delivery_total'] = $this->resolveFood99SnapshotMoneyValue(
            $storedFinancial,
            $promotionFundingBreakdown['platform_delivery_total'],
            'platform_delivery_discount_total'
        );
        $promotionFundingBreakdown['store_total'] = $this->resolveFood99SnapshotMoneyValue(
            $storedFinancial,
            $promotionFundingBreakdown['store_total'],
            'store_discount_total'
        );
        $promotionFundingBreakdown['platform_total'] = $this->resolveFood99SnapshotMoneyValue(
            $storedFinancial,
            $promotionFundingBreakdown['platform_total'],
            'platform_discount_total'
        );
        $chargeBaseAmount = $this->resolveFood99SnapshotMoneyValue($storedFinancial, $chargeBaseAmount, 'charge_base_amount');
        $commissionDistributionAmount = $this->resolveFood99SnapshotMoneyValue(
            $storedFinancial,
            $commissionDistributionAmount,
            'commission_distribution_amount'
        );
        $paymentProcessingAmount = $this->resolveFood99SnapshotMoneyValue(
            $storedFinancial,
            $paymentProcessingAmount,
            'payment_processing_amount'
        );
        $logisticsCostAmount = $this->resolveFood99SnapshotMoneyValue(
            $storedFinancial,
            $logisticsCostAmount,
            'logistics_cost_amount'
        );
        $platformChargesAmount = $this->resolveFood99SnapshotMoneyValue(
            $storedFinancial,
            $platformChargesAmount,
            'platform_charges_amount'
        );
        $weeklySettlementAmount = $this->resolveFood99SnapshotMoneyValue(
            $storedFinancial,
            $weeklySettlementAmount,
            'weekly_settlement_amount'
        );
        $customerTotal = $this->resolveFood99SnapshotMoneyValue($storedFinancial, $customerTotal, 'customer_total');
        $customerNeedPayingMoney = $this->resolveFood99SnapshotMoneyValue(
            $storedFinancial,
            $this->normalizeFood99Money($price['customer_need_paying_money'] ?? null),
            'customer_need_paying_money'
        );
        $shopPaidMoney = $this->resolveFood99SnapshotMoneyValue($storedFinancial, $shopPaidMoney, 'shop_paid_money');
        $storeReceivableTotal = $this->resolveFood99SnapshotMoneyValue(
            $storedFinancial,
            $this->normalizeFood99Money($price['real_price'] ?? null),
            'store_receivable_total',
            'real_price'
        );
        $realPayTotal = $this->resolveFood99SnapshotMoneyValue(
            $storedFinancial,
            $this->normalizeFood99Money($price['real_pay_price'] ?? null),
            'real_pay_total',
            'real_pay_price'
        );
        $refundTotal = $this->resolveFood99SnapshotMoneyValue(
            $storedFinancial,
            $this->normalizeFood99Money($price['refund_price'] ?? null),
            'refund_total',
            'refund_price'
        );
        $amountPaid = $this->resolveFood99SnapshotMoneyValue($storedPayment, $isPaidOnline ? $customerTotal : 0.0, 'amount_paid');
        $amountPending = $this->resolveFood99SnapshotMoneyValue(
            $storedPayment,
            round(max(0, $customerTotal - $amountPaid), 2),
            'amount_pending'
        );
        $collectOnDeliveryAmount = $this->resolveFood99SnapshotMoneyValue(
            $storedPayment,
            $isPaidOnline ? 0.0 : ($customerNeedPayingMoney ?: $customerTotal),
            'collect_on_delivery_amount'
        );
        $changeFor = $this->resolveFood99SnapshotMoneyValue($storedPayment, $changeFor, 'change_for');
        $changeAmount = $this->resolveFood99SnapshotMoneyValue(
            $storedPayment,
            $changeFor > 0 && $customerNeedPayingMoney > 0
                ? round(max(0, $changeFor - $customerNeedPayingMoney), 2)
                : 0.0,
            'change_amount'
        );
        $needsChange = $this->resolveFood99SnapshotBooleanValue($storedPayment, $changeAmount > 0.009, 'needs_change');
        $isFullyPaid = $this->resolveFood99SnapshotBooleanValue($storedPayment, $amountPending <= 0.009, 'is_fully_paid');
        $shouldConfirmPayment = $this->resolveFood99SnapshotBooleanValue($storedPayment, !$isPaidOnline, 'should_confirm_payment');

        return [
            'financial' => [
                'currency' => 'BRL',
                'items_total' => $itemsTotal,
                'delivery_fee' => $deliveryFee,
                'service_fee' => $serviceFee,
                'small_order_fee' => $smallOrderFee,
                'meal_top_up_fee' => $mealTopUpFee,
                'tip_total' => $tipTotal,
                'subtotal_before_discounts' => $subtotalBeforeDiscounts,
                'discount_total' => $discountTotal,
                'store_discount_total' => $storeDiscountTotal,
                'platform_discount_total' => $platformDiscountTotal,
                'store_non_delivery_discount_total' => $promotionFundingBreakdown['store_non_delivery_total'],
                'platform_non_delivery_discount_total' => $promotionFundingBreakdown['platform_non_delivery_total'],
                'store_delivery_discount_total' => $promotionFundingBreakdown['store_delivery_total'],
                'platform_delivery_discount_total' => $promotionFundingBreakdown['platform_delivery_total'],
                'charge_base_amount' => $chargeBaseAmount,
                'commission_distribution_amount' => $commissionDistributionAmount,
                'payment_processing_amount' => $paymentProcessingAmount,
                'logistics_cost_amount' => $logisticsCostAmount,
                'platform_charges_amount' => $platformChargesAmount,
                'weekly_settlement_amount' => $weeklySettlementAmount,
                'promotions_total' => $promotionsTotal,
                'items_discount_total' => $itemsDiscountTotal,
                'delivery_discount_total' => $deliveryDiscountTotal,
                'coupon_discount_total' => $couponDiscountTotal,
                'customer_total' => $customerTotal,
                'customer_need_paying_money' => $customerNeedPayingMoney,
                'store_receivable_total' => $storeReceivableTotal,
                'real_pay_total' => $realPayTotal,
                'refund_total' => $refundTotal,
                'store_charged_delivery_price' => $originalDeliveryFee,
                'shop_paid_money' => $shopPaidMoney,
            ],
            'payment' => [
                'pay_type' => $payType,
                'pay_type_label' => $paymentTypeLabel,
                'pay_method' => $payMethod,
                'pay_method_label' => $paymentMethodLabel,
                'pay_channel' => $payChannel,
                'pay_channel_label' => $paymentChannelLabel,
                'selected_payment_label' => $selectedPaymentLabel,
                'amount_paid' => $amountPaid,
                'amount_pending' => $amountPending,
                'customer_need_paying_money' => $customerNeedPayingMoney,
                'collect_on_delivery_amount' => $collectOnDeliveryAmount,
                'shop_paid_money' => $shopPaidMoney,
                'change_for' => $changeFor,
                'change_amount' => $changeAmount,
                'needs_change' => $needsChange,
                'is_fully_paid' => $isFullyPaid,
                'should_confirm_payment' => $shouldConfirmPayment,
                'is_paid_online' => $isPaidOnline,
                'delivery_99_always_paid_rule' => $isPlatformDelivery,
            ],
            'customer' => [
                'name' => $this->resolveFood99CustomerName($receiveAddress, ''),
                'phone' => $this->normalizeIncomingFood99Value($receiveAddress['phone'] ?? null),
            ],
            'address' => [
                'display' => $this->buildFood99AddressDisplay($receiveAddress),
                'street_name' => $this->normalizeIncomingFood99Value($receiveAddress['street_name'] ?? null),
                'street_number' => $this->normalizeIncomingFood99Value($receiveAddress['street_number'] ?? null),
                'district' => $this->normalizeIncomingFood99Value($receiveAddress['district'] ?? null),
                'city' => $this->normalizeIncomingFood99Value($receiveAddress['city'] ?? null),
                'state' => $this->normalizeIncomingFood99Value($receiveAddress['state'] ?? null),
                'postal_code' => $this->normalizeIncomingFood99Value($receiveAddress['postal_code'] ?? null),
                'reference' => $this->normalizeIncomingFood99Value($receiveAddress['reference'] ?? null),
                'complement' => $this->normalizeIncomingFood99Value($receiveAddress['complement'] ?? null),
                'poi_address' => $this->normalizeIncomingFood99Value($receiveAddress['poi_address'] ?? null),
            ],
            'notes' => [
                'remark' => $this->normalizeIncomingFood99Value($orderInfo['remark'] ?? $data['remark'] ?? null),
                'need_cutlery' => $this->normalizeFood99Boolean($orderInfo['need_cutlery'] ?? $data['need_cutlery'] ?? null),
            ],
            'identifiers' => [
                'remote_order_id' => $this->normalizeIncomingFood99Value($orderInfo['order_id'] ?? $data['order_id'] ?? null),
                'order_index' => $this->normalizeIncomingFood99Value($orderInfo['order_index'] ?? $data['order_index'] ?? null),
                'delivery_type' => $deliveryType,
                'pickup_code' => $this->normalizeIncomingFood99Value($data['pickup_code'] ?? $orderInfo['pickup_code'] ?? null),
                'handover_code' => $this->normalizeIncomingFood99Value($data['handover_code'] ?? $orderInfo['handover_code'] ?? null),
            ],
            'raw_payload_available' => true,
        ];
    }
}
