<?php

namespace ControleOnline\Controller\iFood;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Service\iFoodService;
use ControleOnline\Service\OrderActionService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface as Security;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;

#[SecurityAttribute("is_granted('ROLE_ADMIN') or is_granted('ROLE_CLIENT')")]
class IntegrationController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $manager,
        private Security $security,
        private iFoodService $iFoodService,
        private OrderActionService $orderActionService,
    ) {}

    private function normalizeString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (!is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private function getAuthenticatedPeople(): ?People
    {
        $user = $this->security->getToken()?->getUser();

        if (!is_object($user) || !method_exists($user, 'getPeople')) {
            return null;
        }

        $people = $user->getPeople();

        return $people instanceof People ? $people : null;
    }

    private function isAdminUser(): bool
    {
        $user = $this->security->getToken()?->getUser();
        $roles = is_object($user) && method_exists($user, 'getRoles') ? (array) $user->getRoles() : [];

        return in_array('ROLE_ADMIN', $roles, true);
    }

    private function canAccessOrder(Order $order): bool
    {
        if ($this->isAdminUser()) {
            return true;
        }

        $userPeople = $this->getAuthenticatedPeople();
        if (!$userPeople) {
            return false;
        }

        $provider = $order->getProvider();
        if (!$provider instanceof People) {
            return false;
        }

        if ($userPeople->getId() === $provider->getId()) {
            return true;
        }

        $sql = <<<SQL
            SELECT COUNT(1)
            FROM people_link
            WHERE company_id = :companyId
              AND people_id = :peopleId
              AND enable = 1
        SQL;

        $count = (int) $this->manager->getConnection()->fetchOne($sql, [
            'companyId' => $provider->getId(),
            'peopleId' => $userPeople->getId(),
        ]);

        return $count > 0;
    }

    private function resolveOrder(string|int $orderId): ?Order
    {
        $id = (int) preg_replace('/\D+/', '', (string) $orderId);
        if ($id <= 0) {
            return null;
        }

        $order = $this->manager->getRepository(Order::class)->find($id);
        if (!$order instanceof Order) {
            return null;
        }

        if (!$this->canAccessOrder($order)) {
            return null;
        }

        return $order;
    }

    private function isIfoodOrder(Order $order): bool
    {
        return $this->normalizeString($order->getApp()) !== ''
            && strtolower($this->normalizeString($order->getApp())) === 'ifood';
    }

    private function orderNotFound(): JsonResponse
    {
        return new JsonResponse(
            ['error' => 'Pedido nao encontrado ou acesso negado'],
            Response::HTTP_FORBIDDEN
        );
    }

    private function hasErrnoError(mixed $value): bool
    {
        $normalized = $this->normalizeString($value);
        if ($normalized === '') {
            return false;
        }

        return $normalized !== '0';
    }

    private function resolveAgeInMinutes(mixed $dateTimeValue): ?int
    {
        $normalized = $this->normalizeString($dateTimeValue);
        if ($normalized === '') {
            return null;
        }

        try {
            $dateTime = new DateTimeImmutable($normalized);
        } catch (\Throwable) {
            return null;
        }

        $now = new DateTimeImmutable('now');
        $seconds = $now->getTimestamp() - $dateTime->getTimestamp();
        if ($seconds < 0) {
            return 0;
        }

        return (int) floor($seconds / 60);
    }

    private function resolveRemoteOrderStateLabel(?string $state): string
    {
        return match (strtolower($this->normalizeString($state))) {
            'new', 'placed' => 'Novo',
            'confirmed' => 'Confirmado',
            'preparing', 'started' => 'Preparando',
            'ready' => 'Pronto',
            'dispatching', 'dispatched' => 'Em entrega',
            'concluded', 'closed' => 'Concluido',
            'cancelled', 'canceled' => 'Cancelado',
            'cancellation_requested' => 'Cancelamento solicitado',
            default => 'Indefinido',
        };
    }

    private function decodeOrderOtherInformations(Order $order): array
    {
        $raw = $order->getOtherInformations(true);
        if (is_array($raw)) {
            return $raw;
        }

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    private function payloadHasOrderData(array $payload): bool
    {
        if (!is_array($payload['order'] ?? null)) {
            return false;
        }

        $orderData = $payload['order'];
        if (!empty($orderData['displayId'])) {
            return true;
        }

        if (!empty($orderData['delivery']) || !empty($orderData['items']) || !empty($orderData['payments'])) {
            return true;
        }

        return !empty($orderData['total']);
    }

    private function resolveLatestOrderPayload(Order $order): array
    {
        $otherInformations = $this->decodeOrderOtherInformations($order);
        if (!$otherInformations) {
            return [];
        }

        $latestEventType = $this->normalizeString($otherInformations['latest_event_type'] ?? null);
        if ($latestEventType !== '' && is_array($otherInformations[$latestEventType] ?? null)) {
            $latestPayload = $otherInformations[$latestEventType];
            if ($this->payloadHasOrderData($latestPayload)) {
                return $latestPayload;
            }
        }

        $firstEventPayload = [];
        foreach ($otherInformations as $value) {
            if (is_array($value) && isset($value['orderId'])) {
                if ($firstEventPayload === []) {
                    $firstEventPayload = $value;
                }

                if ($this->payloadHasOrderData($value)) {
                    return $value;
                }
            }
        }

        return $firstEventPayload;
    }

    private function resolveDeliveryContext(Order $order, array $payload): array
    {
        $delivery = is_array($payload['order']['delivery'] ?? null) ? $payload['order']['delivery'] : [];
        $deliveredBy = strtoupper($this->normalizeString($delivery['deliveredBy'] ?? null));

        $isStoreDelivery = $deliveredBy === 'MERCHANT';
        $isPlatformDelivery = $deliveredBy === 'IFOOD';

        $deliveryLabel = 'Entrega indefinida';
        if ($isStoreDelivery) {
            $deliveryLabel = 'Entrega da loja';
        } elseif ($isPlatformDelivery) {
            $deliveryLabel = 'Entrega iFood';
        }

        return [
            'delivery_label' => $deliveryLabel,
            'is_store_delivery' => $isStoreDelivery,
            'is_platform_delivery' => $isPlatformDelivery,
        ];
    }

    private function buildOrderIntegrationDetail(Order $order): array
    {
        $storedState = $this->iFoodService->getStoredOrderIntegrationState($order);
        $capabilities = $this->orderActionService->getCapabilities($order);
        $payload = $this->resolveLatestOrderPayload($order);
        $deliveryContext = $this->resolveDeliveryContext($order, $payload);
        $remoteState = $this->normalizeString($storedState['remote_order_state'] ?? null);
        $orderComments = method_exists($order, 'getComments')
            ? $this->normalizeString($order->getComments())
            : '';

        $orderIndex = $this->normalizeString(
            $payload['order']['displayId']
                ?? $payload['displayId']
                ?? $storedState['ifood_code']
                ?? null
        );

        $remark = $this->normalizeString(
            $payload['order']['additionalInfo']['notes']
                ?? $payload['order']['additionalInfo']
                ?? $payload['order']['orderComment']
                ?? $orderComments
                ?? null
        );

        return [
            'order' => [
                'id' => $order->getId(),
                'app' => $order->getApp(),
                'status' => [
                    'id' => $order->getStatus()?->getId(),
                    'status' => $order->getStatus()?->getStatus(),
                    'real_status' => $order->getStatus()?->getRealStatus(),
                ],
            ],
            'integration' => [
                'key' => 'ifood',
                'ifood_id' => $storedState['ifood_id'] ?? null,
                'ifood_code' => $storedState['ifood_code'] ?? null,
                'merchant_id' => $storedState['merchant_id'] ?? null,
                'remote_order_state' => $remoteState,
                'remote_order_state_label' => $this->resolveRemoteOrderStateLabel($remoteState),
                'last_event_type' => $storedState['last_event_type'] ?? null,
                'last_event_at' => $storedState['last_event_at'] ?? null,
                'last_action' => $storedState['last_action'] ?? null,
                'last_action_at' => $storedState['last_action_at'] ?? null,
                'last_action_errno' => $storedState['last_action_errno'] ?? null,
                'last_action_message' => $storedState['last_action_message'] ?? null,
                'cancel_reason' => $storedState['cancel_reason'] ?? null,
                'webhook_event_id' => $storedState['webhook_event_id'] ?? null,
                'webhook_event_type' => $storedState['webhook_event_type'] ?? null,
                'webhook_event_at' => $storedState['webhook_event_at'] ?? null,
                'webhook_received_at' => $storedState['webhook_received_at'] ?? null,
                'webhook_processed_at' => $storedState['webhook_processed_at'] ?? null,
                'last_integration_id' => $storedState['last_integration_id'] ?? null,
            ],
            'delivery' => [
                'delivery_label' => $deliveryContext['delivery_label'],
                'remote_delivery_status' => $remoteState,
                'expected_arrived_eta' => null,
                'pickup_code' => null,
                'handover_code' => null,
                'locator' => null,
                'handover_page_url' => null,
                'handover_confirmation_url' => null,
                'virtual_phone_number' => null,
                'rider_name' => null,
                'rider_phone' => null,
                'rider_to_store_eta' => null,
                'is_store_delivery' => $deliveryContext['is_store_delivery'],
                'is_platform_delivery' => $deliveryContext['is_platform_delivery'],
                'allows_manual_delivery_completion' => (bool) ($capabilities['can_delivered'] ?? false),
            ],
            'observability' => [
                'has_action_error' => $this->hasErrnoError($storedState['last_action_errno'] ?? null),
                'is_healthy' => !$this->hasErrnoError($storedState['last_action_errno'] ?? null),
                'remote_state_age_minutes' => $this->resolveAgeInMinutes($storedState['last_event_at'] ?? null),
                'last_action_age_minutes' => $this->resolveAgeInMinutes($storedState['last_action_at'] ?? null),
            ],
            'identifiers' => [
                'order_index' => $orderIndex,
            ],
            'notes' => [
                'remark' => $remark,
            ],
            'capabilities' => $capabilities,
        ];
    }

    #[Route('/marketplace/integrations/ifood/orders/{orderId}/state', name: 'marketplace_integrations_ifood_order_state', methods: ['GET'])]
    public function getOrderState(string $orderId): JsonResponse
    {
        $order = $this->resolveOrder($orderId);
        if (!$order) {
            return $this->orderNotFound();
        }

        if (!$this->isIfoodOrder($order)) {
            return new JsonResponse([
                'error' => 'Order is not linked to iFood',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse($this->buildOrderIntegrationDetail($order));
    }
}
