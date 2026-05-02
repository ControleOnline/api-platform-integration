<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Invoice;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderInvoice;
use ControleOnline\Entity\PaymentType;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Status;
use ControleOnline\Entity\Wallet;
use DateTime;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

class MarketplaceOrderFinancialGenerationService
{
    private const GENERATED_BY = 'marketplace_financial_generation';
    private const LEGACY_GENERATED_BY = 'marketplace_financial_correction';
    private const PURPOSE_CUSTOMER_MARKETPLACE_PAYMENT = 'customer_marketplace_payment';
    private const PURPOSE_WEEKLY_SETTLEMENT = 'weekly_settlement';
    private const PURPOSE_CUSTOMER_COLLECTION = 'customer_collection';
    private const PURPOSE_SERVICE_FEE = 'service_fee';
    private const PURPOSE_SMALL_ORDER_FEE = 'small_order_fee';
    private const PURPOSE_MEAL_TOP_UP_FEE = 'meal_top_up_fee';
    private const PURPOSE_MERCHANT_DISCOUNT = 'merchant_discount';
    private const PURPOSE_PLATFORM_DISCOUNT = 'platform_discount';
    private const PURPOSE_COURIER_PAYMENT = 'courier_payment';
    private const IFOOD_DOCUMENT = '14380200000121';
    private const IFOOD_NAME = 'Ifood.com Agência de Restaurantes Online S.A';
    private const FOOD99_DOCUMENT = '6012920000123';
    private const FOOD99_NAME = '99 Food';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private InvoiceService $invoiceService,
        private WalletService $walletService,
        private PeopleService $peopleService,
        private StatusService $statusService,
        private OrderService $orderService,
        private Food99Service $food99Service,
        private iFoodService $iFoodService,
    ) {}

    public function generate(Order $order): array
    {
        $financialOrder = $this->orderService->resolveFinancialOrder($order);
        $context = $this->buildContext($financialOrder);
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $this->assertNoLegacyMarketplaceInvoices($financialOrder, $context['app']);
            $removedInvoices = $this->purgeManagedMarketplaceInvoices($financialOrder, $context['app']);
            $this->entityManager->flush();
            $createdInvoices = [];
            $warnings = [];

            if ($context['customer_collection_amount'] > 0) {
                $customer = $financialOrder->getPayer() instanceof People
                    ? $financialOrder->getPayer()
                    : $financialOrder->getClient();

                if ($customer instanceof People) {
                    $createdInvoices[] = $this->createSingleOrderInvoice(
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
                        'collect_on_delivery'
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
                    $createdInvoices[] = $this->createSingleOrderInvoice(
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
                        'customer_online_payment'
                    );
                } else {
                    $warnings[] = 'Pedido sem cliente/pagador para gerar invoice de pagamento online ao marketplace.';
                }
            }

            if ($context['weekly_settlement_amount'] > 0) {
                $createdInvoices[] = $this->syncWeeklySettlementInvoice($financialOrder, $context);
            }

            foreach ($this->buildProviderPayables($financialOrder, $context) as $payable) {
                if (($payable['amount'] ?? 0) <= 0) {
                    continue;
                }

                $createdInvoices[] = $this->createSingleOrderInvoice(
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
                    (string) $payable['payment_code']
                );
            }

            foreach ($this->buildMarketplaceOffsets($financialOrder, $context) as $offset) {
                if (($offset['amount'] ?? 0) <= 0) {
                    continue;
                }

                $createdInvoices[] = $this->createSingleOrderInvoice(
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
                    (string) $offset['payment_code']
                );
            }

            if ($context['courier_payment_amount'] > 0) {
                $courier = $this->resolveCourierPeople($context);
                if ($courier instanceof People) {
                    $courierWallet = $this->walletService->discoverWallet($courier, $context['wallet_name']);
                    $createdInvoices[] = $this->createSingleOrderInvoice(
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
                        'courier_payment'
                    );
                } else {
                    $warnings[] = 'Pedido sem motoboy identificavel para gerar invoice de pagamento ao entregador.';
                }
            }

            $this->entityManager->flush();
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

    private function assertNoLegacyMarketplaceInvoices(Order $order, string $app): void
    {
        $orderInvoices = is_iterable($order->getInvoice()) ? $order->getInvoice()->toArray() : [];

        foreach ($orderInvoices as $orderInvoice) {
            if (!$orderInvoice instanceof OrderInvoice) {
                continue;
            }

            $invoice = $orderInvoice->getInvoice();
            if (!$invoice instanceof Invoice) {
                continue;
            }

            $metadata = $this->readMarketplaceMetadata($invoice, $app);
            if ($metadata === []) {
                continue;
            }

            if ($this->isManagedGeneratedMarketplaceMetadata($metadata)) {
                continue;
            }

            if (
                ($metadata['marketplace'] ?? null) === $app
                || isset($metadata['invoice_purpose'])
                || isset($metadata['financial_kind'])
            ) {
                throw new \RuntimeException(
                    'Pedido possui invoices legadas de marketplace. A limpeza automatica foi bloqueada para evitar apagar dados antigos.'
                );
            }
        }
    }

    private function buildContext(Order $order): array
    {
        $normalizedApp = strtolower(trim((string) $order->getApp()));

        if ($normalizedApp === strtolower(Order::APP_FOOD99)) {
            $snapshot = $this->food99Service->getOrderHomologationSnapshot($order);
            $state = $this->food99Service->getStoredOrderIntegrationState($order);
            $marketplacePeople = $this->peopleService->discoveryPeople(
                self::FOOD99_DOCUMENT,
                null,
                [],
                self::FOOD99_NAME,
                'J'
            );
            $walletName = self::FOOD99_NAME;
            $marketplaceLabel = self::FOOD99_NAME;
        } else {
            $snapshot = $this->iFoodService->getOrderHomologationSnapshot($order);
            $state = $this->iFoodService->getStoredOrderIntegrationState($order);
            $marketplacePeople = $this->peopleService->discoveryPeople(
                self::IFOOD_DOCUMENT,
                null,
                [],
                self::IFOOD_NAME,
                'J'
            );
            $walletName = Order::APP_IFOOD;
            $marketplaceLabel = Order::APP_IFOOD;
        }

        $financial = is_array($snapshot['financial'] ?? null) ? $snapshot['financial'] : [];
        $payment = is_array($snapshot['payment'] ?? null) ? $snapshot['payment'] : [];
        $delivery = is_array($snapshot['delivery'] ?? null) ? $snapshot['delivery'] : [];

        $this->assertMarketplaceFinancialSnapshotIsUsable($order, $financial, $payment);

        $providerWallet = $this->walletService->discoverWallet($order->getProvider(), $walletName);
        $marketplaceWallet = $this->walletService->discoverWallet($marketplacePeople, $walletName);
        $pendingStatus = $this->statusService->discoveryStatus('pending', 'waiting payment', 'invoice');
        $paidStatus = $this->statusService->discoveryStatus('closed', 'paid', 'invoice');

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
        $serviceFeeAmount = $this->money($financial['service_fee'] ?? 0);
        $smallOrderFeeAmount = $this->money($financial['small_order_fee'] ?? 0);
        $mealTopUpFeeAmount = $this->money($financial['meal_top_up_fee'] ?? 0);
        $customerTotal = $this->money($financial['customer_total'] ?? 0);
        $amountPaid = $this->money($payment['amount_paid'] ?? 0);
        $shopPaidMoney = $this->money($financial['shop_paid_money'] ?? 0);
        $deliveryFeeAmount = $this->money($financial['delivery_fee'] ?? 0);
        $collectOnDeliveryAmount = $this->money(
            $payment['collect_on_delivery_amount']
                ?? $payment['customer_need_paying_money']
                ?? 0
        );
        $isPaidOnline = $this->toBool($payment['is_paid_online'] ?? false);
        $isPlatformDelivery = $this->toBool(
            $state['is_platform_delivery']
                ?? $delivery['is_platform_delivery']
                ?? false
        );

        $marketplaceGrossAmount = $shopPaidMoney > 0
            ? $shopPaidMoney
            : $this->money(($isPaidOnline ? $customerTotal : 0) + $platformDiscountAmount);

        $weeklySettlementAmount = $shopPaidMoney > 0
            ? $shopPaidMoney
            : $this->money(
                max(
                    0,
                    $marketplaceGrossAmount
                        - $serviceFeeAmount
                        - $smallOrderFeeAmount
                        - $mealTopUpFeeAmount
                        - $merchantDiscountAmount
                )
            );

        $settlementPaymentType = $this->resolveMarketplacePaymentType(
            $order->getProvider(),
            $marketplaceLabel,
            self::PURPOSE_WEEKLY_SETTLEMENT,
            'weekly_settlement',
            $providerWallet,
            $marketplaceWallet
        );

        return [
            'app' => $normalizedApp === strtolower(Order::APP_FOOD99) ? Order::APP_FOOD99 : Order::APP_IFOOD,
            'marketplace_label' => $marketplaceLabel,
            'wallet_name' => $walletName,
            'marketplace_people' => $marketplacePeople,
            'provider_wallet' => $providerWallet,
            'marketplace_wallet' => $marketplaceWallet,
            'pending_status' => $pendingStatus,
            'paid_status' => $paidStatus,
            'settlement_payment_type' => $settlementPaymentType,
            'weekly_due_date' => $this->resolveWeeklyDueDate($order),
            'weekly_settlement_amount' => $weeklySettlementAmount,
            'customer_collection_amount' => $isPaidOnline ? 0.0 : $collectOnDeliveryAmount,
            'customer_marketplace_payment_amount' => $isPaidOnline
                ? ($amountPaid > 0 ? $amountPaid : $customerTotal)
                : 0.0,
            'courier_payment_amount' => $isPlatformDelivery ? $deliveryFeeAmount : 0.0,
            'service_fee_amount' => $serviceFeeAmount,
            'small_order_fee_amount' => $smallOrderFeeAmount,
            'meal_top_up_fee_amount' => $mealTopUpFeeAmount,
            'merchant_discount_amount' => $merchantDiscountAmount,
            'platform_discount_amount' => $platformDiscountAmount,
            'financial' => $financial,
            'payment' => $payment,
            'delivery' => $delivery,
            'state' => $state,
            'courier' => [
                'name' => $this->text($state['rider_name'] ?? $delivery['rider_name'] ?? null),
                'phone' => $this->text($state['rider_phone'] ?? $delivery['rider_phone'] ?? null),
            ],
        ];
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

    private function buildProviderPayables(Order $order, array $context): array
    {
        return [
            [
                'purpose' => self::PURPOSE_SERVICE_FEE,
                'amount' => $context['service_fee_amount'],
                'description' => sprintf(
                    'Taxa de servico %s do pedido #%s',
                    $context['marketplace_label'],
                    (string) $order->getId()
                ),
                'payment_code' => 'service_fee',
            ],
            [
                'purpose' => self::PURPOSE_SMALL_ORDER_FEE,
                'amount' => $context['small_order_fee_amount'],
                'description' => sprintf(
                    'Taxa de pedido minimo %s do pedido #%s',
                    $context['marketplace_label'],
                    (string) $order->getId()
                ),
                'payment_code' => 'small_order_fee',
            ],
            [
                'purpose' => self::PURPOSE_MEAL_TOP_UP_FEE,
                'amount' => $context['meal_top_up_fee_amount'],
                'description' => sprintf(
                    'Complemento de beneficio %s do pedido #%s',
                    $context['marketplace_label'],
                    (string) $order->getId()
                ),
                'payment_code' => 'meal_top_up_fee',
            ],
        ];
    }

    private function buildMarketplaceOffsets(Order $order, array $context): array
    {
        return [
            [
                'purpose' => self::PURPOSE_MERCHANT_DISCOUNT,
                'amount' => $context['merchant_discount_amount'],
                'description' => sprintf(
                    'Compensacao interna %s do desconto subsidiado pela loja no pedido #%s',
                    $context['marketplace_label'],
                    (string) $order->getId()
                ),
                'payment_code' => 'merchant_discount',
            ],
            [
                'purpose' => self::PURPOSE_PLATFORM_DISCOUNT,
                'amount' => $context['platform_discount_amount'],
                'description' => sprintf(
                    'Compensacao interna %s do desconto subsidiado pela plataforma no pedido #%s',
                    $context['marketplace_label'],
                    (string) $order->getId(),
                ),
                'payment_code' => 'platform_discount',
            ],
        ];
    }

    private function syncWeeklySettlementInvoice(Order $order, array $context): Invoice
    {
        $invoice = $this->findWeeklySettlementInvoice($order, $context);
        $description = sprintf(
            'Repasse semanal %s com vencimento em %s',
            $context['marketplace_label'],
            $context['weekly_due_date']->format('d/m/Y')
        );

        if ($invoice instanceof Invoice) {
            $this->adjustWalletBalances(
                $invoice->getSourceWallet(),
                $invoice->getDestinationWallet(),
                $context['weekly_settlement_amount']
            );
            $invoice->setPrice($this->money($invoice->getPrice() + $context['weekly_settlement_amount']));
            $invoice->setDueDate($context['weekly_due_date']);
            $invoice->setStatus($context['pending_status']);
            $invoice->setPaymentType($context['settlement_payment_type']);
            $invoice->setDescription($description);
            $this->applyMarketplaceMetadata($invoice, $context, self::PURPOSE_WEEKLY_SETTLEMENT, [
                'financial_kind' => 'account_receivable',
                'grouping' => 'weekly',
                'settled_by' => 'marketplace',
                'weekly_due_date' => $context['weekly_due_date']->format('Y-m-d'),
            ]);
            $this->entityManager->persist($invoice);
            $this->linkInvoiceToOrder($order, $invoice, $context['weekly_settlement_amount']);
            return $invoice;
        }

        $invoice = $this->invoiceService->createInvoice(
            null,
            $context['marketplace_people'],
            $order->getProvider(),
            $context['weekly_settlement_amount'],
            $context['pending_status'],
            $context['weekly_due_date'],
            $context['marketplace_wallet'],
            $context['provider_wallet'],
            1,
            1,
            null,
            $description
        );
        $invoice->setPaymentType($context['settlement_payment_type']);
        $this->applyMarketplaceMetadata($invoice, $context, self::PURPOSE_WEEKLY_SETTLEMENT, [
            'financial_kind' => 'account_receivable',
            'grouping' => 'weekly',
            'settled_by' => 'marketplace',
            'weekly_due_date' => $context['weekly_due_date']->format('Y-m-d'),
        ]);
        $this->entityManager->persist($invoice);
        $this->linkInvoiceToOrder($order, $invoice, $context['weekly_settlement_amount']);
        return $invoice;
    }

    private function createSingleOrderInvoice(
        Order $order,
        array $context,
        string $purpose,
        People $payer,
        People $receiver,
        float $amount,
        Status $status,
        DateTime $dueDate,
        ?Wallet $sourceWallet,
        ?Wallet $destinationWallet,
        string $description,
        array $metadata,
        string $paymentCode
    ): Invoice {
        $paymentType = $this->resolveMarketplacePaymentType(
            $order->getProvider(),
            (string) ($context['marketplace_label'] ?? ''),
            $purpose,
            $paymentCode,
            $sourceWallet,
            $destinationWallet
        );

        $invoice = $this->invoiceService->createInvoice(
            null,
            $payer,
            $receiver,
            $amount,
            $status,
            $dueDate,
            $sourceWallet,
            $destinationWallet,
            1,
            1,
            null,
            $description
        );
        $invoice->setPaymentType($paymentType);
        $this->applyMarketplaceMetadata($invoice, $context, $purpose, $metadata);
        $this->entityManager->persist($invoice);
        $this->linkInvoiceToOrder($order, $invoice, $amount);
        return $invoice;
    }

    private function purgeManagedMarketplaceInvoices(Order $order, string $app): array
    {
        $removedInvoiceIds = [];
        $orderInvoices = is_iterable($order->getInvoice()) ? $order->getInvoice()->toArray() : [];

        foreach ($orderInvoices as $orderInvoice) {
            if (!$orderInvoice instanceof OrderInvoice) {
                continue;
            }

            $invoice = $orderInvoice->getInvoice();
            if (!$invoice instanceof Invoice || !$this->isManagedMarketplaceInvoice($invoice, $app)) {
                continue;
            }

            $share = $this->resolveOrderInvoiceShare($orderInvoice, $invoice);
            $remainingLinks = array_values(array_filter(
                $invoice->getOrder()->toArray(),
                fn(mixed $link) => $link instanceof OrderInvoice && $link->getId() !== $orderInvoice->getId()
            ));

            $this->adjustWalletBalances(
                $invoice->getSourceWallet(),
                $invoice->getDestinationWallet(),
                -$share
            );

            $invoice->removeOrder($orderInvoice);
            $order->removeInvoice($orderInvoice);
            $this->entityManager->remove($orderInvoice);

            if ($remainingLinks === []) {
                $removedInvoiceIds[] = $invoice->getId();
                $this->entityManager->remove($invoice);
                continue;
            }

            $invoice->setPrice($this->money(max(0, $invoice->getPrice() - $share)));
            $this->entityManager->persist($invoice);
        }

        return array_values(array_filter($removedInvoiceIds));
    }

    private function findWeeklySettlementInvoice(Order $order, array $context): ?Invoice
    {
        $candidates = $this->entityManager->getRepository(Invoice::class)->findBy([
            'payer' => $context['marketplace_people'],
            'receiver' => $order->getProvider(),
            'sourceWallet' => $context['marketplace_wallet'],
            'destinationWallet' => $context['provider_wallet'],
            'dueDate' => $context['weekly_due_date'],
        ]);

        foreach ($candidates as $candidate) {
            if (!$candidate instanceof Invoice) {
                continue;
            }

            $metadata = $this->readMarketplaceMetadata($candidate, $context['app']);
            if (!$this->isManagedGeneratedMarketplaceMetadata($metadata)) {
                continue;
            }

            if (($metadata['invoice_purpose'] ?? null) !== self::PURPOSE_WEEKLY_SETTLEMENT) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    private function linkInvoiceToOrder(Order $order, Invoice $invoice, float $realPrice): void
    {
        $orderInvoice = $this->entityManager->getRepository(OrderInvoice::class)->findOneBy([
            'order' => $order,
            'invoice' => $invoice,
        ]);

        if (!$orderInvoice instanceof OrderInvoice) {
            $orderInvoice = new OrderInvoice();
            $orderInvoice->setOrder($order);
            $orderInvoice->setInvoice($invoice);
        }

        $orderInvoice->setRealPrice($this->money($realPrice));
        $this->entityManager->persist($orderInvoice);
    }

    private function resolveMarketplacePaymentType(
        People $provider,
        string $marketplaceLabel,
        string $purpose,
        string $paymentCode,
        ?Wallet $sourceWallet = null,
        ?Wallet $destinationWallet = null
    ): PaymentType {
        $paymentType = $this->walletService->discoverPaymentType($provider, [
            'paymentType' => $this->resolveMarketplacePaymentTypeLabel($marketplaceLabel, $purpose),
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

    private function resolveMarketplacePaymentTypeLabel(string $marketplaceLabel, string $purpose): string
    {
        $normalizedMarketplaceLabel = trim($marketplaceLabel) !== '' ? trim($marketplaceLabel) : 'Marketplace';

        return match ($purpose) {
            self::PURPOSE_CUSTOMER_MARKETPLACE_PAYMENT => sprintf('%s - Pagamento online do cliente', $normalizedMarketplaceLabel),
            self::PURPOSE_WEEKLY_SETTLEMENT => sprintf('%s - Repasse semanal', $normalizedMarketplaceLabel),
            self::PURPOSE_CUSTOMER_COLLECTION => sprintf('%s - Cobranca na entrega', $normalizedMarketplaceLabel),
            self::PURPOSE_SERVICE_FEE => sprintf('%s - Taxa de servico', $normalizedMarketplaceLabel),
            self::PURPOSE_SMALL_ORDER_FEE => sprintf('%s - Taxa de pedido minimo', $normalizedMarketplaceLabel),
            self::PURPOSE_MEAL_TOP_UP_FEE => sprintf('%s - Complemento de beneficio', $normalizedMarketplaceLabel),
            self::PURPOSE_MERCHANT_DISCOUNT => sprintf('%s - Desconto subsidiado pela loja', $normalizedMarketplaceLabel),
            self::PURPOSE_PLATFORM_DISCOUNT => sprintf('%s - Desconto subsidiado pela plataforma', $normalizedMarketplaceLabel),
            self::PURPOSE_COURIER_PAYMENT => sprintf('%s - Pagamento do motoboy', $normalizedMarketplaceLabel),
            default => sprintf('%s - %s', $normalizedMarketplaceLabel, $purpose),
        };
    }

    private function resolveCourierPeople(array $context): ?People
    {
        $name = $this->text($context['courier']['name'] ?? null);
        $phone = $this->normalizeBrazilianPhone($context['courier']['phone'] ?? null);

        if ($phone === [] && $name === '') {
            return null;
        }

        if ($phone === []) {
            $existingCourier = $this->entityManager->getRepository(People::class)->findOneBy([
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

    private function resolveWeeklyDueDate(Order $order): DateTime
    {
        $reference = $this->resolveOrderReferenceDate($order);
        $dueDate = new DateTime($reference->format('Y-m-d'));
        $weekday = (int) $dueDate->format('N');
        $daysUntilWednesday = (3 - $weekday + 7) % 7;

        if ($daysUntilWednesday > 0) {
            $dueDate->modify(sprintf('+%d days', $daysUntilWednesday));
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

    private function applyMarketplaceMetadata(
        Invoice $invoice,
        array $context,
        string $purpose,
        array $metadata = []
    ): void {
        $otherInformations = $invoice->getOtherInformations(true);
        $serializedInformations = $otherInformations instanceof \stdClass
            ? (array) $otherInformations
            : (is_array($otherInformations) ? $otherInformations : []);
        $currentMarketplaceData = $serializedInformations[$context['app']] ?? [];

        if ($currentMarketplaceData instanceof \stdClass) {
            $currentMarketplaceData = (array) $currentMarketplaceData;
        }

        $serializedInformations[$context['app']] = array_merge(
            is_array($currentMarketplaceData) ? $currentMarketplaceData : [],
            $metadata,
            [
                'marketplace' => $context['app'],
                'wallet_name' => $context['wallet_name'],
                'generated_by' => self::GENERATED_BY,
                'invoice_purpose' => $purpose,
                'marketplace_label' => $context['marketplace_label'],
            ]
        );

        $invoice->setOtherInformations($serializedInformations);
    }

    private function readMarketplaceMetadata(Invoice $invoice, string $app): array
    {
        $otherInformations = $invoice->getOtherInformations(true);
        $serializedInformations = $otherInformations instanceof \stdClass
            ? (array) $otherInformations
            : (is_array($otherInformations) ? $otherInformations : []);
        $metadata = $serializedInformations[$app] ?? [];

        if ($metadata instanceof \stdClass) {
            $metadata = (array) $metadata;
        }

        return is_array($metadata) ? $metadata : [];
    }

    private function isManagedMarketplaceInvoice(Invoice $invoice, string $app): bool
    {
        $metadata = $this->readMarketplaceMetadata($invoice, $app);
        if ($metadata === []) {
            return false;
        }

        return $this->isManagedGeneratedMarketplaceMetadata($metadata)
            && ($metadata['marketplace'] ?? null) === $app;
    }

    private function isManagedGeneratedMarketplaceMetadata(array $metadata): bool
    {
        return in_array(
            (string) ($metadata['generated_by'] ?? ''),
            [
                self::GENERATED_BY,
                self::LEGACY_GENERATED_BY,
            ],
            true
        );
    }

    private function resolveOrderInvoiceShare(OrderInvoice $orderInvoice, Invoice $invoice): float
    {
        $realPrice = $this->money($orderInvoice->getRealPrice());
        if ($realPrice > 0) {
            return min($realPrice, $this->money($invoice->getPrice()));
        }

        return $this->money($invoice->getPrice());
    }

    private function adjustWalletBalances(?Wallet $sourceWallet, ?Wallet $destinationWallet, float $delta): void
    {
        $normalizedDelta = $this->money($delta);
        if ($normalizedDelta === 0.0) {
            return;
        }

        if ($destinationWallet instanceof Wallet) {
            $destinationWallet->setBalance((float) $destinationWallet->getBalance() + $normalizedDelta);
            $this->entityManager->persist($destinationWallet);
        }

        if ($sourceWallet instanceof Wallet) {
            $sourceWallet->setBalance((float) $sourceWallet->getBalance() - $normalizedDelta);
            $this->entityManager->persist($sourceWallet);
        }
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
