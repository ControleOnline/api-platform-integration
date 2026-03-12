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

    private function resolveBizStatusLabel(?int $bizStatus): string
    {
        return match ($bizStatus) {
            1 => 'Online',
            2 => 'Offline',
            default => 'Indefinido',
        };
    }

    private function resolveSubBizStatusLabel(?int $subStatus): string
    {
        return match ($subStatus) {
            1 => 'Pronta',
            2 => 'Pausada',
            3 => 'Fechada',
            default => 'Indefinido',
        };
    }

    private function resolvePublishedRemoteItemIds(?array $menuDetails): array
    {
        $items = is_array($menuDetails['data']['items'] ?? null) ? $menuDetails['data']['items'] : [];
        $remoteItemIds = [];

        foreach ($items as $item) {
            if (!is_array($item) || empty($item['app_item_id'])) {
                continue;
            }

            $remoteItemIds[] = (string) $item['app_item_id'];
        }

        return array_values(array_unique($remoteItemIds));
    }

    private function mapProductsWithRemoteCatalog(array $products, array $remoteItemIds): array
    {
        $remoteItemIdSet = array_flip($remoteItemIds);

        return array_map(static function (array $product) use ($remoteItemIdSet) {
            $candidateId = (string) ($product['food99_code'] ?: $product['suggested_app_item_id'] ?: $product['id']);
            $product['published_remotely'] = isset($remoteItemIdSet[$candidateId]);

            return $product;
        }, $products);
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

        try {
            $integratedStoreCode = $this->food99Service->getIntegratedStoreCode($provider);
        } catch (\Throwable $e) {
            self::$logger->error('Food99 local integration lookup error', [
                'provider_id' => $provider->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        $connected = !empty($integratedStoreCode);

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
                'remote_connected' => null,
                'food99_code' => $integratedStoreCode,
                'app_shop_id' => (string) $provider->getId(),
                'store' => null,
                'store_error' => null,
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

    #[Route('/marketplace/integrations/99food/detail', name: 'marketplace_integrations_food99_detail', methods: ['GET'])]
    public function getIntegrationDetail(Request $request): JsonResponse
    {
        $provider = $this->resolveProvider($request);
        if (!$provider) {
            return $this->providerErrorResponse();
        }

        $products = $this->food99Service->listSelectableMenuProducts($provider);
        $food99Code = $this->food99Service->getIntegratedStoreCode($provider);
        $connected = !empty($food99Code);

        $detail = [
            'provider' => [
                'id' => $provider->getId(),
                'name' => method_exists($provider, 'getName') ? $provider->getName() : null,
            ],
            'integration' => [
                'key' => '99food',
                'label' => '99Food',
                'minimum_required_items' => 5,
                'eligible_product_count' => $products['eligible_product_count'] ?? 0,
                'connected' => $connected,
                'remote_connected' => false,
                'food99_code' => $food99Code,
                'app_shop_id' => (string) $provider->getId(),
                'auth_available' => false,
                'online' => false,
                'biz_status' => null,
                'biz_status_label' => 'Indefinido',
                'sub_biz_status' => null,
                'sub_biz_status_label' => 'Indefinido',
            ],
            'store' => null,
            'delivery_areas' => null,
            'menu' => [
                'remote_item_ids' => [],
            ],
            'products' => array_merge($products, [
                'published_product_count' => 0,
                'products' => $this->mapProductsWithRemoteCatalog($products['products'] ?? [], []),
            ]),
            'errors' => [],
        ];

        if (!$connected) {
            return new JsonResponse($detail);
        }

        try {
            $authToken = $this->food99Service->resolveIntegrationAccessToken($provider);
            $detail['integration']['auth_available'] = !empty($authToken);

            if (!$authToken) {
                $detail['errors']['auth'] = 'Nao foi possivel obter o auth_token da loja na 99Food.';
                return new JsonResponse($detail);
            }
        } catch (\Throwable $e) {
            self::$logger->error('Food99 integration token error', [
                'provider_id' => $provider->getId(),
                'error' => $e->getMessage(),
            ]);
            $detail['errors']['auth'] = $e->getMessage();

            return new JsonResponse($detail);
        }

        try {
            $storeDetails = $this->food99Service->getStoreDetails($provider);
            $detail['store'] = $storeDetails;

            $remoteConnected = is_array($storeDetails) && (($storeDetails['errno'] ?? 1) === 0);
            $detail['integration']['remote_connected'] = $remoteConnected;

            $remoteStore = is_array($storeDetails['data'] ?? null) ? $storeDetails['data'] : null;
            $bizStatus = isset($remoteStore['biz_status']) ? (int) $remoteStore['biz_status'] : null;
            $subBizStatus = isset($remoteStore['sub_biz_status']) ? (int) $remoteStore['sub_biz_status'] : null;

            $detail['integration']['online'] = $remoteConnected && $bizStatus === 1;
            $detail['integration']['biz_status'] = $bizStatus;
            $detail['integration']['biz_status_label'] = $this->resolveBizStatusLabel($bizStatus);
            $detail['integration']['sub_biz_status'] = $subBizStatus;
            $detail['integration']['sub_biz_status_label'] = $this->resolveSubBizStatusLabel($subBizStatus);
        } catch (\Throwable $e) {
            self::$logger->error('Food99 integration store detail error', [
                'provider_id' => $provider->getId(),
                'error' => $e->getMessage(),
            ]);
            $detail['errors']['store'] = $e->getMessage();
        }

        try {
            $deliveryAreas = $this->food99Service->listDeliveryAreas($provider);
            $detail['delivery_areas'] = $deliveryAreas;
        } catch (\Throwable $e) {
            self::$logger->error('Food99 integration delivery area error', [
                'provider_id' => $provider->getId(),
                'error' => $e->getMessage(),
            ]);
            $detail['errors']['delivery_areas'] = $e->getMessage();
        }

        try {
            $menuDetails = $this->food99Service->getStoreMenuDetails($provider);
            $remoteItemIds = $this->resolvePublishedRemoteItemIds($menuDetails);
            $mappedProducts = $this->mapProductsWithRemoteCatalog($products['products'] ?? [], $remoteItemIds);

            $detail['menu'] = array_merge(is_array($menuDetails) ? $menuDetails : [], [
                'remote_item_ids' => $remoteItemIds,
            ]);
            $detail['products'] = array_merge($products, [
                'products' => $mappedProducts,
                'published_product_count' => count(array_filter(
                    $mappedProducts,
                    static fn(array $product) => !empty($product['published_remotely'])
                )),
            ]);
        } catch (\Throwable $e) {
            self::$logger->error('Food99 integration menu detail error', [
                'provider_id' => $provider->getId(),
                'error' => $e->getMessage(),
            ]);
            $detail['errors']['menu'] = $e->getMessage();
        }

        return new JsonResponse($detail);
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
