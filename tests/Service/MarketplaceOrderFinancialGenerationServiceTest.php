<?php

namespace ControleOnline\Integration\Tests\Service;

use ControleOnline\Entity\Invoice;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderInvoice;
use ControleOnline\Service\MarketplaceOrderFinancialGenerationService;
use ControleOnline\Service\Food99Service;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

class MarketplaceOrderFinancialGenerationServiceTest extends TestCase
{
    public function testLegacyMarketplaceMetadataIsTreatedAsManagedForCleanup(): void
    {
        $service = (new \ReflectionClass(MarketplaceOrderFinancialGenerationService::class))
            ->newInstanceWithoutConstructor();

        foreach (
            [
                ['financial_kind' => 'account_receivable', 'invoice_purpose' => 'customer_total'],
                ['financial_kind' => 'account_payable', 'invoice_purpose' => 'delivery_fee'],
            ] as $metadata
        ) {
            $invoice = new Invoice();
            $invoice->setOtherInformations([
                Order::APP_IFOOD => array_merge([
                    'marketplace' => Order::APP_IFOOD,
                ], $metadata),
            ]);

            self::assertTrue(
                $this->invokePrivateMethod(
                    $service,
                    'isManagedMarketplaceInvoice',
                    $invoice,
                    Order::APP_IFOOD,
                ),
            );
        }
    }

    public function testSuspiciousLegacyMarketplaceMetadataStillBlocksAutomaticCleanup(): void
    {
        $service = (new \ReflectionClass(MarketplaceOrderFinancialGenerationService::class))
            ->newInstanceWithoutConstructor();

        $invoice = new Invoice();
        $invoice->setOtherInformations([
            Order::APP_IFOOD => [
                'marketplace' => Order::APP_IFOOD,
                'financial_kind' => 'custom_manual_adjustment',
                'invoice_purpose' => 'unknown_purpose',
            ],
        ]);

        $orderInvoice = $this->createConfiguredMock(OrderInvoice::class, [
            'getInvoice' => $invoice,
        ]);
        $order = $this->createConfiguredMock(Order::class, [
            'getInvoice' => new ArrayCollection([$orderInvoice]),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Pedido possui invoices legadas de marketplace. A limpeza automatica foi bloqueada para evitar apagar dados antigos.'
        );

        $this->invokePrivateMethod(
            $service,
            'assertNoLegacyMarketplaceInvoices',
            $order,
            Order::APP_IFOOD,
        );
    }

    public function testFood99DerivedFinancialsReturnSnapshotValuesWithoutRecalculation(): void
    {
        $service = (new \ReflectionClass(MarketplaceOrderFinancialGenerationService::class))
            ->newInstanceWithoutConstructor();

        $derivedFinancials = $this->invokePrivateMethod(
            $service,
            'resolveFood99DerivedFinancials',
            [
                'charge_base_amount' => 75.04,
                'commission_distribution_amount' => 5.93,
                'payment_processing_amount' => 2.40,
                'service_fee_amount' => 2.03,
                'logistics_cost_amount' => 5.99,
                'platform_charges_amount' => 16.35,
                'weekly_settlement_amount' => 48.79,
                'store_delivery_discount_total' => 9.90,
                'store_non_delivery_discount_total' => 22.75,
            ],
            [],
            [],
        );

        self::assertSame(75.04, $derivedFinancials['charge_base_amount']);
        self::assertSame(5.93, $derivedFinancials['commission_distribution_amount']);
        self::assertSame(2.40, $derivedFinancials['payment_processing_amount']);
        self::assertSame(2.03, $derivedFinancials['service_fee_amount']);
        self::assertSame(5.99, $derivedFinancials['logistics_cost_amount']);
        self::assertSame(16.35, $derivedFinancials['platform_charges_amount']);
        self::assertSame(48.79, $derivedFinancials['weekly_settlement_amount']);
        self::assertSame(9.90, $derivedFinancials['store_delivery_discount_amount']);
        self::assertSame(22.75, $derivedFinancials['store_non_delivery_discount_amount']);
    }

    public function testWeeklyDueDateUsesNextWednesdayAfterWeekClose(): void
    {
        $service = (new \ReflectionClass(MarketplaceOrderFinancialGenerationService::class))
            ->newInstanceWithoutConstructor();

        $tuesdayOrder = $this->createConfiguredMock(Order::class, [
            'getOrderDate' => new DateTime('2026-05-05 20:29:49'),
        ]);
        $mondayOrder = $this->createConfiguredMock(Order::class, [
            'getOrderDate' => new DateTime('2026-05-11 10:00:00'),
        ]);

        $tuesdayDueDate = $this->invokePrivateMethod(
            $service,
            'resolveWeeklyDueDate',
            $tuesdayOrder,
        );
        $mondayDueDate = $this->invokePrivateMethod(
            $service,
            'resolveWeeklyDueDate',
            $mondayOrder,
        );

        self::assertSame('2026-05-13', $tuesdayDueDate->format('Y-m-d'));
        self::assertSame('2026-05-20', $mondayDueDate->format('Y-m-d'));
    }

    public function testIfoodWeeklyDueDateUsesMonthlySettlementAfterWeekClose(): void
    {
        $service = (new \ReflectionClass(MarketplaceOrderFinancialGenerationService::class))
            ->newInstanceWithoutConstructor();

        $mondayOrder = $this->createConfiguredMock(Order::class, [
            'getOrderDate' => new DateTime('2026-05-11 10:00:00'),
            'getApp' => Order::APP_IFOOD,
        ]);

        $dueDate = $this->invokePrivateMethod(
            $service,
            'resolveWeeklyDueDate',
            $mondayOrder,
        );

        self::assertSame('2026-06-20', $dueDate->format('Y-m-d'));
    }

    public function testFood99CanceledOrdersAreSkippedFromFinancialGeneration(): void
    {
        $service = (new \ReflectionClass(MarketplaceOrderFinancialGenerationService::class))
            ->newInstanceWithoutConstructor();

        $canceledStatus = $this->createConfiguredMock(\ControleOnline\Entity\Status::class, [
            'getRealStatus' => 'canceled',
        ]);
        $food99Order = $this->createConfiguredMock(Order::class, [
            'getApp' => Order::APP_FOOD99,
            'getStatus' => $canceledStatus,
        ]);
        $nonCanceledOrder = $this->createConfiguredMock(Order::class, [
            'getApp' => Order::APP_FOOD99,
            'getStatus' => $this->createConfiguredMock(\ControleOnline\Entity\Status::class, [
                'getRealStatus' => 'closed',
            ]),
        ]);
        $otherAppOrder = $this->createConfiguredMock(Order::class, [
            'getApp' => Order::APP_IFOOD,
            'getStatus' => $canceledStatus,
        ]);

        self::assertTrue(
            $this->invokePrivateMethod(
                $service,
                'shouldSkipFood99FinancialGeneration',
                $food99Order,
            ),
        );
        self::assertFalse(
            $this->invokePrivateMethod(
                $service,
                'shouldSkipFood99FinancialGeneration',
                $nonCanceledOrder,
            ),
        );
        self::assertFalse(
            $this->invokePrivateMethod(
                $service,
                'shouldSkipFood99FinancialGeneration',
                $otherAppOrder,
            ),
        );
    }

    public function testFood99BuildContextUsesConfiguredSettlementWalletForTheProvider(): void
    {
        $service = (new \ReflectionClass(MarketplaceOrderFinancialGenerationService::class))
            ->newInstanceWithoutConstructor();

        $provider = $this->createConfiguredMock(\ControleOnline\Entity\People::class, [
            'getId' => 3,
        ]);
        $marketplacePeople = $this->createConfiguredMock(\ControleOnline\Entity\People::class, [
            'getId' => 99,
        ]);

        $providerWallet = new \ControleOnline\Entity\Wallet();
        $providerWallet->setId(21);
        $providerWallet->setWallet('Pic Pay');
        $providerWallet->setPeople($provider);

        $marketplaceWallet = new \ControleOnline\Entity\Wallet();
        $marketplaceWallet->setId(99);
        $marketplaceWallet->setWallet('99 Food');
        $marketplaceWallet->setPeople($marketplacePeople);

        $order = $this->createConfiguredMock(Order::class, [
            'getApp' => Order::APP_FOOD99,
            'getProvider' => $provider,
            'getPrice' => 100.0,
            'getOrderDate' => new DateTime('2026-05-05 10:00:00'),
            'getId' => 570004,
        ]);

        $snapshot = [
            'financial' => [
                'items_total' => 100.0,
                'customer_total' => 100.0,
                'weekly_settlement_amount' => 73.51,
                'service_fee' => 2.03,
                'shop_paid_money' => 73.51,
                'store_receivable_total' => 73.51,
                'commission_distribution_amount' => 5.93,
                'payment_processing_amount' => 2.40,
                'logistics_cost_amount' => 5.99,
            ],
            'payment' => [
                'is_paid_online' => true,
                'amount_paid' => 100.0,
            ],
            'delivery' => [
                'is_platform_delivery' => false,
            ],
        ];

        $food99Service = $this->getMockBuilder(Food99Service::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getOrderHomologationSnapshot',
                'getStoredOrderIntegrationState',
                '__call',
            ])
            ->getMock();
        $food99Service
            ->expects(self::once())
            ->method('getOrderHomologationSnapshot')
            ->with($order)
            ->willReturn($snapshot);
        $food99Service
            ->expects(self::once())
            ->method('getStoredOrderIntegrationState')
            ->with($order)
            ->willReturn(['is_platform_delivery' => false]);
        $food99Service
            ->method('__call')
            ->willReturnCallback(static function (string $method, array $arguments) use (
                $provider,
                $providerWallet
            ): mixed {
                return match ($method) {
                    'getStoredSettlementWallet' => $arguments === [$provider] ? $providerWallet : null,
                    default => null,
                };
            });

        $peopleService = $this->createMock(\ControleOnline\Service\PeopleService::class);
        $peopleService
            ->expects(self::once())
            ->method('discoveryPeople')
            ->with('6012920000123', null, [], '99 Food', 'J')
            ->willReturn($marketplacePeople);

        $walletService = $this->createMock(\ControleOnline\Service\WalletService::class);
        $walletService
            ->expects(self::once())
            ->method('discoverWallet')
            ->with($marketplacePeople, '99 Food')
            ->willReturn($marketplaceWallet);

        $pendingStatus = $this->createConfiguredMock(\ControleOnline\Entity\Status::class, [
            'getId' => 1,
        ]);
        $paidStatus = $this->createConfiguredMock(\ControleOnline\Entity\Status::class, [
            'getId' => 2,
        ]);
        $statusService = $this->createMock(\ControleOnline\Service\StatusService::class);
        $statusService
            ->expects(self::exactly(2))
            ->method('discoveryStatus')
            ->willReturnOnConsecutiveCalls($pendingStatus, $paidStatus);

        $this->setObjectProperty($service, 'food99Service', $food99Service);
        $this->setObjectProperty($service, 'peopleService', $peopleService);
        $this->setObjectProperty($service, 'walletService', $walletService);
        $this->setObjectProperty($service, 'statusService', $statusService);
        $this->setObjectProperty($service, 'entityManager', $this->createMock(\Doctrine\ORM\EntityManagerInterface::class));
        $this->setObjectProperty($service, 'invoiceService', $this->createMock(\ControleOnline\Service\InvoiceService::class));
        $this->setObjectProperty($service, 'orderService', $this->createMock(\ControleOnline\Service\OrderService::class));
        $this->setObjectProperty($service, 'iFoodService', $this->createMock(\ControleOnline\Service\iFoodService::class));

        $context = $this->invokePrivateMethod($service, 'buildContext', $order);

        self::assertSame($providerWallet, $context['provider_wallet']);
        self::assertSame($marketplaceWallet, $context['marketplace_wallet']);
        self::assertSame('99 Food', $context['wallet_name']);
        self::assertSame('99 Food', $context['marketplace_label']);
        self::assertSame(73.51, $context['weekly_settlement_amount']);
    }

    public function testFood99BuildContextFallsBackToMaterializedInvoicesWhenSnapshotIsEmpty(): void
    {
        $service = (new \ReflectionClass(MarketplaceOrderFinancialGenerationService::class))
            ->newInstanceWithoutConstructor();

        $provider = $this->createConfiguredMock(\ControleOnline\Entity\People::class, [
            'getId' => 3,
        ]);
        $marketplacePeople = $this->createConfiguredMock(\ControleOnline\Entity\People::class, [
            'getId' => 99,
        ]);

        $providerWallet = new \ControleOnline\Entity\Wallet();
        $providerWallet->setId(21);
        $providerWallet->setWallet('Pic Pay');
        $providerWallet->setPeople($provider);

        $marketplaceWallet = new \ControleOnline\Entity\Wallet();
        $marketplaceWallet->setId(99);
        $marketplaceWallet->setWallet('99 Food');
        $marketplaceWallet->setPeople($marketplacePeople);

        $paymentType = new \ControleOnline\Entity\PaymentType();
        $paymentType->setPeople($provider);
        $paymentType->setPaymentType('Pix');
        $paymentType->setFrequency('single');
        $paymentType->setInstallments('single');

        $customerPayment = $this->createMarketplaceInvoice(
            Order::APP_FOOD99,
            'customer_marketplace_payment',
            'marketplace_customer_payment',
            65.59,
            new DateTime('2026-05-27'),
            $paymentType
        );
        $weeklySettlement = $this->createMarketplaceInvoice(
            Order::APP_FOOD99,
            'weekly_settlement',
            'account_receivable',
            51.76,
            new DateTime('2026-06-03'),
            $paymentType
        );
        $serviceFee = $this->createMarketplaceInvoice(
            Order::APP_FOOD99,
            'service_fee',
            'account_payable',
            3.13,
            new DateTime('2026-05-27'),
            $paymentType
        );
        $commissionDistribution = $this->createMarketplaceInvoice(
            Order::APP_FOOD99,
            'commission_distribution',
            'account_payable',
            6.02,
            new DateTime('2026-05-27'),
            $paymentType
        );
        $paymentProcessing = $this->createMarketplaceInvoice(
            Order::APP_FOOD99,
            'payment_processing',
            'account_payable',
            2.44,
            new DateTime('2026-05-27'),
            $paymentType
        );
        $logisticsCost = $this->createMarketplaceInvoice(
            Order::APP_FOOD99,
            'logistics_cost',
            'account_payable',
            4.79,
            new DateTime('2026-05-27'),
            $paymentType
        );
        $merchantDiscount = $this->createMarketplaceInvoice(
            Order::APP_FOOD99,
            'merchant_discount',
            'marketplace_internal_offset',
            37.65,
            new DateTime('2026-05-27'),
            $paymentType
        );
        $platformDiscount = $this->createMarketplaceInvoice(
            Order::APP_FOOD99,
            'platform_discount',
            'marketplace_internal_offset',
            13.67,
            new DateTime('2026-05-27'),
            $paymentType
        );

        $order = $this->createConfiguredMock(Order::class, [
            'getApp' => Order::APP_FOOD99,
            'getProvider' => $provider,
            'getPrice' => 105.79,
            'getOrderDate' => new DateTime('2026-05-27 10:00:00'),
            'getId' => 71670,
            'getInvoice' => new ArrayCollection([
                $customerPayment,
                $weeklySettlement,
                $serviceFee,
                $commissionDistribution,
                $paymentProcessing,
                $logisticsCost,
                $merchantDiscount,
                $platformDiscount,
            ]),
        ]);

        $food99Service = $this->getMockBuilder(Food99Service::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getOrderHomologationSnapshot',
                'getStoredOrderIntegrationState',
                '__call',
            ])
            ->getMock();
        $food99Service
            ->expects(self::once())
            ->method('getOrderHomologationSnapshot')
            ->with($order)
            ->willReturn([]);
        $food99Service
            ->expects(self::once())
            ->method('getStoredOrderIntegrationState')
            ->with($order)
            ->willReturn([]);
        $food99Service
            ->method('__call')
            ->willReturnCallback(static function (string $method, array $arguments) use (
                $provider,
                $providerWallet
            ): mixed {
                return match ($method) {
                    'getStoredSettlementWallet' => $arguments === [$provider] ? $providerWallet : null,
                    default => null,
                };
            });

        $peopleService = $this->createMock(\ControleOnline\Service\PeopleService::class);
        $peopleService
            ->expects(self::once())
            ->method('discoveryPeople')
            ->with('6012920000123', null, [], '99 Food', 'J')
            ->willReturn($marketplacePeople);

        $walletService = $this->createMock(\ControleOnline\Service\WalletService::class);
        $walletService
            ->expects(self::once())
            ->method('discoverWallet')
            ->with($marketplacePeople, '99 Food')
            ->willReturn($marketplaceWallet);
        $walletService
            ->expects(self::never())
            ->method('discoverPaymentType');
        $walletService
            ->expects(self::never())
            ->method('discoverWalletPaymentType');

        $pendingStatus = $this->createConfiguredMock(\ControleOnline\Entity\Status::class, [
            'getId' => 1,
        ]);
        $paidStatus = $this->createConfiguredMock(\ControleOnline\Entity\Status::class, [
            'getId' => 2,
        ]);
        $statusService = $this->createMock(\ControleOnline\Service\StatusService::class);
        $statusService
            ->expects(self::exactly(2))
            ->method('discoveryStatus')
            ->willReturnOnConsecutiveCalls($pendingStatus, $paidStatus);

        $this->setObjectProperty($service, 'food99Service', $food99Service);
        $this->setObjectProperty($service, 'peopleService', $peopleService);
        $this->setObjectProperty($service, 'walletService', $walletService);
        $this->setObjectProperty($service, 'statusService', $statusService);
        $this->setObjectProperty($service, 'entityManager', $this->createMock(\Doctrine\ORM\EntityManagerInterface::class));
        $this->setObjectProperty($service, 'invoiceService', $this->createMock(\ControleOnline\Service\InvoiceService::class));
        $this->setObjectProperty($service, 'orderService', $this->createMock(\ControleOnline\Service\OrderService::class));
        $this->setObjectProperty($service, 'iFoodService', $this->createMock(\ControleOnline\Service\iFoodService::class));

        $context = $this->invokePrivateMethod($service, 'buildContext', $order);

        self::assertSame('2026-06-03', $context['weekly_due_date']->format('Y-m-d'));
        self::assertSame(51.76, $context['weekly_settlement_amount']);
        self::assertSame(65.59, $context['customer_marketplace_payment_amount']);
        self::assertSame(3.13, $context['service_fee_amount']);
        self::assertSame(6.02, $context['commission_distribution_amount']);
        self::assertSame(2.44, $context['payment_processing_amount']);
        self::assertSame(4.79, $context['logistics_cost_amount']);
        self::assertSame(37.65, $context['merchant_discount_amount']);
        self::assertSame(13.67, $context['platform_discount_amount']);
    }

    public function testIfoodBuildContextUsesSnapshotReceivableAndMerchantFees(): void
    {
        $service = (new \ReflectionClass(MarketplaceOrderFinancialGenerationService::class))
            ->newInstanceWithoutConstructor();

        $provider = $this->createConfiguredMock(\ControleOnline\Entity\People::class, [
            'getId' => 3,
        ]);
        $marketplacePeople = $this->createConfiguredMock(\ControleOnline\Entity\People::class, [
            'getId' => 143802,
        ]);

        $providerWallet = new \ControleOnline\Entity\Wallet();
        $providerWallet->setId(21);
        $providerWallet->setWallet(Order::APP_IFOOD);
        $providerWallet->setPeople($provider);

        $marketplaceWallet = new \ControleOnline\Entity\Wallet();
        $marketplaceWallet->setId(99);
        $marketplaceWallet->setWallet(Order::APP_IFOOD);
        $marketplaceWallet->setPeople($marketplacePeople);

        $order = $this->createConfiguredMock(Order::class, [
            'getApp' => Order::APP_IFOOD,
            'getProvider' => $provider,
            'getPrice' => 102.0,
            'getOrderDate' => new DateTime('2026-05-25 10:00:00'),
            'getId' => 1518,
        ]);

        $snapshot = [
            'financial' => [
                'items_total' => 100.0,
                'delivery_fee' => 10.0,
                'customer_total' => 102.0,
                'store_receivable_total' => 88.0,
                'service_fee' => 1.0,
                'small_order_fee' => 2.0,
                'meal_top_up_fee' => 3.0,
                'store_discount_total' => 11.0,
                'platform_discount_total' => 4.0,
            ],
            'payment' => [
                'is_paid_online' => true,
                'amount_paid' => 102.0,
                'pay_method_label' => 'Online',
            ],
            'delivery' => [
                'is_platform_delivery' => true,
            ],
        ];

        $iFoodService = $this->createMock(\ControleOnline\Service\iFoodService::class);
        $iFoodService
            ->expects(self::once())
            ->method('getOrderHomologationSnapshot')
            ->with($order)
            ->willReturn($snapshot);
        $iFoodService
            ->expects(self::once())
            ->method('getStoredOrderIntegrationState')
            ->with($order)
            ->willReturn([]);

        $peopleService = $this->createMock(\ControleOnline\Service\PeopleService::class);
        $peopleService
            ->expects(self::once())
            ->method('discoveryPeople')
            ->willReturn($marketplacePeople);

        $paymentType = $this->createMock(\ControleOnline\Entity\PaymentType::class);
        $walletService = $this->createMock(\ControleOnline\Service\WalletService::class);
        $walletService
            ->method('discoverWallet')
            ->willReturnCallback(static function (\ControleOnline\Entity\People $people) use (
                $provider,
                $providerWallet,
                $marketplaceWallet
            ): \ControleOnline\Entity\Wallet {
                return $people === $provider ? $providerWallet : $marketplaceWallet;
            });
        $walletService
            ->method('discoverPaymentType')
            ->willReturn($paymentType);
        $walletService
            ->method('discoverWalletPaymentType')
            ->willReturn($this->createMock(\ControleOnline\Entity\WalletPaymentType::class));

        $pendingStatus = $this->createConfiguredMock(\ControleOnline\Entity\Status::class, [
            'getId' => 1,
        ]);
        $paidStatus = $this->createConfiguredMock(\ControleOnline\Entity\Status::class, [
            'getId' => 2,
        ]);
        $statusService = $this->createMock(\ControleOnline\Service\StatusService::class);
        $statusService
            ->expects(self::exactly(2))
            ->method('discoveryStatus')
            ->willReturnOnConsecutiveCalls($pendingStatus, $paidStatus);

        $this->setObjectProperty($service, 'iFoodService', $iFoodService);
        $this->setObjectProperty($service, 'food99Service', $this->createMock(Food99Service::class));
        $this->setObjectProperty($service, 'peopleService', $peopleService);
        $this->setObjectProperty($service, 'walletService', $walletService);
        $this->setObjectProperty($service, 'statusService', $statusService);
        $this->setObjectProperty($service, 'entityManager', $this->createMock(\Doctrine\ORM\EntityManagerInterface::class));
        $this->setObjectProperty($service, 'invoiceService', $this->createMock(\ControleOnline\Service\InvoiceService::class));
        $this->setObjectProperty($service, 'orderService', $this->createMock(\ControleOnline\Service\OrderService::class));

        $context = $this->invokePrivateMethod($service, 'buildContext', $order);

        self::assertSame(88.0, $context['weekly_settlement_amount']);
        self::assertSame(102.0, $context['customer_marketplace_payment_amount']);
        self::assertSame(10.0, $context['courier_payment_amount']);
        self::assertSame(1.0, $context['service_fee_amount']);
        self::assertSame(2.0, $context['small_order_fee_amount']);
        self::assertSame(3.0, $context['meal_top_up_fee_amount']);
        self::assertSame(11.0, $context['merchant_discount_amount']);
        self::assertSame(4.0, $context['platform_discount_amount']);
    }

    private function invokePrivateMethod(object $object, string $methodName, mixed ...$arguments): mixed
    {
        $method = new \ReflectionMethod($object, $methodName);
        $method->setAccessible(true);

        return $method->invoke($object, ...$arguments);
    }

    private function createMarketplaceInvoice(
        string $app,
        string $purpose,
        string $financialKind,
        float $amount,
        DateTime $dueDate,
        ?\ControleOnline\Entity\PaymentType $paymentType = null
    ): OrderInvoice {
        $invoice = new Invoice();
        $invoice->setPrice($amount);
        $invoice->setDueDate($dueDate);
        if ($paymentType instanceof \ControleOnline\Entity\PaymentType) {
            $invoice->setPaymentType($paymentType);
        }
        $invoice->setOtherInformations([
            $app => [
                'marketplace' => $app,
                'generated_by' => 'marketplace_financial_generation',
                'invoice_purpose' => $purpose,
                'financial_kind' => $financialKind,
            ],
        ]);

        $orderInvoice = new OrderInvoice();
        $orderInvoice->setInvoice($invoice);
        $orderInvoice->setRealPrice($amount);

        return $orderInvoice;
    }

    private function setObjectProperty(object $object, string $propertyName, mixed $value): void
    {
        $property = new \ReflectionProperty($object, $propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }
}
