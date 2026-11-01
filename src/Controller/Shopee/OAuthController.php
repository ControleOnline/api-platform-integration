<?php

declare(strict_types=1);

namespace ControleOnline\Controller\Shopee;

use ControleOnline\Entity\People;
use ControleOnline\Service\PeopleService;
use ControleOnline\Service\ShopeeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
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
        private readonly ShopeeService $shopeeService,
    ) {
    }

    #[Route('/integrations/shopee/authorization-page', name: 'integrations_shopee_authorization_page', methods: ['POST'])]
    #[SecurityAttribute("is_granted('ROLE_HUMAN')")]
    public function getAuthorizationPage(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider instanceof People) {
            return new JsonResponse(['error' => 'provider_not_found'], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->shopeeService->buildAuthorizationPage(
            $provider,
            $request->getSchemeAndHttpHost(),
            $this->resolveReturnUrl($request, $payload)
        );

        return new JsonResponse($result, !empty($result['success']) ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
    }

    #[Route('/integrations/shopee/status', name: 'integrations_shopee_status', methods: ['GET', 'POST'])]
    #[SecurityAttribute("is_granted('ROLE_HUMAN')")]
    public function status(Request $request): JsonResponse
    {
        try {
            $payload = $request->getMethod() === 'POST' ? $this->parseJsonBody($request) : [];
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider instanceof People) {
            return new JsonResponse(['error' => 'provider_not_found'], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($this->shopeeService->getConnectionStatus($provider));
    }

    #[Route('/integrations/shopee/disconnect', name: 'integrations_shopee_disconnect', methods: ['POST'])]
    #[SecurityAttribute("is_granted('ROLE_HUMAN')")]
    public function disconnect(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider instanceof People) {
            return new JsonResponse(['error' => 'provider_not_found'], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($this->shopeeService->disconnect($provider));
    }

    #[Route('/oauth/shopee/return', name: 'integrations_shopee_oauth_callback', methods: ['GET'])]
    public function callback(Request $request): RedirectResponse
    {
        $state = trim((string) $request->query->get('state', ''));
        $error = trim((string) $request->query->get('error', ''));
        if ($state === '') {
            return $this->redirectWithStatus('/integrations-page', false, $error !== '' ? $error : 'missing_state');
        }

        try {
            $returnUrl = $this->shopeeService->resolveOAuthReturnUrl($state);
        } catch (\Throwable $e) {
            return $this->redirectWithStatus('/integrations-page', false, $e->getMessage());
        }

        // Full token exchange requires partner_key signing — mark pending until configured.
        return $this->redirectWithStatus($returnUrl, false, 'shopee_token_exchange_pending_configuration');
    }

    private function redirectWithStatus(string $returnUrl, bool $ok, string $error = ''): RedirectResponse
    {
        $sep = str_contains($returnUrl, '?') ? '&' : '?';
        $qs = 'shopee_oauth=' . ($ok ? '1' : '0');
        if ($error !== '') {
            $qs .= '&error=' . rawurlencode($error);
        }

        return new RedirectResponse($returnUrl . $sep . $qs);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonBody(Request $request): array
    {
        $raw = $request->getContent();
        if ($raw === '' || $raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Invalid JSON');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveProvider(Request $request, array $payload = []): ?People
    {
        $providerId = $payload['provider_id']
            ?? $payload['company_id']
            ?? $request->query->get('provider_id')
            ?? $request->query->get('company_id');

        $userPeople = $this->getAuthenticatedPeople();
        if (!$providerId) {
            return $userPeople;
        }

        $providerId = (int) $providerId;
        $provider = $this->entityManager->getRepository(People::class)->find($providerId);
        if (!$provider instanceof People || !$userPeople) {
            return null;
        }

        if ($userPeople->getId() === $provider->getId()) {
            return $provider;
        }

        if (method_exists($this->peopleService, 'canAccessCompany')
            && $this->peopleService->canAccessCompany($provider, $userPeople)) {
            return $provider;
        }

        return null;
    }

    private function getAuthenticatedPeople(): ?People
    {
        $token = $this->security->getToken();
        $user = $token?->getUser();
        if (is_object($user) && method_exists($user, 'getPeople')) {
            $people = $user->getPeople();
            return $people instanceof People ? $people : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveReturnUrl(Request $request, array $payload): ?string
    {
        $returnUrl = $payload['return_url'] ?? $request->query->get('return_url');

        return is_string($returnUrl) ? $returnUrl : null;
    }
}
