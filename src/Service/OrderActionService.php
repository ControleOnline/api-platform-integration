<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Service\Food99Service;
use ControleOnline\Service\iFoodService;
use ControleOnline\Service\StatusService;
use Doctrine\ORM\EntityManagerInterface;

class OrderActionService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Food99Service $food99Service,
        private iFoodService $iFoodService,
        private StatusService $statusService,
    ) {}

    private function plataforma(Order $order): string
    {
        return strtolower(trim((string) ($order->getApp() ?? '')));
    }

    private function ehFood99(Order $order): bool
    {
        return in_array($this->plataforma($order), ['food99', '99food'], true);
    }

    private function ehIfood(Order $order): bool
    {
        return $this->plataforma($order) === 'ifood';
    }

    public function getCapabilities(Order $order): array
    {
        $plataforma = $this->plataforma($order);
        $realStatus = strtolower(trim((string) ($order->getStatus()?->getRealStatus() ?? '')));
        $terminal   = in_array($realStatus, ['canceled', 'cancelled', 'closed'], true);

        $base = [
            'can_cancel'   => !$terminal,
            'can_ready'    => !$terminal,
            'can_delivered'=> !$terminal,
            'requires_cancel_reasons' => false,
            'is_terminal'  => $terminal,
            'platform'     => $plataforma ?: 'manual',
        ];

        if ($this->ehFood99($order)) {
            $estadoFood99 = $this->food99Service->getStoredOrderIntegrationState($order);
            $caps = $estadoFood99['capabilities'] ?? [];

            return array_merge($base, [
                'can_cancel'              => $caps['can_cancel'] ?? $base['can_cancel'],
                'can_ready'               => $caps['can_ready'] ?? $base['can_ready'],
                'can_delivered'           => $caps['can_delivered'] ?? $base['can_delivered'],
                'requires_cancel_reasons' => true,
                'is_terminal'             => $caps['is_terminal'] ?? $terminal,
                'requires_delivery_locator' => $caps['requires_delivery_locator'] ?? false,
                'delivery_locator_length'   => $caps['delivery_locator_length'] ?? 8,
                'delivery_code_length'      => $caps['delivery_code_length'] ?? 4,
            ]);
        }

        if ($this->ehIfood($order)) {
            $storedState = $this->iFoodService->getStoredOrderIntegrationState($order);
            $remoteOrderState = strtolower(trim((string) ($storedState['remote_order_state'] ?? '')));
            $remoteOrderState = str_replace(['.', '-', ' '], '_', $remoteOrderState);
            $isTerminalRemoteState = in_array($remoteOrderState, ['concluded', 'cancelled', 'canceled'], true);
            $isTerminal = $terminal || $isTerminalRemoteState;

            $canReadyStates = [
                '',
                'new',
                'order_created',
                'placed',
                'confirmed',
                'accepted',
                'preparing',
                'started',
                'ready',
                'ready_to_pickup',
            ];
            $canConfirmStates = ['', 'new', 'order_created', 'placed'];
            $dispatchFlow = strtolower(trim((string) ($storedState['delivered_by'] ?? '')));

            return array_merge($base, [
                'can_confirm'             => !$isTerminal && in_array($remoteOrderState, $canConfirmStates, true),
                'can_cancel'              => !$isTerminal,
                'can_ready'               => !$isTerminal && in_array($remoteOrderState, $canReadyStates, true),
                'can_delivered'           => false,
                'requires_cancel_reasons' => true,
                'is_terminal'             => $isTerminal,
                'remote_state'            => $remoteOrderState !== '' ? $remoteOrderState : null,
                'delivery_flow'           => $dispatchFlow !== '' ? $dispatchFlow : null,
            ]);
        }

        if (in_array($plataforma, ['whatsapp', 'instagram', 'messenger'], true)) {
            return array_merge($base, [
                'can_ready'     => false,
                'can_delivered' => false,
            ]);
        }

        return $base;
    }

    public function getCancelReasons(Order $order): array
    {
        if ($this->ehFood99($order)) {
            return $this->food99Service->getOrderCancelReasons($order);
        }

        if ($this->ehIfood($order)) {
            $reasons = $this->iFoodService->getIfoodCancellationReasons();
            return ['data' => ['reasons' => $reasons]];
        }

        return ['data' => ['reasons' => []]];
    }

    public function confirm(Order $order): array
    {
        if ($this->ehIfood($order)) {
            return $this->iFoodService->performConfirmAction($order);
        }

        return ['errno' => 1, 'errmsg' => 'Confirmacao nao suportada para esta plataforma.'];
    }

    public function cancel(Order $order, ?int $reasonId = null, ?string $reason = null): array
    {
        if ($this->ehFood99($order)) {
            return $this->food99Service->performCancelAction($order, $reasonId, $reason);
        }

        if ($this->ehIfood($order)) {
            $cancellationCode = $reasonId !== null ? (string) $reasonId : null;
            return $this->iFoodService->performCancelAction($order, $reason, $cancellationCode);
        }

        return $this->aplicarStatusLocal($order, 'canceled', 'canceled');
    }

    public function ready(Order $order): array
    {
        if ($this->ehFood99($order)) {
            return $this->food99Service->performReadyAction($order);
        }

        if ($this->ehIfood($order)) {
            return $this->iFoodService->performReadyAction($order);
        }

        return $this->aplicarStatusLocal($order, 'open', 'ready');
    }

    public function delivered(Order $order, ?string $deliveryCode = null, ?string $locator = null): array
    {
        if ($this->ehFood99($order)) {
            return $this->food99Service->performDeliveredAction($order, $deliveryCode, $locator);
        }

        if ($this->ehIfood($order)) {
            return $this->iFoodService->performDeliveredAction($order);
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
