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

class IfoodFinancialOperationsService extends AbstractMarketplaceService
{
    private const APP_CONTEXT = Order::APP_IFOOD;

    protected function getMarketplaceApp(): string
    {
        return self::APP_CONTEXT;
    }

    private function resolveIfoodInvoicePaymentTypeData(array $payment): array
    {
        $method = strtoupper($this->normalizeString($payment['method'] ?? null));
        $type = strtoupper($this->normalizeString($payment['type'] ?? null));
        $brand = strtoupper($this->normalizeString($payment['card']['brand'] ?? null));
        $walletName = $this->normalizeString($payment['wallet']['name'] ?? null);
        $liability = strtoupper($this->normalizeString($payment['liability'] ?? null));
        $selectedPaymentLabel = trim($method . ($brand !== '' ? " ({$brand})" : ''));

        $paymentTypeData = match ($method) {
            'PIX' => ['paymentType' => 'PIX', 'aliases' => []],
            'CASH' => ['paymentType' => 'Dinheiro', 'aliases' => []],
            'DEBIT' => ['paymentType' => 'Debito', 'aliases' => ['Cartao de Debito', 'Cartão de Débito', 'Débito']],
            'CREDIT' => ['paymentType' => 'Credito', 'aliases' => ['Cartao de Credito', 'Cartão de Crédito', 'Crédito']],
            'MEAL_VOUCHER' => ['paymentType' => 'Refeicao', 'aliases' => ['Vale Refeicao', 'Vale Refeição', 'Refeição']],
            'FOOD_VOUCHER' => ['paymentType' => 'Alimentacao', 'aliases' => ['Vale Alimentacao', 'Vale Alimentação', 'Alimentação']],
            'DIGITAL_WALLET' => ['paymentType' => $walletName !== '' ? $walletName : 'Carteira Digital', 'aliases' => ['Digital Wallet']],
            'GIFT_CARD' => ['paymentType' => 'Gift Card', 'aliases' => []],
            'OTHER' => ['paymentType' => $selectedPaymentLabel !== '' ? $selectedPaymentLabel : 'iFood', 'aliases' => []],
            default => ['paymentType' => $selectedPaymentLabel !== '' ? $selectedPaymentLabel : 'iFood', 'aliases' => []],
        };

        $paymentTypeData['frequency'] = 'single';
        $paymentTypeData['installments'] = 'single';
        $paymentTypeData['paymentCode'] = $brand !== '' ? $brand : ($method !== '' ? $method : null);
        $paymentTypeData['pay_type'] = strtolower($type);
        $paymentTypeData['pay_method'] = strtolower($method);
        $paymentTypeData['pay_channel'] = $brand !== '' ? $brand : $method;
        $paymentTypeData['selected_payment_label'] = $selectedPaymentLabel;
        $paymentTypeData['payment_liability'] = $liability;
        $paymentTypeData['payment_wallet_name'] = $walletName;

        return $paymentTypeData;
    }

    private function resolveIfoodProviderPaymentType(People $provider, array $paymentTypeData, ?Wallet $wallet = null): PaymentType
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
                $this->ensureIfoodWalletPaymentType(
                    $wallet,
                    $paymentType,
                    $paymentTypeData['paymentCode'] ?? null
                );
            }

            return $paymentType;
        }

        $paymentType = $this->walletService->discoverPaymentType($provider, [
            'paymentType' => $candidateNames[0] ?? 'iFood',
            'frequency' => $paymentTypeData['frequency'] ?? 'single',
            'installments' => $paymentTypeData['installments'] ?? 'single',
        ]);

        if ($wallet instanceof Wallet) {
            $this->ensureIfoodWalletPaymentType(
                $wallet,
                $paymentType,
                $paymentTypeData['paymentCode'] ?? null
            );
        }

        return $paymentType;
    }

    private function resolveIfoodSettlementPaymentType(People $provider, ?Wallet $wallet = null): PaymentType
    {
        return $this->resolveIfoodProviderPaymentType($provider, [
            'paymentType' => 'iFood',
            'aliases' => ['IFOOD'],
            'frequency' => 'single',
            'installments' => 'single',
            'paymentCode' => self::APP_CONTEXT,
        ], $wallet);
    }

    private function ensureIfoodWalletPaymentType(
        Wallet $wallet,
        PaymentType $paymentType,
        $paymentCode = null
    ): WalletPaymentType {
        $normalizedPaymentCode = $this->normalizeString($paymentCode);

        $walletPaymentType = $this->entityManager
            ->getRepository(WalletPaymentType::class)
            ->findOneBy([
                'wallet' => $wallet,
                'paymentType' => $paymentType,
            ]);

        if ($walletPaymentType instanceof WalletPaymentType) {
            $currentPaymentCode = $this->normalizeString($walletPaymentType->getPaymentCode());
            if ($currentPaymentCode === '' && $normalizedPaymentCode !== '') {
                $walletPaymentType->setPaymentCode($normalizedPaymentCode);
                $this->entityManager->persist($walletPaymentType);
                $this->entityManager->flush();
            }

            return $walletPaymentType;
        }

        $walletPaymentType = new WalletPaymentType();
        $walletPaymentType->setWallet($wallet);
        $walletPaymentType->setPaymentType($paymentType);
        $walletPaymentType->setPaymentCode($normalizedPaymentCode !== '' ? $normalizedPaymentCode : null);
        $this->entityManager->persist($walletPaymentType);
        $this->entityManager->flush();

        return $walletPaymentType;
    }

    private function shouldIfoodUseMarketplaceWalletForReceivable(array $paymentTypeData, bool $isPrepaid): bool
    {
        $paymentLiability = strtoupper($this->normalizeString($paymentTypeData['payment_liability'] ?? null));
        $paymentType = strtoupper($this->normalizeString($paymentTypeData['pay_type'] ?? null));

        return $isPrepaid
            || $paymentLiability === 'IFOOD'
            || $paymentType === 'ONLINE';
    }

    private function resolveIfoodReceivableWallet(
        Order $order,
        PaymentType $paymentType,
        array $paymentTypeData,
        bool $isPrepaid
    ): Wallet {
        $walletName = $this->shouldIfoodUseMarketplaceWalletForReceivable($paymentTypeData, $isPrepaid)
            ? self::$app
            : $this->normalizeString($paymentType->getPaymentType());

        if ($walletName === '') {
            $walletName = self::$app;
        }

        return $this->walletService->discoverWallet($order->getProvider(), $walletName);
    }

    private function applyIfoodInvoiceContract(
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
        $currentIfoodData = $serializedInformations[self::APP_CONTEXT] ?? [];

        if ($currentIfoodData instanceof \stdClass) {
            $currentIfoodData = (array) $currentIfoodData;
        }

        $serializedInformations[self::APP_CONTEXT] = array_merge(
            is_array($currentIfoodData) ? $currentIfoodData : [],
            $metadata
        );

        $invoice->setOtherInformations($serializedInformations);

        $this->entityManager->persist($invoice);
        $this->entityManager->flush();
    }

    private function createIfoodPayableInvoice(
        Order $order,
        PaymentType $paymentType,
        float $amount,
        Status $status,
        Wallet $providerWallet,
        Wallet $ifoodWallet,
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
            self::$foodPeople,
            $normalizedAmount,
            $status,
            new DateTime(),
            $providerWallet,
            $ifoodWallet
        );

        $this->applyIfoodInvoiceContract(
            $invoice,
            $paymentType,
            array_merge([
                'financial_kind' => 'account_payable',
                'invoice_purpose' => $purpose,
                'marketplace' => self::APP_CONTEXT,
            ], $metadata),
            $status,
            $providerWallet,
            $ifoodWallet
        );

        return $invoice;
    }


}
