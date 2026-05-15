<?php

namespace ControleOnline\Controller\Uber;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Service\HydratorService;
use ControleOnline\Service\PeopleService;
use ControleOnline\Service\RequestPayloadService;
use ControleOnline\Service\UberService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface as Security;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;

#[SecurityAttribute("is_granted('ROLE_HUMAN')")]
class IntegrationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $manager,
        private readonly Security $security,
        private readonly PeopleService $peopleService,
        private readonly RequestPayloadService $requestPayloadService,
        private readonly HydratorService $hydratorService,
        private readonly UberService $uberService,
    ) {
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

    private function canAccessProvider(People $provider): bool
    {
        $userPeople = $this->getAuthenticatedPeople();
        if (!$userPeople) {
            return false;
        }

        if ($userPeople->getId() === $provider->getId()) {
            return true;
        }

        return $this->peopleService->canAccessCompany($provider, $userPeople);
    }

    private function resolveOrder(string|int $orderId): ?Order
    {
        $normalizedOrderId = $this->requestPayloadService->normalizeOptionalNumericId($orderId);
        if (!$normalizedOrderId) {
            return null;
        }

        $order = $this->manager->getRepository(Order::class)->find($normalizedOrderId);
        if (!$order instanceof Order) {
            return null;
        }

        $provider = $order->getProvider();
        if (!$provider instanceof People) {
            return null;
        }

        if (!$this->canAccessProvider($provider)) {
            return null;
        }

        return $order;
    }

    private function orderNotFoundResponse(): JsonResponse
    {
        return new JsonResponse(
            $this->hydratorService->error(new \Exception('Order not found or access denied')),
            Response::HTTP_FORBIDDEN
        );
    }

    #[Route('/marketplace/integrations/uber/orders/{orderId}/request-driver', name: 'marketplace_integrations_uber_request_driver', methods: ['POST'])]
    public function requestDriver(string $orderId): JsonResponse
    {
        $order = $this->resolveOrder($orderId);
        if (!$order instanceof Order) {
            return $this->orderNotFoundResponse();
        }

        try {
            $result = $this->uberService->requestDriver($order);
            if (is_array($result) && isset($result['errno']) && (int) $result['errno'] !== 0) {
                throw new \RuntimeException(
                    (string) ($result['errmsg'] ?? 'Uber request failed'),
                    (int) $result['errno']
                );
            }
        } catch (\RuntimeException $exception) {
            $statusCode = $exception->getCode() > 0 ? $exception->getCode() : Response::HTTP_CONFLICT;

            return new JsonResponse($this->hydratorService->error($exception), $statusCode);
        } catch (\Throwable $exception) {
            return new JsonResponse($this->hydratorService->error(new \Exception($exception->getMessage(), (int) $exception->getCode(), $exception)), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse($this->hydratorService->result([$result]), Response::HTTP_OK);
    }
}
