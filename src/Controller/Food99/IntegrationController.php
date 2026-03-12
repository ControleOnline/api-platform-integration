<?php

namespace ControleOnline\Controller\Food99;

use ControleOnline\Entity\People;
use ControleOnline\Service\Food99Service;
use ControleOnline\Service\LoggerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface as Security;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;

#[SecurityAttribute("is_granted('ROLE_ADMIN') or is_granted('ROLE_CLIENT')")]
class IntegrationController extends AbstractController
{
    protected static $logger;

    public function __construct(
        private EntityManagerInterface $manager,
        private LoggerService $loggerService,
        private Security $security,
        private Food99Service $food99Service,
    ) {
        self::$logger = $loggerService->getLogger('Food99');
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

    private function parseJsonBody(Request $request): array
    {
        $content = trim((string) $request->getContent());
        if ($content === '') {
            return [];
        }

        $json = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($json)) {
            throw new \InvalidArgumentException('Invalid JSON');
        }

        return $json;
    }

    private function canAccessProvider(People $userPeople, People $provider): bool
    {
        if ($this->isAdminUser()) {
            return true;
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

        $providerId = (int) preg_replace('/\D+/', '', (string) $providerId);
        if ($providerId <= 0) {
            return null;
        }

        $provider = $this->manager->getRepository(People::class)->find($providerId);
        if (!$provider) {
            return null;
        }

        if (!$userPeople && !$this->isAdminUser()) {
            return null;
        }

        if ($userPeople && !$this->canAccessProvider($userPeople, $provider)) {
            return null;
        }

        return $provider;
    }

    private function providerErrorResponse(): JsonResponse
    {
        return new JsonResponse([
            'error' => 'Provider not found or access denied',
        ], Response::HTTP_FORBIDDEN);
    }

    private function extractProductIds(array $payload): array
    {
        $productIds = $payload['product_ids'] ?? $payload['products'] ?? [];

        if (!is_array($productIds)) {
            return [];
        }

        return $productIds;
    }

    #[Route('/marketplace/integrations', name: 'marketplace_integrations', methods: ['GET'])]
    public function listIntegrations(Request $request): JsonResponse
    {
        $provider = $this->resolveProvider($request);
        if (!$provider) {
            return $this->providerErrorResponse();
        }

        $products = $this->food99Service->listSelectableMenuProducts($provider);
        $integratedStoreCode = null;
        $storeDetails = null;

        try {
            $integratedStoreCode = $this->food99Service->getIntegratedStoreCode($provider);
        } catch (\Throwable $e) {
            self::$logger->error('Food99 local integration lookup error', [
                'provider_id' => $provider->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $storeDetails = $this->food99Service->getStoreDetails($provider);
        } catch (\Throwable $e) {
            self::$logger->error('Food99 remote integration lookup error', [
                'provider_id' => $provider->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        $remoteConnected = is_array($storeDetails) && (($storeDetails['errno'] ?? 1) === 0);
        $connected = !empty($integratedStoreCode) || $remoteConnected;

        return new JsonResponse([
            'provider' => [
                'id' => $provider->getId(),
                'name' => method_exists($provider, 'getName') ? $provider->getName() : null,
            ],
            'items' => [[
                'key' => '99food',
                'label' => '99Food',
                'minimum_required_items' => 5,
                'eligible_product_count' => $products['eligible_product_count'] ?? 0,
                'connected' => $connected,
                'remote_connected' => $remoteConnected,
                'food99_code' => $integratedStoreCode,
                'store' => $remoteConnected ? ($storeDetails['data'] ?? null) : null,
                'store_error' => $remoteConnected ? null : [
                    'errno' => $storeDetails['errno'] ?? null,
                    'errmsg' => $storeDetails['errmsg'] ?? null,
                ],
            ]],
        ]);
    }

    #[Route('/marketplace/integrations/99food/store', name: 'marketplace_integrations_food99_store', methods: ['GET'])]
    public function getStore(Request $request): JsonResponse
    {
        $provider = $this->resolveProvider($request);
        if (!$provider) {
            return $this->providerErrorResponse();
        }

        $storeDetails = $this->food99Service->getStoreDetails($provider);
        $deliveryAreas = $this->food99Service->listDeliveryAreas($provider);
        $menuDetails = $this->food99Service->getStoreMenuDetails($provider);

        return new JsonResponse([
            'provider' => [
                'id' => $provider->getId(),
                'name' => method_exists($provider, 'getName') ? $provider->getName() : null,
            ],
            'store' => $storeDetails,
            'delivery_areas' => $deliveryAreas,
            'menu' => $menuDetails,
        ]);
    }

    #[Route('/marketplace/integrations/99food/store/authorization-page', name: 'marketplace_integrations_food99_authorization_page', methods: ['POST'])]
    public function getAuthorizationPage(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerErrorResponse();
        }

        $payload['app_shop_id'] = (string) ($payload['app_shop_id'] ?? $provider->getId());

        return new JsonResponse($this->food99Service->getAuthorizationPage($payload));
    }

    #[Route('/marketplace/integrations/99food/store/categories', name: 'marketplace_integrations_food99_categories', methods: ['GET'])]
    public function getStoreCategories(Request $request): JsonResponse
    {
        $provider = $this->resolveProvider($request);
        if (!$provider) {
            return $this->providerErrorResponse();
        }

        return new JsonResponse($this->food99Service->getStoreCategories($provider));
    }

    #[Route('/marketplace/integrations/99food/store/status', name: 'marketplace_integrations_food99_status', methods: ['POST'])]
    public function setStoreStatus(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerErrorResponse();
        }

        $bizStatus = isset($payload['biz_status']) ? (int) $payload['biz_status'] : null;
        if (!in_array($bizStatus, [1, 2], true)) {
            return new JsonResponse([
                'error' => 'biz_status must be 1 or 2',
            ], Response::HTTP_BAD_REQUEST);
        }

        $autoSwitch = isset($payload['auto_switch']) ? (int) $payload['auto_switch'] : null;

        return new JsonResponse($this->food99Service->setStoreStatus($provider, $bizStatus, $autoSwitch));
    }

    #[Route('/marketplace/integrations/99food/products', name: 'marketplace_integrations_food99_products', methods: ['GET'])]
    public function listProducts(Request $request): JsonResponse
    {
        $provider = $this->resolveProvider($request);
        if (!$provider) {
            return $this->providerErrorResponse();
        }

        return new JsonResponse($this->food99Service->listSelectableMenuProducts($provider));
    }

    #[Route('/marketplace/integrations/99food/menu/preview', name: 'marketplace_integrations_food99_menu_preview', methods: ['POST'])]
    public function previewMenuUpload(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerErrorResponse();
        }

        $preview = $this->food99Service->buildStoreMenuPayloadFromProducts($provider, $this->extractProductIds($payload));
        $statusCode = empty($preview['errors']) ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY;

        return new JsonResponse($preview, $statusCode);
    }

    #[Route('/marketplace/integrations/99food/menu/upload', name: 'marketplace_integrations_food99_menu_upload', methods: ['POST'])]
    public function uploadMenu(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerErrorResponse();
        }

        $productIds = $this->extractProductIds($payload);
        $preview = $this->food99Service->buildStoreMenuPayloadFromProducts($provider, $productIds);
        if (!empty($preview['errors'])) {
            return new JsonResponse($preview, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->food99Service->ensureMenuProductCodes($provider, $productIds);
        $result = $this->food99Service->uploadStoreMenu($provider, $preview['payload']);

        return new JsonResponse([
            'provider_id' => $provider->getId(),
            'selected_product_count' => $preview['selected_product_count'] ?? 0,
            'eligible_product_count' => $preview['eligible_product_count'] ?? 0,
            'result' => $result,
            'payload' => $preview['payload'],
        ]);
    }

    #[Route('/marketplace/integrations/99food/menu/task/{taskId}', name: 'marketplace_integrations_food99_menu_task', methods: ['GET'])]
    public function getMenuTask(Request $request, string $taskId): JsonResponse
    {
        $provider = $this->resolveProvider($request);
        if (!$provider) {
            return $this->providerErrorResponse();
        }

        return new JsonResponse($this->food99Service->getMenuUploadTaskInfo($provider, $taskId));
    }

    #[Route('/marketplace/integrations/99food/delivery-areas', name: 'marketplace_integrations_food99_delivery_areas', methods: ['GET'])]
    public function getDeliveryAreas(Request $request): JsonResponse
    {
        $provider = $this->resolveProvider($request);
        if (!$provider) {
            return $this->providerErrorResponse();
        }

        return new JsonResponse($this->food99Service->listDeliveryAreas($provider));
    }
}
