<?php

namespace ControleOnline\Integration\Tests\Service;

use ControleOnline\Entity\Invoice;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderInvoice;
use ControleOnline\Service\MarketplaceOrderFinancialGenerationService;
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

    public function testFood99DerivedFinancialsRebuildWeeklySettlementFromStoredPayloadBreakdown(): void
    {
        $service = (new \ReflectionClass(MarketplaceOrderFinancialGenerationService::class))
            ->newInstanceWithoutConstructor();

        $derivedFinancials = $this->invokePrivateMethod(
            $service,
            'resolveFood99DerivedFinancials',
            [
                'items_total' => 97.79,
                'store_discount_total' => 32.65,
                'store_non_delivery_discount_total' => 22.75,
                'store_delivery_discount_total' => 9.90,
                'service_fee' => 2.03,
                'store_charged_delivery_price' => 9.99,
            ],
            [
                'is_paid_online' => true,
            ],
            [
                'is_platform_delivery' => true,
            ],
        );

        self::assertSame(75.04, $derivedFinancials['charge_base_amount']);
        self::assertSame(6.68, $derivedFinancials['commission_distribution_amount']);
        self::assertSame(2.41, $derivedFinancials['payment_processing_amount']);
        self::assertSame(2.03, $derivedFinancials['service_fee_amount']);
        self::assertSame(6.00, $derivedFinancials['logistics_cost_amount']);
        self::assertSame(17.12, $derivedFinancials['platform_charges_amount']);
        self::assertSame(48.02, $derivedFinancials['weekly_settlement_amount']);
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

    private function invokePrivateMethod(object $object, string $methodName, mixed ...$arguments): mixed
    {
        $method = new \ReflectionMethod($object, $methodName);
        $method->setAccessible(true);

        return $method->invoke($object, ...$arguments);
    }
}
