<?php

namespace ControleOnline\Controller\MercadoLivre;

use ControleOnline\Entity\People;
use ControleOnline\Service\DatabaseSwitchService;
use ControleOnline\Service\MercadoLivreService;
use ControleOnline\Service\PeopleService;
use ControleOnline\Service\RequestPayloadService;
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
        private readonly RequestPayloadService $requestPayloadService,
        private readonly MercadoLivreService $mercadoLivreService,
        private readonly DatabaseSwitchService $databaseSwitchService,
    ) {}

    #[Route('/marketplace/integrations/mercadolivre/authorization-page', name: 'marketplace_integrations_mercadolivre_authorization_page', methods: ['POST'])]
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

        $result = $this->mercadoLivreService->buildAuthorizationPage(
            $provider,
            $request->getSchemeAndHttpHost(),
            $this->resolveReturnUrl($request, $payload),
            $this->resolveAppDomain($request, $payload)
        );

        return new JsonResponse($result, !empty($result['success']) ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
    }

    #[Route('/oauth/mercadolivre/return', name: 'marketplace_integrations_mercadolivre_oauth_callback', methods: ['GET'])]
    public function callback(Request $request): RedirectResponse
    {
        $state = trim((string) $request->query->get('state', ''));
        $code = trim((string) $request->query->get('code', ''));
        $error = trim((string) $request->query->get('error', ''));
        $errorDescription = trim((string) $request->query->get('error_description', ''));

        if ($state === '') {
            return $this->redirectWithOAuthStatus('/integrations-page', false, $error !== '' ? $error : 'missing_state', $errorDescription);
        }

        try {
            $resolvedAppDomain = $this->mercadoLivreService->resolveOAuthAppDomain($state);
            $this->databaseSwitchService->switchDatabaseByDomain($resolvedAppDomain);
            $returnUrl = $this->mercadoLivreService->resolveOAuthReturnUrl($state);
        } catch (\Throwable $exception) {
            return $this->redirectWithOAuthStatus('/integrations-page', false, 'invalid_app_domain');
        }

        if ($error !== '' || $code === '') {
            return $this->redirectWithOAuthStatus($returnUrl, false, $error !== '' ? $error : 'missing_code', $errorDescription);
        }

        try {
            $result = $this->mercadoLivreService->connectViaOAuthCode(
                $code,
                $state,
                $request->getSchemeAndHttpHost() . $request->getPathInfo(),
                $resolvedAppDomain
            );
        } catch (\Throwable $exception) {
            return $this->redirectWithOAuthStatus(
                $returnUrl,
                false,
                'oauth_callback_failed',
                $exception->getMessage()
            );
        }

        return $this->redirectWithOAuthStatus(
            (string) ($result['return_url'] ?? '/integrations-page'),
            !empty($result['success']),
            !empty($result['success']) ? null : (string) ($result['error'] ?? 'oauth_failed'),
            !empty($result['success']) ? null : (string) ($result['message'] ?? '')
        );
    }

    private function parseJsonBody(Request $request): array
    {
        $content = trim((string) $request->getContent());
        if ($content === '') {
            return [];
        }

        return $this->requestPayloadService->decodeJsonContent($content);
    }

    private function resolveProvider(Request $request, array $payload): ?People
    {
        $providerId = $payload['provider_id']
            ?? $payload['company_id']
            ?? $request->query->get('provider_id')
            ?? $request->query->get('company_id');

        $providerId = $this->requestPayloadService->normalizeOptionalNumericId($providerId);
        if (!$providerId) {
            return null;
        }

        $provider = $this->entityManager->getRepository(People::class)->find($providerId);
        $userPeople = $this->getAuthenticatedPeople();
        if (!$provider instanceof People || !$userPeople instanceof People) {
            return null;
        }

        return $this->canAccessProvider($userPeople, $provider) ? $provider : null;
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

    private function canAccessProvider(People $userPeople, People $provider): bool
    {
        return $userPeople->getId() === $provider->getId()
            || $this->peopleService->canAccessCompany($provider, $userPeople);
    }

    private function resolveReturnUrl(Request $request, array $payload): string
    {
        $returnUrl = trim((string) ($payload['return_url'] ?? ''));
        if ($returnUrl !== '') {
            return $returnUrl;
        }

        $referer = trim((string) $request->headers->get('referer', ''));
        if ($referer !== '') {
            return $referer;
        }

        $origin = trim((string) $request->headers->get('origin', ''));

        return $origin !== '' ? rtrim($origin, '/') . '/integrations-page' : '/integrations-page';
    }

    private function resolveAppDomain(Request $request, array $payload): string
    {
        $candidates = [
            $request->headers->get('app-domain'),
            $payload['app_domain'] ?? null,
            $payload['appDomain'] ?? null,
            $payload['domain'] ?? null,
            $payload['return_url'] ?? null,
            $request->headers->get('origin'),
            $request->headers->get('referer'),
        ];

        foreach ($candidates as $candidate) {
            $domain = $this->normalizeDomainCandidate($candidate);
            if ($domain !== '') {
                return $domain;
            }
        }

        return '';
    }

    private function normalizeDomainCandidate(mixed $candidate): string
    {
        if (!is_string($candidate)) {
            return '';
        }

        $candidate = strtolower(trim($candidate));
        if ($candidate === '' || in_array($candidate, ['undefined', 'null', 'false'], true)) {
            return '';
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $candidate)) {
            $host = parse_url($candidate, PHP_URL_HOST);
            $candidate = is_string($host) ? $host : '';
        }

        $candidate = preg_replace('/[\/?#].*$/', '', $candidate) ?? '';
        $candidate = preg_replace('/[^a-z0-9.:-]/', '', $candidate) ?? '';

        return $candidate;
    }

    private function redirectWithOAuthStatus(string $returnUrl, bool $success, ?string $error = null, ?string $message = null): RedirectResponse
    {
        $separator = str_contains($returnUrl, '?') ? '&' : '?';
        $target = $returnUrl . $separator . http_build_query(array_filter([
            'mercadolivre_connected' => $success ? '1' : null,
            'mercadolivre_error' => !$success ? ($error ?: 'oauth_failed') : null,
            'mercadolivre_message' => !$success ? $this->sanitizeOAuthMessage($message) : null,
        ]), '', '&', PHP_QUERY_RFC3986);

        return new RedirectResponse($target);
    }

    private function sanitizeOAuthMessage(?string $message): string
    {
        $message = trim((string) $message);
        if ($message === '') {
            return '';
        }

        $message = preg_replace('/[^\pL\pN\s.,:;!?_@\/-]/u', '', $message) ?? '';
        $message = preg_replace('/\s+/', ' ', $message) ?? '';

        return substr(trim($message), 0, 180);
    }
}
