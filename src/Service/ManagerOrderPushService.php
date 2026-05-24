<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class ManagerOrderPushService
{
    private const MANAGER_DEVICE_TYPE = 'MANAGER';
    private const ROUTE_NAME = 'OrderDetails';

    public function __construct(
        private EntityManagerInterface $manager,
        private FirebaseCloudMessagingService $firebaseCloudMessagingService,
        private LoggerInterface $logger
    ) {}

    public function sendOrderCreatedNotification(Order $order): void
    {
        $orderId = $order->getId();
        $company = $order->getProvider();
        if (!$orderId || !$company) {
            return;
        }

        $tokens = $this->resolveManagerDeviceTokens($order);
        if (empty($tokens)) {
            return;
        }

        $companyLabel = trim((string) (
            $company->getAlias() ?: $company->getName() ?: $company->getId()
        ));
        $customerLabel = trim((string) (
            $order->getClient()?->getName() ?: $order->getPayer()?->getName()
        ));
        $totalLabel = $this->formatCurrency($order->getPrice());
        $title = sprintf('Novo pedido #%s', $orderId);
        $bodyParts = array_filter([
            $customerLabel ? 'Cliente: ' . $customerLabel : null,
            $totalLabel ? 'Valor: ' . $totalLabel : null,
            $companyLabel,
        ]);
        $body = implode(' | ', $bodyParts);
        if ($body === '') {
            $body = sprintf('%s recebeu um novo pedido.', $companyLabel ?: 'A empresa');
        }

        $data = [
            'event' => 'order.created',
            'route' => self::ROUTE_NAME,
            'routeName' => self::ROUTE_NAME,
            'screen' => self::ROUTE_NAME,
            'orderId' => (string) $orderId,
            'companyId' => (string) $company->getId(),
        ];

        foreach ($tokens as $token) {
            try {
                $this->firebaseCloudMessagingService->sendNotificationToToken(
                    $token,
                    $title,
                    $body,
                    $data
                );
            } catch (Throwable $throwable) {
                $this->logger->warning('Unable to send manager order push notification.', [
                    'orderId' => $orderId,
                    'companyId' => $company->getId(),
                    'tokenHash' => hash('sha256', $token),
                    'exception' => $throwable,
                ]);
            }
        }
    }

    private function resolveManagerDeviceTokens(Order $order): array
    {
        $company = $order->getProvider();
        if (!$company) {
            return [];
        }

        $deviceConfigs = $this->manager->getRepository(DeviceConfig::class)->findBy([
            'people' => $company,
            'type' => self::MANAGER_DEVICE_TYPE,
        ]);

        $tokens = [];
        foreach ($deviceConfigs as $deviceConfig) {
            if (!$deviceConfig instanceof DeviceConfig) {
                continue;
            }

            $token = $this->extractManagerAndroidToken(
                $deviceConfig->getDevice()?->getMetadata(true)
            );
            if ($token === '') {
                continue;
            }

            $tokens[$token] = $token;
        }

        return array_values($tokens);
    }

    private function extractManagerAndroidToken(mixed $metadata): string
    {
        if (!is_array($metadata)) {
            return '';
        }

        return trim((string) (
            $metadata['pushTokens']['manager']['android']['deviceToken'] ??
            $metadata['push_tokens']['manager']['android']['deviceToken'] ??
            ''
        ));
    }

    private function formatCurrency(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return 'R$ ' . number_format((float) $value, 2, ',', '.');
    }
}
