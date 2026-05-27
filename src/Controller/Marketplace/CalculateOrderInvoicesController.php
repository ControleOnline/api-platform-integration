<?php

namespace ControleOnline\Controller\Marketplace;

use ControleOnline\Entity\Invoice;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\PaymentType;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Wallet;
use ControleOnline\Service\Food99Service;
use ControleOnline\Service\MarketplaceOrderFinancialGenerationService;
use ControleOnline\Service\OrderService;
use ControleOnline\Service\PeopleService;
use ControleOnline\Service\RequestPayloadService;
use ControleOnline\Service\StatusService;
use ControleOnline\Service\WalletService;
use DateTime;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface as Security;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;

#[SecurityAttribute("is_granted('ROLE_HUMAN')")]
class CalculateOrderInvoicesController extends AbstractController
{
    private const PURPOSE_CUSTOMER_MARKETPLACE_PAYMENT = 'customer_marketplace_payment';
    private const PURPOSE_CUSTOMER_COLLECTION = 'customer_collection';
    private const PURPOSE_COURIER_PAYMENT = 'courier_payment';
    private const IFOOD_DOCUMENT = '14380200000121';
    private const IFOOD_NAME = 'Ifood.com Agência de Restaurantes Online S.A';
    private const FOOD99_DOCUMENT = '6012920000123';
    private const FOOD99_NAME = '99 Food';

    public function __construct(
        private EntityManagerInterface $manager,
        private Security $security,
        private PeopleService $peopleService,
        private RequestPayloadService $requestPayloadService,
        private MarketplaceOrderFinancialGenerationService $marketplaceOrderFinancialGenerationService,
        private WalletService $walletService,
        private StatusService $statusService,
        private OrderService $orderService,
        private Food99Service $food99Service,
    ) {}

    private function getAuthenticatedPeople(): ?People
    {
        $user = $this->security->getToken()?->getUser();

        if (!is_object($user) || !method_exists($user, 'getPeople')) {
            return null;
        }

        $people = $user->getPeople();

        return $people instanceof People ? $people : null;
    }

    private function canAccessProvider(People $provider): bool
    {
        $userPeople = $this->getAuthenticatedPeople();
        if (!$userPeople) {
            return false;
        }

        if ($userPeople->getId() === $provider->getId()) {
            return true;
        }

        return $this->peopleService->canAccessCompany($provider, $userPeople);
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

        if (!$this->canAccessProvider($provider)) {
            return null;
        }

        return $order;
    }

    private function isSupportedMarketplaceOrder(Order $order): bool
    {
        $normalizedApp = strtolower(trim((string) $order->getApp()));

        return in_array(
            $normalizedApp,
            [
                strtolower(Order::APP_IFOOD),
                strtolower(Order::APP_FOOD99),
            ],
            true
        );
    }

    #[Route('/marketplace/integrations/orders/{orderId}/invoices/calculate', name: 'marketplace_integrations_order_calculate_invoices', methods: ['POST'])]
    public function __invoke(string $orderId): JsonResponse
    {
        $order = $this->resolveOrder($orderId);
        if (!$order) {
            return new JsonResponse([
                'error' => 'Order not found or access denied',
            ], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isSupportedMarketplaceOrder($order)) {
            return new JsonResponse([
                'error' => 'Order is not linked to a supported marketplace integration',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->generateFromStoredSnapshot($order);
        } catch (\RuntimeException $exception) {
            return new JsonResponse([
                'error' => $exception->getMessage(),
            ], Response::HTTP_CONFLICT);
        }

        return new JsonResponse([
            'success' => true,
            'message' => 'Invoices populadas com sucesso a partir do snapshot salvo no pedido.',
            'data' => $result,
        ], Response::HTTP_OK);
    }

    private function generateFromStoredSnapshot(Order $order): array
    {
        $financialOrder = $this->orderService->resolveFinancialOrder($order);
        $connection = $this->manager->getConnection();
        $connection->beginTransaction();

        try {
            if ($this->shouldSkipFood99FinancialGeneration($financialOrder)) {
                $app = Order::APP_FOOD99;
                $this->invokeGeneratorMethod('assertNoLegacyMarketplaceInvoices', $financialOrder, $app);
                $removedInvoices = $this->invokeGeneratorMethod('purgeManagedMarketplaceInvoices', $financialOrder, $app);
                $this->manager->flush();
                $connection->commit();

                return [
                    'order_id' => $financialOrder->getId(),
                    'app' => $app,
                    'wallet' => self::FOOD99_NAME,
                    'due_date' => $this->resolveWeeklyDueDate($financialOrder)->format('Y-m-d'),
                    'removed_invoice_ids' => $removedInvoices,
                    'invoices' => [],
                    'summary' => [
                        'weekly_settlement_amount' => 0.0,
                        'customer_collection_amount' => 0.0,
                        'customer_marketplace_payment_amount' => 0.0,
                        'courier_payment_amount' => 0.0,
                        'service_fee_amount' => 0.0,
                        'small_order_fee_amount' => 0.0,
                        'meal_top_up_fee_amount' => 0.0,
                        'commission_distribution_amount' => 0.0,
                        'payment_processing_amount' => 0.0,
                        'logistics_cost_amount' => 0.0,
                        'merchant_discount_amount' => 0.0,
                        'platform_discount_amount' => 0.0,
                    ],
                    'warnings' => ['Pedido cancelado; financeiro do marketplace nao foi gerado.'],
                ];
            }

            $context = $this->buildContextFromStoredSnapshot($financialOrder);
            $this->invokeGeneratorMethod('assertNoLegacyMarketplaceInvoices', $financialOrder, $context['app']);
            $removedInvoices = $this->invokeGeneratorMethod(
                'purgeManagedMarketplaceInvoices',
                $financialOrder,
                $context['app']
            );
            $this->manager->flush();
            $createdInvoices = [];
            $warnings = [];

            if ($context['customer_collection_amount'] > 0) {
                $customer = $financialOrder->getPayer() instanceof People
                    ? $financialOrder->getPayer()
                    : $financialOrder->getClient();

                if ($customer instanceof People) {
                    $createdInvoices[] = $this->invokeGeneratorMethod(
                        'createSingleOrderInvoice',
                        $financialOrder,
                        $context,
                        self::PURPOSE_CUSTOMER_COLLECTION,
                        $customer,
                        $financialOrder->getProvider(),
                        $context['customer_collection_amount'],
                        $context['pending_status'],
                        $this->resolveOrderReferenceDate($financialOrder),
                        null,
                        $context['provider_wallet'],
                        sprintf(
                            'Recebimento na entrega do pedido #%s via %s',
                            (string) $financialOrder->getId(),
                            $context['marketplace_label']
                        ),
                        [
                            'financial_kind' => 'account_receivable',
                            'settled_by' => 'customer',
                        ],
                    );
                } else {
                    $warnings[] = 'Pedido sem cliente/pagador para gerar invoice de cobranca na entrega.';
                }
            }

            if ($context['customer_marketplace_payment_amount'] > 0) {
                $customer = $financialOrder->getPayer() instanceof People
                    ? $financialOrder->getPayer()
                    : $financialOrder->getClient();

                if ($customer instanceof People) {
                    $createdInvoices[] = $this->invokeGeneratorMethod(
                        'createSingleOrderInvoice',
                        $financialOrder,
                        $context,
                        self::PURPOSE_CUSTOMER_MARKETPLACE_PAYMENT,
                        $customer,
                        $context['marketplace_people'],
                        $context['customer_marketplace_payment_amount'],
                        $context['paid_status'],
                        $this->resolveOrderReferenceDate($financialOrder),
                        null,
                        $context['marketplace_wallet'],
                        sprintf(
                            'Pagamento online do cliente para %s no pedido #%s',
                            $context['marketplace_label'],
                            (string) $financialOrder->getId()
                        ),
                        [
                            'financial_kind' => 'marketplace_customer_payment',
                            'settled_by' => 'customer',
                        ],
                    );
                } else {
                    $warnings[] = 'Pedido sem cliente/pagador para gerar invoice de pagamento online ao marketplace.';
                }
            }

            if ($context['weekly_settlement_amount'] > 0) {
                $createdInvoices[] = $this->invokeGeneratorMethod(
                    'syncWeeklySettlementInvoice',
                    $financialOrder,
                    $context
                );
            }

            foreach ($this->invokeGeneratorMethod('buildProviderPayables', $financialOrder, $context) as $payable) {
                if (($payable['amount'] ?? 0) <= 0) {
                    continue;
                }

                $createdInvoices[] = $this->invokeGeneratorMethod(
                    'createSingleOrderInvoice',
                    $financialOrder,
                    $context,
                    (string) $payable['purpose'],
                    $financialOrder->getProvider(),
                    $context['marketplace_people'],
                    (float) $payable['amount'],
                    $context['paid_status'],
                    $this->resolveOrderReferenceDate($financialOrder),
                    $context['provider_wallet'],
                    $context['marketplace_wallet'],
                    (string) $payable['description'],
                    [
                        'financial_kind' => 'account_payable',
                        'settled_by' => 'marketplace_offset',
                    ],
                );
            }

            foreach ($this->invokeGeneratorMethod('buildMarketplaceOffsets', $financialOrder, $context) as $offset) {
                if (($offset['amount'] ?? 0) <= 0) {
                    continue;
                }

                $createdInvoices[] = $this->invokeGeneratorMethod(
                    'createSingleOrderInvoice',
                    $financialOrder,
                    $context,
                    (string) $offset['purpose'],
                    $context['marketplace_people'],
                    $context['marketplace_people'],
                    (float) $offset['amount'],
                    $context['paid_status'],
                    $this->resolveOrderReferenceDate($financialOrder),
                    $context['marketplace_wallet'],
                    $context['marketplace_wallet'],
                    (string) $offset['description'],
                    [
                        'financial_kind' => 'marketplace_internal_offset',
                        'settled_by' => 'marketplace_offset',
                    ],
                );
            }

            if ($context['courier_payment_amount'] > 0) {
                $courier = $this->resolveCourierPeople($context);
                if ($courier instanceof People) {
                    $courierWallet = $this->walletService->discoverWallet($courier, $context['wallet_name']);
                    $createdInvoices[] = $this->invokeGeneratorMethod(
                        'createSingleOrderInvoice',
                        $financialOrder,
                        $context,
                        self::PURPOSE_COURIER_PAYMENT,
                        $context['marketplace_people'],
                        $courier,
                        $context['courier_payment_amount'],
                        $context['paid_status'],
                        $this->resolveOrderReferenceDate($financialOrder),
                        $context['marketplace_wallet'],
                        $courierWallet,
                        sprintf(
                            'Pagamento do motoboy do pedido #%s pela %s',
                            (string) $financialOrder->getId(),
                            $context['marketplace_label']
                        ),
                        [
                            'financial_kind' => 'account_payable',
                            'settled_by' => 'marketplace',
                            'courier_name' => $context['courier']['name'] ?? null,
                            'courier_phone' => $context['courier']['phone'] ?? null,
                        ],
                    );
                } else {
                    $warnings[] = 'Pedido sem motoboy identificavel para gerar invoice de pagamento ao entregador.';
                }
            }

            $this->manager->flush();
            $connection->commit();

            return [
                'order_id' => $financialOrder->getId(),
                'app' => $context['app'],
                'wallet' => $context['wallet_name'],
                'due_date' => $context['weekly_due_date']->format('Y-m-d'),
                'removed_invoice_ids' => $removedInvoices,
                'invoices' => array_map(fn(Invoice $invoice) => $this->serializeInvoice($invoice), $createdInvoices),
                'summary' => [
                    'weekly_settlement_amount' => $context['weekly_settlement_amount'],
                    'customer_collection_amount' => $context['customer_collection_amount'],
                    'customer_marketplace_payment_amount' => $context['customer_marketplace_payment_amount'],
                    'courier_payment_amount' => $context['courier_payment_amount'],
                    'service_fee_amount' => $context['service_fee_amount'],
                    'small_order_fee_amount' => $context['small_order_fee_amount'],
                    'meal_top_up_fee_amount' => $context['meal_top_up_fee_amount'],
                    'commission_distribution_amount' => $context['commission_distribution_amount'],
                    'payment_processing_amount' => $context['payment_processing_amount'],
                    'logistics_cost_amount' => $context['logistics_cost_amount'],
                    'merchant_discount_amount' => $context['merchant_discount_amount'],
                    'platform_discount_amount' => $context['platform_discount_amount'],
                ],
                'warnings' => $warnings,
            ];
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    private function buildContextFromStoredSnapshot(Order $order): array
    {
        $normalizedApp = strtolower(trim((string) $order->getApp()));
        $storedSnapshot = $this->extractStoredSnapshot($order);
        $financial = is_array($storedSnapshot['financial'] ?? null) ? $storedSnapshot['financial'] : [];
        $payment = is_array($storedSnapshot['payment'] ?? null) ? $storedSnapshot['payment'] : [];
        $delivery = is_array($storedSnapshot['delivery'] ?? null) ? $storedSnapshot['delivery'] : [];

        $this->assertStoredMarketplaceSnapshotIsUsable($order, $financial, $payment);
        $this->assertMarketplaceFinancialSnapshotIsUsable($order, $financial, $payment);

        if ($normalizedApp === strtolower(Order::APP_FOOD99)) {
            $marketplacePeople = $this->peopleService->discoveryPeople(
                self::FOOD99_DOCUMENT,
                null,
                [],
                self::FOOD99_NAME,
                'J'
            );
            $walletName = self::FOOD99_NAME;
            $marketplaceLabel = self::FOOD99_NAME;
            $providerWallet = $this->resolveFood99SettlementWallet($order->getProvider());
        } else {
            $marketplacePeople = $this->peopleService->discoveryPeople(
                self::IFOOD_DOCUMENT,
                null,
                [],
                self::IFOOD_NAME,
                'J'
            );
            $walletName = Order::APP_IFOOD;
            $marketplaceLabel = Order::APP_IFOOD;
            $providerWallet = $this->walletService->discoverWallet($order->getProvider(), $walletName);
        }

        $marketplaceWallet = $this->walletService->discoverWallet($marketplacePeople, $walletName);
        $pendingStatus = $this->statusService->discoveryStatus('pending', 'waiting payment', 'invoice');
        $paidStatus = $this->statusService->discoveryStatus('closed', 'paid', 'invoice');

        $isFood99Order = $normalizedApp === strtolower(Order::APP_FOOD99);
        $platformDiscountAmount = $this->money(
            $financial['platform_discount_total']
                ?? $financial['ifood_subsidy']
                ?? 0
        );
        $merchantDiscountAmount = $this->money(
            $financial['store_discount_total']
                ?? $financial['merchant_subsidy']
                ?? 0
        );
        $serviceFeeAmount = $this->money(
            $financial['service_fee_amount']
                ?? $financial['service_fee']
                ?? 0
        );
        $smallOrderFeeAmount = $this->money(
            $financial['small_order_fee_amount']
                ?? $financial['small_order_fee']
                ?? 0
        );
        $mealTopUpFeeAmount = $this->money(
            $financial['meal_top_up_fee_amount']
                ?? $financial['meal_top_up_fee']
                ?? 0
        );
        $customerTotal = $this->money($financial['customer_total'] ?? 0);
        $amountPaid = $this->money($payment['amount_paid'] ?? 0);
        $collectOnDeliveryAmount = $this->money(
            $payment['collect_on_delivery_amount']
                ?? $payment['customer_need_paying_money']
                ?? $financial['customer_need_paying_money']
                ?? 0
        );
        $isPaidOnline = $this->toBool($payment['is_paid_online'] ?? false);
        $isPlatformDelivery = $this->toBool($delivery['is_platform_delivery'] ?? false);
        $food99DerivedFinancials = $isFood99Order ? $this->resolveFood99DerivedFinancials($financial) : [];
        $commissionDistributionAmount = $this->money(
            $food99DerivedFinancials['commission_distribution_amount'] ?? 0
        );
        $paymentProcessingAmount = $this->money(
            $food99DerivedFinancials['payment_processing_amount'] ?? 0
        );
        $logisticsCostAmount = $this->money(
            $food99DerivedFinancials['logistics_cost_amount'] ?? 0
        );
        $weeklySettlementAmount = $this->money(
            $financial['weekly_settlement_amount']
                ?? $financial['store_receivable_total']
                ?? $financial['real_price']
                ?? 0
        );
        $invoicePaymentType = $this->resolveMarketplaceInvoicePaymentType(
            $order->getProvider(),
            $this->resolveMarketplaceInvoicePaymentMethodLabel($payment, $marketplaceLabel),
            $this->resolveMarketplaceInvoicePaymentCode($payment),
            $providerWallet,
            $marketplaceWallet
        );

        return [
            'app' => $isFood99Order ? Order::APP_FOOD99 : Order::APP_IFOOD,
            'marketplace_label' => $marketplaceLabel,
            'wallet_name' => $walletName,
            'marketplace_people' => $marketplacePeople,
            'provider_wallet' => $providerWallet,
            'marketplace_wallet' => $marketplaceWallet,
            'pending_status' => $pendingStatus,
            'paid_status' => $paidStatus,
            'invoice_payment_type' => $invoicePaymentType,
            'weekly_due_date' => $this->resolveWeeklyDueDate($order),
            'weekly_settlement_amount' => $weeklySettlementAmount,
            'customer_collection_amount' => $isPaidOnline ? 0.0 : $collectOnDeliveryAmount,
            'customer_marketplace_payment_amount' => $isPaidOnline
                ? ($amountPaid > 0 ? $amountPaid : $customerTotal)
                : 0.0,
            'courier_payment_amount' => $isFood99Order || !$isPlatformDelivery
                ? 0.0
                : $this->money($financial['delivery_fee'] ?? $financial['delivery_fee_amount'] ?? 0),
            'service_fee_amount' => $serviceFeeAmount,
            'small_order_fee_amount' => $smallOrderFeeAmount,
            'meal_top_up_fee_amount' => $mealTopUpFeeAmount,
            'commission_distribution_amount' => $commissionDistributionAmount,
            'payment_processing_amount' => $paymentProcessingAmount,
            'logistics_cost_amount' => $logisticsCostAmount,
            'merchant_discount_amount' => $merchantDiscountAmount,
            'platform_discount_amount' => $platformDiscountAmount,
            'financial' => $financial,
            'payment' => $payment,
            'delivery' => $delivery,
            'state' => [],
            'food99_derived_financials' => $food99DerivedFinancials,
            'courier' => [
                'name' => $this->text($delivery['rider_name'] ?? null),
                'phone' => $this->text($delivery['rider_phone'] ?? null),
            ],
        ];
    }

    private function extractStoredSnapshot(Order $order): array
    {
        $otherInformations = $this->decodeToArray($order->getOtherInformations(true));
        if ($otherInformations === []) {
            $otherInformations = $this->decodeToArray($order->getOtherInformations());
        }

        $app = trim((string) $order->getApp());
        $contextPayload = $this->extractContextPayload($otherInformations, $app);
        $candidates = $this->collectCandidates($otherInformations, $contextPayload);

        return [
            'financial' => $this->extractSection($candidates, 'financial'),
            'payment' => $this->extractSection($candidates, 'payment'),
            'delivery' => $this->extractSection($candidates, 'delivery'),
        ];
    }

    private function extractContextPayload(array $otherInformations, string $app): array
    {
        if ($app === '') {
            return [];
        }

        $candidate = $this->decodeToArray($otherInformations[$app] ?? null);
        if ($candidate === []) {
            return [];
        }

        $normalizedPayload = $candidate;
        for ($depth = 0; $depth < 16; $depth++) {
            $nestedCandidate = $this->decodeToArray($normalizedPayload[$app] ?? null);
            if ($nestedCandidate === [] || $nestedCandidate === $normalizedPayload) {
                break;
            }

            $normalizedPayload = $nestedCandidate;
        }

        return $normalizedPayload;
    }

    private function collectCandidates(array $root, array $contextPayload): array
    {
        $candidates = [];
        $seen = [];

        $this->appendCandidate($candidates, $seen, $root);
        $this->appendCandidate($candidates, $seen, $contextPayload);

        return $candidates;
    }

    private function appendCandidate(array &$candidates, array &$seen, mixed $candidate): void
    {
        $normalizedCandidate = $this->decodeToArray($candidate);
        if ($normalizedCandidate === []) {
            return;
        }

        $signature = md5(json_encode($normalizedCandidate));
        if (isset($seen[$signature])) {
            return;
        }

        $seen[$signature] = true;
        $candidates[] = $normalizedCandidate;

        $latestEventType = $this->text(
            $normalizedCandidate['latest_event_type']
                ?? $normalizedCandidate['latestEventType']
                ?? null
        );

        if ($latestEventType !== '') {
            $this->appendCandidate(
                $candidates,
                $seen,
                $normalizedCandidate[$latestEventType] ?? null
            );
        }

        foreach (['data', 'order', 'order_info', 'orderInfo'] as $nestedKey) {
            $this->appendCandidate($candidates, $seen, $normalizedCandidate[$nestedKey] ?? null);
        }

        $dataPayload = $this->decodeToArray($normalizedCandidate['data'] ?? null);
        if ($dataPayload !== []) {
            foreach (['order', 'order_info', 'orderInfo'] as $nestedKey) {
                $this->appendCandidate($candidates, $seen, $dataPayload[$nestedKey] ?? null);
            }
        }
    }

    private function extractSection(array $candidates, string $section): array
    {
        foreach ($candidates as $candidate) {
            $sectionPayload = $this->decodeToArray($candidate[$section] ?? null);
            if ($sectionPayload !== []) {
                return $sectionPayload;
            }
        }

        return [];
    }

    private function resolveMarketplaceInvoicePaymentType(
        People $provider,
        string $paymentMethodLabel,
        ?string $paymentCode = null,
        ?Wallet $sourceWallet = null,
        ?Wallet $destinationWallet = null
    ): PaymentType {
        $paymentType = $this->walletService->discoverPaymentType($provider, [
            'paymentType' => $paymentMethodLabel !== '' ? $paymentMethodLabel : 'Marketplace payment',
            'frequency' => 'single',
            'installments' => 'single',
        ]);

        if ($sourceWallet instanceof Wallet) {
            $this->walletService->discoverWalletPaymentType($sourceWallet, $paymentType, $paymentCode);
        }

        if ($destinationWallet instanceof Wallet) {
            $this->walletService->discoverWalletPaymentType($destinationWallet, $paymentType, $paymentCode);
        }

        return $paymentType;
    }

    private function resolveMarketplaceInvoicePaymentMethodLabel(
        array $payment,
        string $marketplaceLabel
    ): string {
        $candidates = [
            $this->text($payment['pay_method_label'] ?? null),
            $this->mapMarketplacePaymentLabel($payment['selected_payment_label'] ?? null),
            $this->text($payment['selected_payment_label'] ?? null),
            $this->text($payment['pay_type_label'] ?? null),
            $this->mapMarketplacePaymentLabel($payment['pay_method'] ?? null),
            $this->mapMarketplacePaymentLabel($payment['pay_type'] ?? null),
            $this->mapMarketplacePaymentLabel($payment['pay_channel'] ?? null),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        $normalizedMarketplaceLabel = trim($marketplaceLabel) !== '' ? trim($marketplaceLabel) : 'Marketplace';

        return sprintf('%s payment', $normalizedMarketplaceLabel);
    }

    private function resolveMarketplaceInvoicePaymentCode(array $payment): ?string
    {
        $candidates = [
            $this->text($payment['pay_channel'] ?? null),
            $this->text($payment['pay_method'] ?? null),
            $this->text($payment['pay_type'] ?? null),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function mapMarketplacePaymentLabel(mixed $value): string
    {
        $normalizedValue = strtoupper($this->text($value));

        return match ($normalizedValue) {
            'PIX' => 'Pix',
            'CASH', 'MONEY' => 'Dinheiro',
            'DEBIT', 'DEBIT_CARD' => 'Debito',
            'CREDIT', 'CREDIT_CARD' => 'Credito',
            'MEAL_VOUCHER' => 'Refeicao',
            'FOOD_VOUCHER' => 'Alimentacao',
            'DIGITAL_WALLET' => 'Carteira digital',
            default => '',
        };
    }

    private function shouldSkipFood99FinancialGeneration(Order $order): bool
    {
        if (strtolower(trim((string) $order->getApp())) !== strtolower(Order::APP_FOOD99)) {
            return false;
        }

        $realStatus = strtolower(trim((string) $order->getStatus()?->getRealStatus()));

        return in_array($realStatus, ['canceled', 'cancelled'], true);
    }

    private function resolveFood99SettlementWallet(People $provider): Wallet
    {
        $wallet = $this->food99Service->getStoredSettlementWallet($provider);
        if (!$wallet instanceof Wallet) {
            throw new \RuntimeException(
                sprintf(
                    'Carteira de repasse do Food99 nao configurada para a empresa #%s.',
                    (string) $provider->getId()
                )
            );
        }

        return $wallet;
    }

    private function resolveFood99DerivedFinancials(array $financial): array
    {
        return [
            'charge_base_amount' => $this->money($financial['charge_base_amount'] ?? 0),
            'commission_distribution_amount' => $this->money($financial['commission_distribution_amount'] ?? 0),
            'payment_processing_amount' => $this->money($financial['payment_processing_amount'] ?? 0),
            'service_fee_amount' => $this->money($financial['service_fee_amount'] ?? $financial['service_fee'] ?? 0),
            'logistics_cost_amount' => $this->money($financial['logistics_cost_amount'] ?? 0),
            'platform_charges_amount' => $this->money($financial['platform_charges_amount'] ?? 0),
            'weekly_settlement_amount' => $this->money($financial['weekly_settlement_amount'] ?? 0),
            'store_delivery_discount_amount' => $this->money($financial['store_delivery_discount_total'] ?? 0),
            'store_non_delivery_discount_amount' => $this->money($financial['store_non_delivery_discount_total'] ?? 0),
        ];
    }

    private function assertStoredMarketplaceSnapshotIsUsable(Order $order, array $financial, array $payment): void
    {
        if ($financial !== [] || $payment !== []) {
            return;
        }

        throw new \RuntimeException(
            sprintf(
                'Pedido #%s nao possui snapshot financeiro persistido em other_informations para popular as invoices sem calculo.',
                (string) $order->getId()
            )
        );
    }

    private function assertMarketplaceFinancialSnapshotIsUsable(Order $order, array $financial, array $payment): void
    {
        $orderPrice = $this->money($order->getPrice());
        if ($orderPrice <= 0) {
            return;
        }

        $relevantAmounts = [
            $financial['items_total'] ?? null,
            $financial['customer_total'] ?? null,
            $financial['discount_total'] ?? null,
            $financial['delivery_fee'] ?? null,
            $financial['service_fee'] ?? null,
            $financial['service_fee_amount'] ?? null,
            $financial['small_order_fee'] ?? null,
            $financial['small_order_fee_amount'] ?? null,
            $financial['meal_top_up_fee'] ?? null,
            $financial['meal_top_up_fee_amount'] ?? null,
            $financial['weekly_settlement_amount'] ?? null,
            $financial['store_receivable_total'] ?? null,
            $financial['commission_distribution_amount'] ?? null,
            $financial['payment_processing_amount'] ?? null,
            $financial['logistics_cost_amount'] ?? null,
            $financial['store_discount_total'] ?? null,
            $financial['platform_discount_total'] ?? null,
            $financial['shop_paid_money'] ?? null,
            $payment['customer_need_paying_money'] ?? null,
            $payment['collect_on_delivery_amount'] ?? null,
            $payment['amount_paid'] ?? null,
            $payment['amount_pending'] ?? null,
        ];

        foreach ($relevantAmounts as $amount) {
            if ($this->money($amount) > 0) {
                return;
            }
        }

        throw new \RuntimeException(
            sprintf(
                'Resumo financeiro da integracao indisponivel para o pedido #%s. O backend nao encontrou um payload rico o suficiente para gerar as invoices financeiras.',
                (string) $order->getId()
            )
        );
    }

    private function resolveCourierPeople(array $context): ?People
    {
        $name = $this->text($context['courier']['name'] ?? null);
        $phone = $this->normalizeBrazilianPhone($context['courier']['phone'] ?? null);

        if ($phone === [] && $name === '') {
            return null;
        }

        if ($phone === []) {
            $existingCourier = $this->manager->getRepository(People::class)->findOneBy([
                'name' => $name,
                'peopleType' => 'F',
            ]);

            return $existingCourier instanceof People ? $existingCourier : null;
        }

        return $this->peopleService->discoveryPeople(
            null,
            null,
            $phone,
            $name !== '' ? $name : 'Motoboy marketplace',
            'F'
        );
    }

    private function normalizeBrazilianPhone(mixed $value): array
    {
        $digits = preg_replace('/\D+/', '', (string) ($value ?? ''));
        if ($digits === null || $digits === '') {
            return [];
        }

        if (str_starts_with($digits, '55') && strlen($digits) >= 12) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) < 10) {
            return [];
        }

        return [
            'ddi' => 55,
            'ddd' => substr($digits, 0, 2),
            'phone' => substr($digits, 2),
        ];
    }

    private function resolveWeeklyDueDate(Order $order): DateTime
    {
        return $this->resolveWeeklyDueDateFromReference(
            $this->resolveOrderReferenceDate($order),
            (string) $order->getApp()
        );
    }

    private function resolveWeeklyDueDateFromReference(DateTimeInterface $reference, string $app = ''): DateTime
    {
        $dueDate = new DateTime($reference->format('Y-m-d'));
        $weekday = (int) $dueDate->format('N');
        $daysUntilSunday = 7 - $weekday;

        if ($daysUntilSunday > 0) {
            $dueDate->modify(sprintf('+%d days', $daysUntilSunday));
        }

        $dueDate->modify('+3 days');

        if (strtolower(trim($app)) === strtolower(Order::APP_IFOOD)) {
            $dueDate->modify('+1 month');
        }

        return $dueDate;
    }

    private function resolveOrderReferenceDate(Order $order): DateTime
    {
        $orderDate = $order->getOrderDate();

        if ($orderDate instanceof DateTimeInterface) {
            return new DateTime($orderDate->format('Y-m-d'));
        }

        return new DateTime('now');
    }

    private function serializeInvoice(Invoice $invoice): array
    {
        return [
            'id' => $invoice->getId(),
            'price' => $this->money($invoice->getPrice()),
            'description' => $invoice->getDescription(),
            'due_date' => $invoice->getDueDate()?->format('Y-m-d'),
        ];
    }

    private function invokeGeneratorMethod(string $methodName, mixed ...$arguments): mixed
    {
        $method = new \ReflectionMethod($this->marketplaceOrderFinancialGenerationService, $methodName);
        $method->setAccessible(true);

        return $method->invoke($this->marketplaceOrderFinancialGenerationService, ...$arguments);
    }

    private function decodeToArray(mixed $value): array
    {
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }

        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decodedValue = json_decode($value, true);

        return is_array($decodedValue) ? $decodedValue : [];
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(trim((string) ($value ?? ''))), ['1', 'true', 'yes', 'sim'], true);
    }

    private function money(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }

    private function text(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }
}
