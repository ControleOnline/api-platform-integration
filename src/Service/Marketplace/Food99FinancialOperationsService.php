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
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

class Food99FinancialOperationsService extends AbstractMarketplaceService
{
    private const APP_CONTEXT = Order::APP_FOOD99;

    private ?Food99PeopleOperationsService $food99PeopleOperationsService = null;

    protected function getMarketplaceApp(): string
    {
        return self::APP_CONTEXT;
    }

    #[Required]
    public function setFood99PeopleOperationsService(Food99PeopleOperationsService $food99PeopleOperationsService): void
    {
        $this->food99PeopleOperationsService = $food99PeopleOperationsService;
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

    private function resolveFood99StoredMoneyValue(array $source, string ...$keys): float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }

            $candidate = $source[$key];
            if ($candidate === null || $candidate === '') {
                continue;
            }

            return $this->normalizeFood99Money($candidate);
        }

        return 0.0;
    }

    private function resolveFood99StoredBooleanValue(array $source, ?bool $fallback, string ...$keys): bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }

            $candidate = $source[$key];
            if ($candidate === null || $candidate === '') {
                continue;
            }

            $value = $this->normalizeFood99Boolean($candidate);
            if ($value !== null) {
                return $value;
            }
        }

        return (bool) $fallback;
    }

    public function decodeOrderOtherInformationsValue(mixed $value): array
    {
        return $this->decodeEntityOtherInformationsValue($value);
    }

    public function getDecodedOrderOtherInformations(Order $order): array
    {
        return $this->getDecodedEntityOtherInformations($order);
    }

    public function resolveBestStoredOrderPayload(Order $order): array
    {
        $otherInformations = $this->getDecodedEntityOtherInformations($order);
        $candidate = $otherInformations[self::APP_CONTEXT] ?? null;
        if (is_string($candidate)) {
            $candidate = $this->decodeEntityOtherInformationsValue($candidate);
        }

        if (!is_array($candidate) || empty($candidate)) {
            return [];
        }

        $latestEventType = $this->normalizeIncomingFood99Value(
            $candidate['latest_event_type'] ?? $candidate['latestEventType'] ?? null
        );

        return $this->resolveBestPayloadFromStoredOrderCandidate($candidate, $latestEventType);
    }

    public function resolveFood99CustomerName(array $address, string $fallback = 'Cliente Food99'): string
    {
        $peopleService = $this->food99PeopleOperationsService;
        if ($peopleService instanceof Food99PeopleOperationsService) {
            $name = $peopleService->resolveFood99CustomerName($address, $fallback);

            return is_string($name) && $name !== '' ? $name : $fallback;
        }

        return $fallback;
    }

    public function extractFood99StoredSnapshotSection(array $payload, string $section): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $orderInfo = is_array($data['order_info'] ?? null) ? $data['order_info'] : [];

        foreach ([$payload[$section] ?? null, $data[$section] ?? null, $orderInfo[$section] ?? null] as $candidate) {
            if (is_array($candidate) && $candidate !== []) {
                return $candidate;
            }

            if (is_string($candidate)) {
                $decodedCandidate = $this->decodeEntityOtherInformationsValue($candidate);
                if (is_array($decodedCandidate) && $decodedCandidate !== []) {
                    return $decodedCandidate;
                }
            }
        }

        return [];
    }

    public function buildFood99AddressDisplay(array $address): ?string
    {
        $service = $this->food99PeopleOperationsService;
        if ($service instanceof Food99PeopleOperationsService) {
            $display = $service->buildFood99AddressDisplay($address);

            return is_string($display) && $display !== '' ? $display : null;
        }

        return null;
    }

    public function resolveFood99PaymentTypeLabel(?string $payType, ?string $deliveryType): string
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

    public function resolveFood99PaymentMethodLabel(?string $payMethod): string
    {
        return match (trim((string) $payMethod)) {
            '1' => 'Pagamento online',
            '2' => 'Pagamento offline',
            '0' => 'Nao informado pela 99',
            default => 'Metodo nao mapeado',
        };
    }

    public function resolveFood99PaymentChannelLabel(?string $payChannel, ?string $payMethod, ?string $deliveryType): string
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

    public function resolveFood99SelectedPaymentLabel(
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

    public function resolveFood99ProviderPaymentType(People $provider, array $paymentTypeData, ?Wallet $wallet = null): PaymentType
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

    public function createFood99PayableInvoice(
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

    public function resolveFood99MarketplacePeople(): People
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

    public function resolveFood99WeeklyDueDate(Order $order): DateTime
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
        $receiveAddress = is_array($data['receive_address'] ?? null) ? $data['receive_address'] : [];
        $deliveryType = $this->normalizeIncomingFood99Value($orderInfo['delivery_type'] ?? $data['delivery_type'] ?? null);
        $payType = $this->normalizeIncomingFood99Value($orderInfo['pay_type'] ?? $data['pay_type'] ?? null);
        $payMethod = $this->normalizeIncomingFood99Value($orderInfo['pay_method'] ?? $data['pay_method'] ?? null);
        $payChannel = $this->normalizeIncomingFood99Value($orderInfo['pay_channel'] ?? $data['pay_channel'] ?? null);
        $storedFinancial = $this->extractFood99StoredSnapshotSection($payload, 'financial');
        $storedPayment = $this->extractFood99StoredSnapshotSection($payload, 'payment');
        $paymentTypeLabel = $this->resolveFood99PaymentTypeLabel($payType, $deliveryType);
        $paymentMethodLabel = $this->resolveFood99PaymentMethodLabel($payMethod);
        $paymentChannelLabel = $this->resolveFood99PaymentChannelLabel($payChannel, $payMethod, $deliveryType);
        $selectedPaymentLabel = $this->resolveFood99SelectedPaymentLabel(
            $paymentChannelLabel,
            $paymentTypeLabel,
            $paymentMethodLabel
        );
        $storedCustomer = $this->extractFood99StoredSnapshotSection($payload, 'customer');
        $storedAddress = $this->extractFood99StoredSnapshotSection($payload, 'address');
        $storedNotes = $this->extractFood99StoredSnapshotSection($payload, 'notes');
        $storedIdentifiers = $this->extractFood99StoredSnapshotSection($payload, 'identifiers');

        $itemsTotal = $this->resolveFood99StoredMoneyValue($storedFinancial, 'items_total');
        $deliveryFee = $this->resolveFood99StoredMoneyValue(
            $storedFinancial,
            'delivery_fee',
            'delivery_fee_amount',
            'store_charged_delivery_price'
        );
        $serviceFee = $this->resolveFood99StoredMoneyValue($storedFinancial, 'service_fee', 'service_fee_amount');
        $smallOrderFee = $this->resolveFood99StoredMoneyValue($storedFinancial, 'small_order_fee', 'small_order_fee_amount');
        $mealTopUpFee = $this->resolveFood99StoredMoneyValue($storedFinancial, 'meal_top_up_fee', 'meal_top_up_fee_amount');
        $tipTotal = $this->resolveFood99StoredMoneyValue($storedFinancial, 'tip_total', 'total_tip_money');
        $subtotalBeforeDiscounts = $this->resolveFood99StoredMoneyValue($storedFinancial, 'subtotal_before_discounts');
        $discountTotal = $this->resolveFood99StoredMoneyValue($storedFinancial, 'discount_total');
        $storeDiscountTotal = $this->resolveFood99StoredMoneyValue($storedFinancial, 'store_discount_total');
        $platformDiscountTotal = $this->resolveFood99StoredMoneyValue($storedFinancial, 'platform_discount_total');
        $storeNonDeliveryDiscountTotal = $this->resolveFood99StoredMoneyValue(
            $storedFinancial,
            'store_non_delivery_discount_total'
        );
        $platformNonDeliveryDiscountTotal = $this->resolveFood99StoredMoneyValue(
            $storedFinancial,
            'platform_non_delivery_discount_total'
        );
        $storeDeliveryDiscountTotal = $this->resolveFood99StoredMoneyValue(
            $storedFinancial,
            'store_delivery_discount_total'
        );
        $platformDeliveryDiscountTotal = $this->resolveFood99StoredMoneyValue(
            $storedFinancial,
            'platform_delivery_discount_total'
        );
        $chargeBaseAmount = $this->resolveFood99StoredMoneyValue($storedFinancial, 'charge_base_amount');
        $commissionDistributionAmount = $this->resolveFood99StoredMoneyValue(
            $storedFinancial,
            'commission_distribution_amount'
        );
        $paymentProcessingAmount = $this->resolveFood99StoredMoneyValue(
            $storedFinancial,
            'payment_processing_amount'
        );
        $logisticsCostAmount = $this->resolveFood99StoredMoneyValue(
            $storedFinancial,
            'logistics_cost_amount'
        );
        $platformChargesAmount = $this->resolveFood99StoredMoneyValue(
            $storedFinancial,
            'platform_charges_amount'
        );
        $weeklySettlementAmount = $this->resolveFood99StoredMoneyValue(
            $storedFinancial,
            'weekly_settlement_amount'
        );
        $promotionsTotal = $this->resolveFood99StoredMoneyValue($storedFinancial, 'promotions_total');
        $itemsDiscountTotal = $this->resolveFood99StoredMoneyValue($storedFinancial, 'items_discount_total');
        $deliveryDiscountTotal = $this->resolveFood99StoredMoneyValue($storedFinancial, 'delivery_discount_total');
        $couponDiscountTotal = $this->resolveFood99StoredMoneyValue($storedFinancial, 'coupon_discount_total');
        $customerTotal = $this->resolveFood99StoredMoneyValue($storedFinancial, 'customer_total');
        $customerNeedPayingMoney = $this->resolveFood99StoredMoneyValue(
            $storedFinancial,
            'customer_need_paying_money',
            'customer_need_paying_money_amount'
        );
        $storeReceivableTotal = $this->resolveFood99StoredMoneyValue($storedFinancial, 'store_receivable_total', 'real_price');
        $realPayTotal = $this->resolveFood99StoredMoneyValue($storedFinancial, 'real_pay_total', 'real_pay_price');
        $refundTotal = $this->resolveFood99StoredMoneyValue($storedFinancial, 'refund_total', 'refund_price');
        $storeChargedDeliveryPrice = $this->resolveFood99StoredMoneyValue(
            $storedFinancial,
            'store_charged_delivery_price',
            'delivery_fee',
            'delivery_fee_amount'
        );
        $shopPaidMoney = $this->resolveFood99StoredMoneyValue($storedFinancial, 'shop_paid_money');
        $amountPaid = $this->resolveFood99StoredMoneyValue($storedPayment, 'amount_paid');
        $amountPending = $this->resolveFood99StoredMoneyValue($storedPayment, 'amount_pending');
        $collectOnDeliveryAmount = $this->resolveFood99StoredMoneyValue($storedPayment, 'collect_on_delivery_amount');
        $changeFor = $this->resolveFood99StoredMoneyValue($storedPayment, 'change_for');
        $changeAmount = $this->resolveFood99StoredMoneyValue($storedPayment, 'change_amount');
        $isPaidOnline = $this->resolveFood99StoredBooleanValue($storedPayment, false, 'is_paid_online');
        $isPlatformDelivery = $this->resolveFood99StoredBooleanValue(
            $storedPayment,
            false,
            'delivery_99_always_paid_rule',
            'is_platform_delivery'
        );
        $needsChange = $this->resolveFood99StoredBooleanValue($storedPayment, false, 'needs_change');
        $isFullyPaid = $this->resolveFood99StoredBooleanValue($storedPayment, false, 'is_fully_paid');
        $shouldConfirmPayment = $this->resolveFood99StoredBooleanValue($storedPayment, false, 'should_confirm_payment');

        return [
            'financial' => [
                'currency' => $this->normalizeIncomingFood99Value($storedFinancial['currency'] ?? 'BRL') ?: 'BRL',
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
                'store_non_delivery_discount_total' => $storeNonDeliveryDiscountTotal,
                'platform_non_delivery_discount_total' => $platformNonDeliveryDiscountTotal,
                'store_delivery_discount_total' => $storeDeliveryDiscountTotal,
                'platform_delivery_discount_total' => $platformDeliveryDiscountTotal,
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
                'store_charged_delivery_price' => $storeChargedDeliveryPrice,
                'shop_paid_money' => $shopPaidMoney,
            ],
            'payment' => [
                'pay_type' => $this->normalizeIncomingFood99Value($storedPayment['pay_type'] ?? $payType),
                'pay_type_label' => $paymentTypeLabel,
                'pay_method' => $this->normalizeIncomingFood99Value($storedPayment['pay_method'] ?? $payMethod),
                'pay_method_label' => $paymentMethodLabel,
                'pay_channel' => $this->normalizeIncomingFood99Value($storedPayment['pay_channel'] ?? $payChannel),
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
                'name' => $this->normalizeIncomingFood99Value($storedCustomer['name'] ?? '') ?: $this->resolveFood99CustomerName($receiveAddress, ''),
                'phone' => $this->normalizeIncomingFood99Value($receiveAddress['phone'] ?? null),
            ],
            'address' => [
                'display' => $this->normalizeIncomingFood99Value($storedAddress['display'] ?? '') ?: $this->buildFood99AddressDisplay($receiveAddress),
                'street_name' => $this->normalizeIncomingFood99Value($storedAddress['street_name'] ?? $receiveAddress['street_name'] ?? null),
                'street_number' => $this->normalizeIncomingFood99Value($storedAddress['street_number'] ?? $receiveAddress['street_number'] ?? null),
                'district' => $this->normalizeIncomingFood99Value($storedAddress['district'] ?? $receiveAddress['district'] ?? null),
                'city' => $this->normalizeIncomingFood99Value($storedAddress['city'] ?? $receiveAddress['city'] ?? null),
                'state' => $this->normalizeIncomingFood99Value($storedAddress['state'] ?? $receiveAddress['state'] ?? null),
                'postal_code' => $this->normalizeIncomingFood99Value($storedAddress['postal_code'] ?? $receiveAddress['postal_code'] ?? null),
                'reference' => $this->normalizeIncomingFood99Value($storedAddress['reference'] ?? $receiveAddress['reference'] ?? null),
                'complement' => $this->normalizeIncomingFood99Value($storedAddress['complement'] ?? $receiveAddress['complement'] ?? null),
                'poi_address' => $this->normalizeIncomingFood99Value($storedAddress['poi_address'] ?? $receiveAddress['poi_address'] ?? null),
            ],
            'notes' => [
                'remark' => $this->normalizeIncomingFood99Value($storedNotes['remark'] ?? $orderInfo['remark'] ?? $data['remark'] ?? null),
                'need_cutlery' => $this->normalizeFood99Boolean($storedNotes['need_cutlery'] ?? $orderInfo['need_cutlery'] ?? $data['need_cutlery'] ?? null),
            ],
            'identifiers' => [
                'remote_order_id' => $this->normalizeIncomingFood99Value($storedIdentifiers['remote_order_id'] ?? $orderInfo['order_id'] ?? $data['order_id'] ?? null),
                'order_index' => $this->normalizeIncomingFood99Value($storedIdentifiers['order_index'] ?? $orderInfo['order_index'] ?? $data['order_index'] ?? null),
                'delivery_type' => $this->normalizeIncomingFood99Value($storedIdentifiers['delivery_type'] ?? $deliveryType),
                'pickup_code' => $this->normalizeIncomingFood99Value($storedIdentifiers['pickup_code'] ?? $data['pickup_code'] ?? $orderInfo['pickup_code'] ?? null),
                'handover_code' => $this->normalizeIncomingFood99Value($storedIdentifiers['handover_code'] ?? $data['handover_code'] ?? $orderInfo['handover_code'] ?? null),
            ],
            'raw_payload_available' => true,
        ];
    }
}
