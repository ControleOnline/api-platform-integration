<?php

declare(strict_types=1);

namespace ControleOnline\Controller\Magalu;

use ControleOnline\Entity\People;
use ControleOnline\Service\MagaluService;
use ControleOnline\Service\PeopleService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface as Security;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;

class OAuthController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly PeopleService $peopleService,
        private readonly MagaluService $magaluService,
    ) {
    }

    #[Route('/integrations/magalu/authorization-page', name: 'integrations_magalu_authorization_page', methods: ['POST'])]
    #[SecurityAttribute("is_granted('ROLE_HUMAN')")]
    public function getAuthorizationPage(Request $request): JsonResponse
    {
        $payload = $this->jsonBody($request);
        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return new JsonResponse(['error' => 'provider_not_found'], Response::HTTP_BAD_REQUEST);
        }
        $result = $this->magaluService->buildAuthorizationPage(
            $provider,
            $request->getSchemeAndHttpHost(),
            is_string($payload['return_url'] ?? null) ? $payload['return_url'] : null
        );

        return new JsonResponse($result, !empty($result['success']) ? 200 : 400);
    }

    #[Route('/integrations/magalu/status', name: 'integrations_magalu_status', methods: ['GET', 'POST'])]
    #[SecurityAttribute("is_granted('ROLE_HUMAN')")]
    public function status(Request $request): JsonResponse
    {
        $payload = $request->getMethod() === 'POST' ? $this->jsonBody($request) : [];
        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return new JsonResponse(['error' => 'provider_not_found'], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($this->magaluService->getConnectionStatus($provider));
    }

    #[Route('/integrations/magalu/disconnect', name: 'integrations_magalu_disconnect', methods: ['POST'])]
    #[SecurityAttribute("is_granted('ROLE_HUMAN')")]
    public function disconnect(Request $request): JsonResponse
    {
        $payload = $this->jsonBody($request);
        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return new JsonResponse(['error' => 'provider_not_found'], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($this->magaluService->disconnect($provider));
    }

    private function jsonBody(Request $request): array
    {
        $data = json_decode($request->getContent() ?: '[]', true);

        return is_array($data) ? $data : [];
    }

    private function resolveProvider(Request $request, array $payload): ?People
    {
        $id = (int) ($payload['provider_id'] ?? $payload['company_id'] ?? $request->query->get('company_id') ?? 0);
        $user = $this->security->getToken()?->getUser();
        $userPeople = (is_object($user) && method_exists($user, 'getPeople')) ? $user->getPeople() : null;
        if (!$userPeople instanceof People) {
            return null;
        }
        if ($id <= 0) {
            return $userPeople;
        }
        $provider = $this->entityManager->find(People::class, $id);
        if (!$provider instanceof People) {
            return null;
        }
        if ($provider->getId() === $userPeople->getId()) {
            return $provider;
        }
        if (method_exists($this->peopleService, 'canAccessCompany') && $this->peopleService->canAccessCompany($provider, $userPeople)) {
            return $provider;
        }

        return null;
    }
}
