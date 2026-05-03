<?php

namespace ControleOnline\Controller\Marketplace;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Service\MarketplaceOrderFinancialGenerationService;
use ControleOnline\Service\PeopleService;
use ControleOnline\Service\RequestPayloadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface as Security;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;

#[SecurityAttribute("is_granted('ROLE_HUMAN')")]
class GenerateOrderInvoicesController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $manager,
        private Security $security,
        private PeopleService $peopleService,
        private RequestPayloadService $requestPayloadService,
        private MarketplaceOrderFinancialGenerationService $marketplaceOrderFinancialGenerationService,
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

    private function isSupportedMarketplaceOrder(Order $order): bool
    {
        $normalizedApp = strtolower(trim((string) $order->getApp()));

        return in_array(
            $normalizedApp,
            [
                strtolower(Order::APP_IFOOD),
                strtolower(Order::APP_FOOD99),
            ],
            true
        );
    }

    #[Route('/marketplace/integrations/orders/{orderId}/invoices', name: 'marketplace_integrations_order_generate_invoices', methods: ['POST'])]
    public function __invoke(string $orderId): JsonResponse
    {
        $order = $this->resolveOrder($orderId);
        if (!$order) {
            return new JsonResponse([
                'error' => 'Order not found or access denied',
            ], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isSupportedMarketplaceOrder($order)) {
            return new JsonResponse([
                'error' => 'Order is not linked to a supported marketplace integration',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->marketplaceOrderFinancialGenerationService->generate($order);
        } catch (\RuntimeException $exception) {
            return new JsonResponse([
                'error' => $exception->getMessage(),
            ], Response::HTTP_CONFLICT);
        }

        return new JsonResponse([
            'success' => true,
            'message' => 'Financeiro do marketplace gerado com sucesso.',
            'data' => $result,
        ], Response::HTTP_OK);
    }
}
