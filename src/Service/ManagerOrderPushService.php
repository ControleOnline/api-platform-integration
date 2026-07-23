<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Entity\Device;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/*
 * Manager push contract:
 * - Handles manager-facing push notifications for order.created, order.canceled, store.opened, store.closed, and cash lifecycle events.
 * - Order cancellation payloads must carry the canonical order.canceled event so every provider reaches the same push renderer.
 */
class ManagerOrderPushService
{
    private const MANAGER_DEVICE_TYPE = 'MANAGER';
    private const ROUTE_NAME = 'OrderDetails';
    private const MANAGER_EVENT_NAMES = [
        'cash.open' => true,
        'cash.closed' => true,
        'store.opened' => true,
        'store.closed' => true,
        'order.canceled' => true,
        'order.cancelled' => true,
    ];

    public function __construct(
        private EntityManagerInterface $manager,
        private FirebaseCloudMessagingService $firebaseCloudMessagingService,
        private LoggerInterface $logger
    ) {}

    public function sendOrderCreatedNotification(Order $order, ?Device $targetDevice = null): int
    {
        $orderId = $order->getId();
        $company = $order->getProvider();
        if (!$orderId || !$company) {
            return 0;
        }

        $tokens = $this->resolveManagerDeviceTokens($order, $targetDevice);
        if (empty($tokens)) {
            return 0;
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

        $sentCount = 0;
        $lastFailure = null;
        foreach ($tokens as $token) {
            try {
                $this->firebaseCloudMessagingService->sendNotificationToToken(
                    $token,
                    $title,
                    $body,
                    $data
                );
                $sentCount++;
            } catch (Throwable $throwable) {
                $this->logger->warning('Unable to send manager order push notification.', [
                    'orderId' => $orderId,
                    'companyId' => $company->getId(),
                    'tokenHash' => hash('sha256', $token),
                    'exceptionClass' => $throwable::class,
                    'exceptionMessage' => $throwable->getMessage(),
                    'exception' => $throwable,
                ]);
                $lastFailure = $throwable;
            }
        }

        if ($sentCount <= 0 && $lastFailure instanceof Throwable) {
            throw new \RuntimeException(
                'Unable to send manager order push notification to any token.',
                0,
                $lastFailure
            );
        }

        return $sentCount;
    }

    public function sendCompanyEventNotification(People $company, array $event, ?Device $targetDevice = null): int
    {
        $eventName = trim((string) ($event['event'] ?? ''));
        if (!isset(self::MANAGER_EVENT_NAMES[$eventName]) || !$company->getId()) {
            return 0;
        }

        $tokens = $this->resolveManagerDeviceTokens($company, $targetDevice);
        if (empty($tokens)) {
            return 0;
        }

        [$title, $body] = $this->buildCompanyEventNotificationContent($company, $event);
        $data = $this->normalizeEventData([
            ...$event,
            'event' => $eventName,
            'company' => (string) $company->getId(),
            'companyId' => (string) $company->getId(),
        ]);

        $sentCount = 0;
        $lastFailure = null;
        foreach ($tokens as $token) {
            try {
                $this->firebaseCloudMessagingService->sendNotificationToToken(
                    $token,
                    $title,
                    $body,
                    $data
                );
                $sentCount++;
            } catch (Throwable $throwable) {
                $this->logger->warning('Unable to send manager event push notification.', [
                    'event' => $eventName,
                    'companyId' => $company->getId(),
                    'tokenHash' => hash('sha256', $token),
                    'exceptionClass' => $throwable::class,
                    'exceptionMessage' => $throwable->getMessage(),
                    'exception' => $throwable,
                ]);
                $lastFailure = $throwable;
            }
        }

        if ($sentCount <= 0 && $lastFailure instanceof Throwable) {
            throw new \RuntimeException(
                'Unable to send manager event push notification to any token.',
                0,
                $lastFailure
            );
        }

        return $sentCount;
    }

    private function resolveManagerDeviceTokens(Order|People $target, ?Device $targetDevice = null): array
    {
        if ($targetDevice instanceof Device) {
            $token = $this->extractManagerAndroidToken($targetDevice->getMetadata());

            return $token !== '' ? [$token] : [];
        }

        $company = $target instanceof Order ? $target->getProvider() : $target;
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
                $deviceConfig->getDevice()?->getMetadata()
            );
            if ($token === '') {
                continue;
            }

            $tokens[$token] = $token;
        }

        return array_values($tokens);
    }

    private function buildCompanyEventNotificationContent(People $company, array $event): array
    {
        $eventName = trim((string) ($event['event'] ?? ''));
        $companyLabel = trim((string) ($event['providerName'] ?? ''));
        if ($companyLabel === '') {
            $companyLabel = trim((string) (
                $company->getAlias() ?: $company->getName() ?: $company->getId()
            ));
        }
        $title = trim((string) ($event['notificationHeader'] ?? ''));
        $bodyParts = array_filter([
            trim((string) ($event['notificationSubheader'] ?? '')),
            trim((string) ($event['notificationBody'] ?? '')),
        ]);

        if ($title === '') {
            $title = match ($eventName) {
                'order.canceled', 'order.cancelled' => sprintf(
                    'Pedido #%s cancelado',
                    trim((string) ($event['orderId'] ?? $event['order'] ?? '')) ?: '0'
                ),
                'store.opened' => sprintf('%s foi aberta', $companyLabel ?: 'Loja'),
                'store.closed' => sprintf('%s foi fechada', $companyLabel ?: 'Loja'),
                'cash.open' => sprintf('Caixa aberto%s', $companyLabel ? ' - ' . $companyLabel : ''),
                'cash.closed' => sprintf('Caixa fechado%s', $companyLabel ? ' - ' . $companyLabel : ''),
                default => 'Aviso do Gestor',
            };
        }

        if (empty($bodyParts) && trim((string) ($event['message'] ?? '')) !== '') {
            $bodyParts[] = trim((string) $event['message']);
        }

        if (empty($bodyParts)) {
            $bodyParts[] = match ($eventName) {
                'order.canceled', 'order.cancelled' => 'O pedido foi cancelado. Toque para ver os detalhes.',
                'store.opened' => 'A loja voltou a ficar online.',
                'store.closed' => 'A loja foi fechada.',
                'cash.open' => 'O caixa foi aberto.',
                'cash.closed' => 'O fechamento de caixa foi concluido.',
                default => 'Novo aviso do Gestor.',
            };
        }

        return [$title, implode(' | ', $bodyParts)];
    }

    private function normalizeEventData(array $event): array
    {
        $data = [];

        foreach ($event as $key => $value) {
            $normalizedKey = trim((string) $key);
            if ($normalizedKey === '') {
                continue;
            }

            if (is_bool($value)) {
                $data[$normalizedKey] = $value ? 'true' : 'false';
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $data[$normalizedKey] = trim((string) ($value ?? ''));
                continue;
            }

            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded !== false) {
                $data[$normalizedKey] = $encoded;
            }
        }

        return $data;
    }

    private function extractManagerAndroidToken(mixed $metadata): string
    {
        if (!is_array($metadata)) {
            return '';
        }

        return trim((string) (
            $metadata['pushTokens']['manager']['android']['deviceToken'] ?? ''
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
