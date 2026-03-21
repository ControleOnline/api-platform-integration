<?php

namespace ControleOnline\Controller;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Service\LoggerService;
use ControleOnline\Service\OrderActionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface as Security;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;

#[SecurityAttribute("is_granted('ROLE_ADMIN') or is_granted('ROLE_CLIENT')")]
class OrderActionController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $manager,
        private Security $security,
        private OrderActionService $orderActionService,
        private LoggerService $loggerService,
    ) {}

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
        $user  = $this->security->getToken()?->getUser();
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
            'peopleId'  => $userPeople->getId(),
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

    private function parseJsonBody(Request $request): array
    {
        $content = trim((string) $request->getContent());
        if ($content === '') {
            return [];
        }

        $json = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($json)) {
            throw new \InvalidArgumentException('JSON inválido');
        }

        return $json;
    }

    private function orderNotFound(): JsonResponse
    {
        return new JsonResponse(
            ['error' => 'Pedido não encontrado ou acesso negado'],
            Response::HTTP_FORBIDDEN
        );
    }

    #[Route('/orders/{orderId}/capabilities', name: 'order_action_capabilities', methods: ['GET'])]
    public function capabilities(string $orderId): JsonResponse
    {
        $order = $this->resolveOrder($orderId);
        if (!$order) {
            return $this->orderNotFound();
        }

        return new JsonResponse($this->orderActionService->getCapabilities($order));
    }

    #[Route('/orders/{orderId}/cancel-reasons', name: 'order_action_cancel_reasons', methods: ['GET'])]
    public function cancelReasons(string $orderId): JsonResponse
    {
        $order = $this->resolveOrder($orderId);
        if (!$order) {
            return $this->orderNotFound();
        }

        return new JsonResponse([
            'action' => 'cancel_reasons',
            'result' => $this->orderActionService->getCancelReasons($order),
        ]);
    }

    #[Route('/orders/{orderId}/cancel', name: 'order_action_cancel', methods: ['POST'])]
    public function cancel(string $orderId, Request $request): JsonResponse
    {
        $order = $this->resolveOrder($orderId);
        if (!$order) {
            return $this->orderNotFound();
        }

        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'JSON inválido'], Response::HTTP_BAD_REQUEST);
        }

        $reasonId = $payload['reason_id'] ?? $payload['reasonId'] ?? null;
        $reason   = trim((string) ($payload['reason'] ?? ''));

        $result = $this->orderActionService->cancel(
            $order,
            $reasonId !== null && $reasonId !== '' ? (int) preg_replace('/\D+/', '', (string) $reasonId) : null,
            $reason !== '' ? $reason : null
        );

        return new JsonResponse([
            'action'       => 'cancel',
            'result'       => $result,
            'capabilities' => $this->orderActionService->getCapabilities($order),
        ]);
    }

    #[Route('/orders/{orderId}/ready', name: 'order_action_ready', methods: ['POST'])]
    public function ready(string $orderId): JsonResponse
    {
        $order = $this->resolveOrder($orderId);
        if (!$order) {
            return $this->orderNotFound();
        }

        $result = $this->orderActionService->ready($order);

        return new JsonResponse([
            'action'       => 'ready',
            'result'       => $result,
            'capabilities' => $this->orderActionService->getCapabilities($order),
        ]);
    }

    #[Route('/orders/{orderId}/delivered', name: 'order_action_delivered', methods: ['POST'])]
    public function delivered(string $orderId, Request $request): JsonResponse
    {
        $order = $this->resolveOrder($orderId);
        if (!$order) {
            return $this->orderNotFound();
        }

        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'JSON inválido'], Response::HTTP_BAD_REQUEST);
        }

        $deliveryCode = trim((string) ($payload['delivery_code'] ?? $payload['deliveryCode'] ?? ''));
        $locator      = trim((string) ($payload['locator'] ?? ''));

        $result = $this->orderActionService->delivered(
            $order,
            $deliveryCode !== '' ? $deliveryCode : null,
            $locator !== '' ? $locator : null
        );

        return new JsonResponse([
            'action'       => 'delivered',
            'result'       => $result,
            'capabilities' => $this->orderActionService->getCapabilities($order),
        ]);
    }
}
