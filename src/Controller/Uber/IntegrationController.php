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
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
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

    private function resolveDefaultProvider(?People $userPeople): ?People
    {
        if (!$userPeople) {
            return null;
        }

        if ($this->peopleService->canAccessCompany($userPeople, $userPeople)) {
            return $userPeople;
        }

        $companies = $this->peopleService->getMyCompanies();

        return count($companies) === 1 ? $companies[0] : null;
    }

    private function parseJsonBody(Request $request): array
    {
        $content = trim((string) $request->getContent());
        if ($content === '') {
            return [];
        }

        return $this->requestPayloadService->decodeJsonContent($content);
    }

    private function resolveProvider(Request $request, array $payload = []): ?People
    {
        $providerId = $payload['provider_id']
            ?? $payload['company_id']
            ?? $request->query->get('provider_id')
            ?? $request->query->get('company_id');

        $userPeople = $this->getAuthenticatedPeople();

        if (!$providerId) {
            return $this->resolveDefaultProvider($userPeople);
        }

        $providerId = $this->requestPayloadService->normalizeOptionalNumericId($providerId);
        if (!$providerId) {
            return null;
        }

        $provider = $this->manager->getRepository(People::class)->find($providerId);
        if (!$provider instanceof People) {
            return null;
        }

        if (!$userPeople) {
            return null;
        }

        if (!$this->canAccessProvider($provider)) {
            return null;
        }

        return $provider;
    }

    private function providerErrorResponse(): JsonResponse
    {
        return new JsonResponse(
            $this->hydratorService->error(new \Exception('Provider not found or access denied')),
            Response::HTTP_FORBIDDEN
        );
    }

    private function normalizeString(mixed $value): string
    {
        return trim((string) $value);
    }

    private function resolveReturnPath(mixed $value): string
    {
        $path = trim((string) $value);
        if ($path === '') {
            return '/uber-integration-page';
        }

        if (preg_match('/^[a-z][a-z0-9+\-.]*:\/\//i', $path)) {
            return '/uber-integration-page';
        }

        if (!str_starts_with($path, '/')) {
            $path = '/' . ltrim($path, '/');
        }

        return $path;
    }

    private function resolveRequestOrigin(Request $request): string
    {
        $origin = trim((string) ($request->headers->get('origin') ?? ''));
        if ($origin !== '' && filter_var($origin, FILTER_VALIDATE_URL)) {
            return rtrim($origin, '/');
        }

        $referer = trim((string) ($request->headers->get('referer') ?? ''));
        if ($referer !== '') {
            $parts = parse_url($referer);
            if (is_array($parts) && !empty($parts['scheme']) && !empty($parts['host'])) {
                $base = $parts['scheme'] . '://' . $parts['host'];
                if (!empty($parts['port'])) {
                    $base .= ':' . $parts['port'];
                }

                return rtrim($base, '/');
            }
        }

        return rtrim($request->getSchemeAndHttpHost(), '/');
    }

    private function buildReturnUrl(Request $request, mixed $returnPath): string
    {
        return $this->resolveRequestOrigin($request) . $this->resolveReturnPath($returnPath);
    }

    private function buildOAuthState(People $provider, string $returnUrl): string
    {
        $payload = [
            'provider_id' => (int) $provider->getId(),
            'return_url' => $returnUrl,
            'issued_at' => time(),
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        return $encoded . '.' . $this->signOAuthState($json);
    }

    private function signOAuthState(string $json): string
    {
        $secret = $this->normalizeString(
            $_ENV['OAUTH_UBER_CLIENT_SECRET']
            ?? $_SERVER['OAUTH_UBER_CLIENT_SECRET']
            ?? getenv('OAUTH_UBER_CLIENT_SECRET')
            ?? $_ENV['UBER_CLIENT_SECRET']
            ?? $_SERVER['UBER_CLIENT_SECRET']
            ?? getenv('UBER_CLIENT_SECRET')
            ?? ''
        );

        return hash_hmac('sha256', $json, $secret);
    }

    private function decodeOAuthState(?string $state): ?array
    {
        $state = $this->normalizeString($state);
        if ($state === '') {
            return null;
        }

        $parts = explode('.', $state, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$encoded, $signature] = $parts;
        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($json === false) {
            return null;
        }

        if (!hash_equals($this->signOAuthState($json), $signature)) {
            return null;
        }

        $payload = json_decode($json, true);

        return is_array($payload) ? $payload : null;
    }

    private function appendQueryParameters(string $url, array $parameters): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return $url;
        }

        parse_str((string) ($parts['query'] ?? ''), $existingQuery);
        foreach ($parameters as $key => $value) {
            if ($value === null) {
                continue;
            }

            $normalizedValue = trim((string) $value);
            if ($normalizedValue === '') {
                continue;
            }

            $existingQuery[$key] = $normalizedValue;
        }

        $rebuilt = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $rebuilt .= ':' . $parts['port'];
        }
        $rebuilt .= $parts['path'] ?? '';

        $query = http_build_query($existingQuery, '', '&', PHP_QUERY_RFC3986);
        if ($query !== '') {
            $rebuilt .= '?' . $query;
        }

        if (!empty($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }

        return $rebuilt;
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

    #[Route('/marketplace/integrations/uber/store/authorization-page', name: 'marketplace_integrations_uber_authorization_page', methods: ['POST'])]
    public function getAuthorizationPage(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse($this->hydratorService->error($exception), Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider instanceof People) {
            return $this->providerErrorResponse();
        }

        $redirectUri = $this->generateUrl('marketplace_integrations_uber_oauth_return', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $returnUrl = $this->buildReturnUrl($request, $payload['return_path'] ?? $request->query->get('return_path'));
        $state = $this->buildOAuthState($provider, $returnUrl);

        return new JsonResponse($this->hydratorService->result([
            $this->uberService->buildAuthorizationUrl($redirectUri, $state) + [
                'state' => $state,
            ],
        ]));
    }

    #[Route('/oauth/uber/return', name: 'marketplace_integrations_uber_oauth_return', methods: ['GET'])]
    public function handleOAuthReturn(Request $request): Response
    {
        $statePayload = $this->decodeOAuthState($request->query->get('state'));
        if (!is_array($statePayload)) {
            return new JsonResponse($this->hydratorService->error(new \Exception('Uber OAuth state invalid')), Response::HTTP_BAD_REQUEST);
        }

        $returnUrl = $this->normalizeString($statePayload['return_url'] ?? '');
        if ($returnUrl === '') {
            return new JsonResponse($this->hydratorService->error(new \Exception('Uber return url invalid')), Response::HTTP_BAD_REQUEST);
        }

        $oauthError = $this->normalizeString($request->query->get('error'));
        if ($oauthError !== '') {
            $errorDescription = $this->normalizeString($request->query->get('error_description'));

            return new RedirectResponse($this->appendQueryParameters($returnUrl, [
                'oauth_status' => 'error',
                'oauth_error' => $errorDescription !== '' ? $errorDescription : $oauthError,
            ]));
        }

        $authorizationCode = $this->normalizeString($request->query->get('code'));
        if ($authorizationCode === '') {
            return new RedirectResponse($this->appendQueryParameters($returnUrl, [
                'oauth_status' => 'error',
                'oauth_error' => 'Código OAuth do Uber ausente.',
            ]));
        }

        $providerId = $this->requestPayloadService->normalizeOptionalNumericId($statePayload['provider_id'] ?? null);
        if (!$providerId) {
            return new RedirectResponse($this->appendQueryParameters($returnUrl, [
                'oauth_status' => 'error',
                'oauth_error' => 'Empresa Uber inválida.',
            ]));
        }

        $provider = $this->manager->getRepository(People::class)->find($providerId);
        if (!$provider instanceof People) {
            return new RedirectResponse($this->appendQueryParameters($returnUrl, [
                'oauth_status' => 'error',
                'oauth_error' => 'Empresa Uber não encontrada.',
            ]));
        }

        try {
            $result = $this->uberService->connectStoreViaOAuth(
                $provider,
                $authorizationCode,
                $this->generateUrl('marketplace_integrations_uber_oauth_return', [], UrlGeneratorInterface::ABSOLUTE_URL)
            );
            if ((int) ($result['errno'] ?? 1) !== 0) {
                throw new \RuntimeException((string) ($result['errmsg'] ?? 'Uber OAuth failed'), (int) ($result['errno'] ?? 500));
            }
        } catch (\Throwable $exception) {
            return new RedirectResponse($this->appendQueryParameters($returnUrl, [
                'oauth_status' => 'error',
                'oauth_error' => $exception->getMessage(),
            ]));
        }

        return new RedirectResponse($this->appendQueryParameters($returnUrl, [
            'oauth_status' => 'success',
            'oauth_store_id' => (string) ($result['data']['store_id'] ?? ''),
        ]));
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
