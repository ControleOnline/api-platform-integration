<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class PushNotificationService
{
    public function __construct(
        private EntityManagerInterface $manager,
        private ManagerOrderPushService $managerOrderPushService,
        private LoggerInterface $logger
    ) {}

    public function integrate(Integration $integration): void
    {
        $payload = json_decode((string) $integration->getBody(), true);
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Invalid PushNotification payload.');
        }

        $eventName = trim((string) ($payload['event'] ?? ''));
        if ($eventName === 'order.created') {
            $this->sendOrderCreated($payload, $integration);

            return;
        }

        $company = $this->resolveCompany($payload, $integration);
        if (!$company instanceof People) {
            $this->logger->warning('Manager push notification skipped because company was not found.', [
                'integrationId' => $integration->getId(),
                'event' => $eventName,
            ]);

            return;
        }

        $this->managerOrderPushService->sendCompanyEventNotification($company, $payload);
    }

    private function sendOrderCreated(array $payload, Integration $integration): void
    {
        $orderId = (int) ($payload['orderId'] ?? $payload['order'] ?? 0);
        if ($orderId <= 0) {
            throw new \InvalidArgumentException('orderId is required for order.created push notification.');
        }

        $order = $this->manager->getRepository(Order::class)->find($orderId);
        if (!$order instanceof Order) {
            $this->logger->warning('Manager order push notification skipped because order was not found.', [
                'integrationId' => $integration->getId(),
                'orderId' => $orderId,
            ]);

            return;
        }

        $this->managerOrderPushService->sendOrderCreatedNotification($order);
    }

    private function resolveCompany(array $payload, Integration $integration): ?People
    {
        $people = $integration->getPeople();
        if ($people instanceof People) {
            return $people;
        }

        $companyId = (int) (
            $payload['companyId']
            ?? $payload['company']
            ?? $payload['provider']
            ?? 0
        );

        if ($companyId <= 0) {
            return null;
        }

        $company = $this->manager->getRepository(People::class)->find($companyId);

        return $company instanceof People ? $company : null;
    }
}
