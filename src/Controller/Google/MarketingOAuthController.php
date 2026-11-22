<?php

declare(strict_types=1);

namespace ControleOnline\Controller\Google;

use ControleOnline\Entity\People;
use ControleOnline\Service\Google\GoogleMarketingOAuthService;
use ControleOnline\Service\Google\GoogleMarketingScopes;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;

/**
 * Multi-tenant Google Marketing OAuth endpoints (Analytics, Ads, AdSense).
 * Mirrors the authorization/status/disconnect shape used by LinkedIn and
 * Mercado Livre OAuth controllers in this module.
 */
class MarketingOAuthController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GoogleMarketingOAuthService $oauthService,
    ) {
    }

    #[Route('/integrations/google-marketing/authorization-page', name: 'integrations_google_marketing_authorization_page', methods: ['POST'])]
    #[SecurityAttribute("is_granted('ROLE_HUMAN')")]
    public function authorizationPage(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($payload);
        if (!$provider instanceof People) {
            return new JsonResponse(['error' => 'provider_not_found'], Response::HTTP_BAD_REQUEST);
        }

        $scopeSet = (string) ($payload['scope_set'] ?? '');
        if (!GoogleMarketingScopes::isValidSet($scopeSet)) {
            return new JsonResponse(['error' => 'invalid_scope_set'], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->oauthService->buildAuthorizationPage(
            $provider,
            $scopeSet,
            $request->getSchemeAndHttpHost(),
            isset($payload['return_url']) ? (string) $payload['return_url'] : null,
            isset($payload['app_domain']) ? (string) $payload['app_domain'] : null,
        );

        $status = !empty($result['success']) ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST;
        return new JsonResponse($result, $status);
    }

    #[Route('/integrations/google-marketing/callback', name: 'integrations_google_marketing_callback', methods: ['GET'])]
    public function callback(Request $request): Response
    {
        $code = (string) $request->query->get('code', '');
        $state = (string) $request->query->get('state', '');
        if ($code === '' || $state === '') {
            return new JsonResponse(['error' => 'missing_code_or_state'], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->oauthService->handleCallback(
            $code,
            $state,
            fn (int $providerId): ?People => $this->entityManager->getRepository(People::class)->find($providerId),
        );

        if (empty($result['success'])) {
            return new JsonResponse($result, Response::HTTP_BAD_REQUEST);
        }

        $returnUrl = (string) ($result['return_url'] ?? '/integrations-page');
        return new RedirectResponse($returnUrl);
    }

    #[Route('/integrations/google-marketing/status', name: 'integrations_google_marketing_status', methods: ['GET', 'POST'])]
    #[SecurityAttribute("is_granted('ROLE_HUMAN')")]
    public function status(Request $request): JsonResponse
    {
        try {
            $payload = $request->getMethod() === 'POST' ? $this->parseJsonBody($request) : $request->query->all();
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($payload);
        if (!$provider instanceof People) {
            return new JsonResponse(['error' => 'provider_not_found'], Response::HTTP_BAD_REQUEST);
        }

        $scopeSet = (string) ($payload['scope_set'] ?? '');
        if (!GoogleMarketingScopes::isValidSet($scopeSet)) {
            return new JsonResponse(['error' => 'invalid_scope_set'], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($this->oauthService->getConnectionStatus($provider, $scopeSet));
    }

    #[Route('/integrations/google-marketing/disconnect', name: 'integrations_google_marketing_disconnect', methods: ['POST'])]
    #[SecurityAttribute("is_granted('ROLE_HUMAN')")]
    public function disconnect(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($payload);
        if (!$provider instanceof People) {
            return new JsonResponse(['error' => 'provider_not_found'], Response::HTTP_BAD_REQUEST);
        }

        $scopeSet = (string) ($payload['scope_set'] ?? '');
        if (!GoogleMarketingScopes::isValidSet($scopeSet)) {
            return new JsonResponse(['error' => 'invalid_scope_set'], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($this->oauthService->disconnect($provider, $scopeSet));
    }

    private function resolveProvider(array $payload): ?People
    {
        $reference = $payload['provider_id'] ?? $payload['people'] ?? $payload['people_id'] ?? null;
        if ($reference === null) {
            return null;
        }

        if (is_string($reference) && preg_match('#/people/(\d+)$#', $reference, $m)) {
            $reference = (int) $m[1];
        }

        if (!is_numeric($reference)) {
            return null;
        }

        return $this->entityManager->getRepository(People::class)->find((int) $reference);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonBody(Request $request): array
    {
        $raw = (string) $request->getContent();
        if ($raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Invalid JSON body');
        }

        return is_array($decoded) ? $decoded : [];
    }
}
