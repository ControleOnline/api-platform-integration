<?php

namespace ControleOnline\Service;

use DateTimeImmutable;
use ControleOnline\Entity\Order;
use ControleOnline\Service\StatusService;
use Doctrine\ORM\EntityManagerInterface;

class OrderActionService
{
    private const ORDER_ACTION_KEY = 'order_action';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private StatusService $statusService,
    ) {}

    private function normalizeString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s.u');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (!is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private function sanitizeActionPayload(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            $normalizedKey = $this->normalizeString($key);
            if ($normalizedKey === '') {
                continue;
            }

            if (is_bool($value)) {
                $sanitized[$normalizedKey] = $value;
                continue;
            }

            $normalizedValue = $this->normalizeString($value);
            if ($normalizedValue === '') {
                continue;
            }

            $sanitized[$normalizedKey] = $normalizedValue;
        }

        return $sanitized;
    }

    private function persistOrderAction(Order $order, string $action, array $payload = [], bool $remoteSync = true): void
    {
        $otherInformations = $order->getOtherInformations(true);
        if (!is_object($otherInformations)) {
            $otherInformations = (object) [];
        }

        $otherInformations->{self::ORDER_ACTION_KEY} = [
            'name' => $action,
            'remote_sync' => $remoteSync,
            'requested_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s.u'),
            'payload' => $this->sanitizeActionPayload($payload),
        ];

        $order->setOtherInformations($otherInformations);
    }

    private function isTerminalOrder(Order $order): bool
    {
        $realStatus = strtolower(trim((string) ($order->getStatus()?->getRealStatus() ?? '')));

        return in_array($realStatus, ['canceled', 'cancelled', 'closed'], true);
    }

    private function buildTerminalOrderResponse(): array
    {
        return [
            'errno' => 10001,
            'errmsg' => 'Pedido em estado final nao pode mais ser alterado.',
        ];
    }

    public function getCapabilities(Order $order): array
    {
        $realStatus = strtolower(trim((string) ($order->getStatus()?->getRealStatus() ?? '')));
        $terminal = in_array($realStatus, ['canceled', 'cancelled', 'closed'], true);

        return [
            'can_cancel' => !$terminal,
            'can_ready' => !$terminal,
            'can_delivered' => !$terminal,
            'is_terminal' => $terminal,
        ];
    }

    public function getCancelReasons(Order $order): array
    {
        return ['data' => ['reasons' => []]];
    }

    public function confirm(Order $order): array
    {
        if ($this->isTerminalOrder($order)) {
            return $this->buildTerminalOrderResponse();
        }

        $this->persistOrderAction($order, 'confirm');

        return $this->aplicarStatusLocal($order, 'open', 'preparing');
    }

    public function cancel(Order $order, ?int $reasonId = null, ?string $reason = null): array
    {
        if ($this->isTerminalOrder($order)) {
            return $this->buildTerminalOrderResponse();
        }

        $this->persistOrderAction($order, 'cancel', [
            'reason_id' => $reasonId,
            'reason' => $reason,
        ]);

        return $this->aplicarStatusLocal($order, 'canceled', 'canceled');
    }

    public function ready(Order $order): array
    {
        if ($this->isTerminalOrder($order)) {
            return $this->buildTerminalOrderResponse();
        }

        $this->persistOrderAction($order, 'ready');

        return $this->aplicarStatusLocal($order, 'pending', 'ready');
    }

    public function delivered(
        Order $order,
        ?string $deliveryCode = null,
        ?string $locator = null,
        bool $deferStatusUpdate = false
    ): array
    {
        if ($this->isTerminalOrder($order)) {
            return $this->buildTerminalOrderResponse();
        }

        $shouldRemoteSync = $deferStatusUpdate
            || $this->normalizeString($deliveryCode) !== ''
            || $this->normalizeString($locator) !== '';

        $this->persistOrderAction($order, 'delivered', [
            'delivery_code' => $deliveryCode,
            'locator' => $locator,
        ], $shouldRemoteSync);

        if ($shouldRemoteSync) {
            $this->entityManager->persist($order);
            $this->entityManager->flush();

            return ['errno' => 0, 'errmsg' => 'ok'];
        }

        return $this->aplicarStatusLocal($order, 'closed', 'closed');
    }

    private function aplicarStatusLocal(Order $order, string $status, string $realStatus): array
    {
        $novoStatus = $this->statusService->discoveryStatus($status, $realStatus, 'order');

        if (!$novoStatus) {
            return ['errno' => 1, 'errmsg' => 'Status não encontrado: ' . $realStatus];
        }

        $order->setStatus($novoStatus);
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return ['errno' => 0, 'errmsg' => 'ok'];
    }
}
