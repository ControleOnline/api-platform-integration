<?php

namespace ControleOnline\Integration\Tests\Service;

use ControleOnline\Entity\Invoice;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderInvoice;
use ControleOnline\Service\MarketplaceOrderFinancialGenerationService;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

class MarketplaceOrderFinancialGenerationServiceTest extends TestCase
{
    public function testLegacyMarketplaceMetadataIsTreatedAsManagedForCleanup(): void
    {
        $service = (new \ReflectionClass(MarketplaceOrderFinancialGenerationService::class))
            ->newInstanceWithoutConstructor();

        $invoice = new Invoice();
        $invoice->setOtherInformations([
            Order::APP_IFOOD => [
                'marketplace' => Order::APP_IFOOD,
                'financial_kind' => 'account_receivable',
                'invoice_purpose' => 'customer_total',
            ],
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
        self::assertSame(6.00, $derivedFinancials['logistics_cost_amount']);
        self::assertSame(15.09, $derivedFinancials['platform_charges_amount']);
        self::assertSame(50.05, $derivedFinancials['weekly_settlement_amount']);
    }

    private function invokePrivateMethod(object $object, string $methodName, mixed ...$arguments): mixed
    {
        $method = new \ReflectionMethod($object, $methodName);
        $method->setAccessible(true);

        return $method->invoke($object, ...$arguments);
    }
}
