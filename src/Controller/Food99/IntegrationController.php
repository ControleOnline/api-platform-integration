<?php

namespace ControleOnline\Controller\Food99;

use ControleOnline\Entity\Order;
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

    private function resolveOrder(string|int $orderId): ?Order
    {
        $normalizedOrderId = (int) preg_replace('/\D+/', '', (string) $orderId);
        if ($normalizedOrderId <= 0) {
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

        $userPeople = $this->getAuthenticatedPeople();
        if (!$userPeople && !$this->isAdminUser()) {
            return null;
        }

        if ($userPeople && !$this->canAccessProvider($userPeople, $provider)) {
            return null;
        }

        return $order;
    }

    private function orderErrorResponse(string $message = 'Order not found or access denied'): JsonResponse
    {
        return new JsonResponse([
            'error' => $message,
        ], Response::HTTP_FORBIDDEN);
    }

    private function isFood99Order(Order $order): bool
    {
        return strcasecmp((string) $order->getApp(), 'Food99') === 0;
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

    private function buildLocalIntegrationDetail(People $provider): array
    {
        $products = $this->food99Service->listSelectableMenuProducts($provider);
        $storedState = $this->food99Service->getStoredIntegrationState($provider);
        $mappedProducts = array_map(static function (array $product) {
            $product['published_remotely'] = !empty($product['food99_published']);

            return $product;
        }, $products['products'] ?? []);
        $publishedProductCount = count(array_filter(
            $mappedProducts,
            static fn(array $product) => !empty($product['published_remotely'])
        ));

        return [
            'provider' => [
                'id' => $provider->getId(),
                'name' => method_exists($provider, 'getName') ? $provider->getName() : null,
            ],
            'integration' => [
                'key' => '99food',
                'label' => '99Food',
                'minimum_required_items' => 5,
                'eligible_product_count' => $products['eligible_product_count'] ?? 0,
                'connected' => $storedState['connected'],
                'remote_connected' => $storedState['remote_connected'],
                'food99_code' => $storedState['food99_code'],
                'app_shop_id' => $storedState['app_shop_id'],
                'auth_available' => null,
                'online' => $storedState['online'],
                'biz_status' => $storedState['biz_status'],
                'biz_status_label' => $this->resolveBizStatusLabel($storedState['biz_status']),
                'sub_biz_status' => $storedState['sub_biz_status'],
                'sub_biz_status_label' => $this->resolveSubBizStatusLabel($storedState['sub_biz_status']),
                'store_status' => $storedState['store_status'],
                'last_sync_at' => $storedState['last_sync_at'],
                'last_error_code' => $storedState['last_error_code'],
                'last_error_message' => $storedState['last_error_message'],
                'last_menu_task_status' => $storedState['last_menu_task_status'],
                'last_menu_task_message' => $storedState['last_menu_task_message'],
                'last_menu_task_checked_at' => $storedState['last_menu_task_checked_at'],
                'last_menu_publish_state' => $storedState['last_menu_publish_state'],
                'menu_count' => $storedState['menu_count'],
                'menu_item_count' => $storedState['menu_item_count'],
                'delivery_area_count' => $storedState['delivery_area_count'],
                'remote_only_item_count' => $storedState['remote_only_item_count'],
                'published_product_count' => $publishedProductCount,
                'last_menu_task_id' => $storedState['last_menu_task_id'],
            ],
            'store' => null,
            'delivery_areas' => null,
            'menu' => [
                'remote_item_ids' => [],
            ],
            'products' => array_merge($products, [
                'products' => $mappedProducts,
                'published_product_count' => $publishedProductCount,
            ]),
            'errors' => [],
        ];
    }

    private function buildOrderIntegrationDetail(Order $order): array
    {
        $storedState = $this->food99Service->getStoredOrderIntegrationState($order);

        return [
            'order' => [
                'id' => $order->getId(),
                'app' => $order->getApp(),
                'status' => [
                    'id' => $order->getStatus()?->getId(),
                    'status' => $order->getStatus()?->getStatus(),
                    'real_status' => $order->getStatus()?->getRealStatus(),
                ],
            ],
            'integration' => [
                'key' => '99food',
                'food99_id' => $storedState['food99_id'],
                'food99_code' => $storedState['food99_code'],
                'remote_order_state' => $storedState['remote_order_state'],
                'remote_delivery_status' => $storedState['remote_delivery_status'],
                'last_event_type' => $storedState['last_event_type'],
                'last_event_at' => $storedState['last_event_at'],
                'cancel_code' => $storedState['cancel_code'],
                'cancel_reason' => $storedState['cancel_reason'],
                'last_action' => $storedState['last_action'],
                'last_action_at' => $storedState['last_action_at'],
                'last_action_errno' => $storedState['last_action_errno'],
                'last_action_message' => $storedState['last_action_message'],
                'confirm_at' => $storedState['confirm_at'],
                'confirm_errno' => $storedState['confirm_errno'],
                'confirm_message' => $storedState['confirm_message'],
            ],
            'delivery' => [
                'delivery_type' => $storedState['delivery_type'],
                'delivery_label' => $storedState['delivery_label'],
                'fulfillment_mode' => $storedState['fulfillment_mode'],
                'expected_arrived_eta' => $storedState['expected_arrived_eta'],
                'remote_delivery_status' => $storedState['remote_delivery_status'],
                'handover_code' => $storedState['handover_code'],
                'locator' => $storedState['locator'],
                'handover_page_url' => $storedState['handover_page_url'],
                'virtual_phone_number' => $storedState['virtual_phone_number'],
                'is_store_delivery' => $storedState['is_store_delivery'],
                'is_platform_delivery' => $storedState['is_platform_delivery'],
                'allows_manual_delivery_completion' => $storedState['allows_manual_delivery_completion'],
            ],
        ];
    }

    #[Route('/marketplace/integrations', name: 'marketplace_integrations', methods: ['GET'])]
    public function listIntegrations(Request $request): JsonResponse
    {
        $provider = $this->resolveProvider($request);
        if (!$provider) {
            return $this->providerErrorResponse();
        }

        $products = $this->food99Service->listSelectableMenuProducts($provider);
        $storedState = $this->food99Service->getStoredIntegrationState($provider);
        $publishedProductCount = count(array_filter(
            $products['products'] ?? [],
            static fn(array $product) => !empty($product['food99_published'])
        ));

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
                'connected' => $storedState['connected'],
                'remote_connected' => $storedState['remote_connected'],
                'food99_code' => $storedState['food99_code'],
                'app_shop_id' => $storedState['app_shop_id'],
                'biz_status' => $storedState['biz_status'],
                'sub_biz_status' => $storedState['sub_biz_status'],
                'store_status' => $storedState['store_status'],
                'online' => $storedState['online'],
                'last_sync_at' => $storedState['last_sync_at'],
                'last_menu_task_status' => $storedState['last_menu_task_status'],
                'last_menu_task_message' => $storedState['last_menu_task_message'],
                'last_menu_task_checked_at' => $storedState['last_menu_task_checked_at'],
                'last_menu_publish_state' => $storedState['last_menu_publish_state'],
                'published_product_count' => $publishedProductCount,
                'remote_only_item_count' => $storedState['remote_only_item_count'],
                'last_error_code' => $storedState['last_error_code'],
                'last_error_message' => $storedState['last_error_message'],
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

        return new JsonResponse($this->buildLocalIntegrationDetail($provider));
    }

    #[Route('/marketplace/integrations/99food/sync', name: 'marketplace_integrations_food99_sync', methods: ['POST'])]
    public function syncIntegration(Request $request): JsonResponse
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

        $syncResult = $this->food99Service->syncIntegrationState($provider);
        $detail = $this->buildLocalIntegrationDetail($provider);
        $detail['integration']['auth_available'] = $syncResult['auth_available'];
        $detail['errors'] = $syncResult['errors'] ?? [];

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

        try {
            $productIds = $this->extractProductIds($payload);
            $preview = $this->food99Service->buildStoreMenuPayloadFromProducts($provider, $productIds);
            if (!empty($preview['errors'])) {
                return new JsonResponse($preview, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $this->food99Service->ensureMenuProductCodes($provider, $productIds);
            $result = $this->food99Service->uploadStoreMenu($provider, $preview['payload']);
            $detail = $this->buildLocalIntegrationDetail($provider);

            return new JsonResponse([
                'provider_id' => $provider->getId(),
                'selected_product_count' => $preview['selected_product_count'] ?? 0,
                'eligible_product_count' => $preview['eligible_product_count'] ?? 0,
                'result' => $result,
                'payload' => $preview['payload'],
                'integration' => $detail['integration'],
                'products' => $detail['products'],
            ]);
        } catch (\Throwable $e) {
            self::$logger->error('Food99 menu upload endpoint error', [
                'provider_id' => $provider->getId(),
                'product_ids' => $this->extractProductIds($payload),
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'provider_id' => $provider->getId(),
                'selected_product_count' => 0,
                'eligible_product_count' => 0,
                'result' => [
                    'errno' => 10001,
                    'errmsg' => 'Nao foi possivel publicar o menu no 99Food.',
                    'data' => [],
                ],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/marketplace/integrations/99food/menu/task/{taskId}', name: 'marketplace_integrations_food99_menu_task', methods: ['GET'])]
    public function getMenuTask(Request $request, string $taskId): JsonResponse
    {
        $provider = $this->resolveProvider($request);
        if (!$provider) {
            return $this->providerErrorResponse();
        }

        $taskResponse = $this->food99Service->getMenuUploadTaskInfo($provider, $taskId);
        $detail = $this->buildLocalIntegrationDetail($provider);

        return new JsonResponse(array_merge(
            is_array($taskResponse) ? $taskResponse : [],
            [
                'publish_state' => $detail['integration']['last_menu_publish_state'],
                'task_message' => $detail['integration']['last_menu_task_message'],
                'task_status' => $detail['integration']['last_menu_task_status'],
                'task_checked_at' => $detail['integration']['last_menu_task_checked_at'],
                'integration' => $detail['integration'],
                'products' => $detail['products'],
            ]
        ));
    }

    #[Route('/marketplace/integrations/99food/orders/{orderId}/state', name: 'marketplace_integrations_food99_order_state', methods: ['GET'])]
    public function getOrderState(string $orderId): JsonResponse
    {
        $order = $this->resolveOrder($orderId);
        if (!$order) {
            return $this->orderErrorResponse();
        }

        if (!$this->isFood99Order($order)) {
            return new JsonResponse([
                'error' => 'Order is not linked to Food99',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse($this->buildOrderIntegrationDetail($order));
    }

    #[Route('/marketplace/integrations/99food/orders/{orderId}/ready', name: 'marketplace_integrations_food99_order_ready', methods: ['POST'])]
    public function readyOrderAction(string $orderId): JsonResponse
    {
        $order = $this->resolveOrder($orderId);
        if (!$order) {
            return $this->orderErrorResponse();
        }

        if (!$this->isFood99Order($order)) {
            return new JsonResponse(['error' => 'Order is not linked to Food99'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $this->food99Service->performReadyAction($order);

        return new JsonResponse([
            'action' => 'ready',
            'result' => $result,
            'state' => $this->buildOrderIntegrationDetail($order),
        ]);
    }

    #[Route('/marketplace/integrations/99food/orders/{orderId}/cancel', name: 'marketplace_integrations_food99_order_cancel', methods: ['POST'])]
    public function cancelOrderAction(string $orderId): JsonResponse
    {
        $order = $this->resolveOrder($orderId);
        if (!$order) {
            return $this->orderErrorResponse();
        }

        if (!$this->isFood99Order($order)) {
            return new JsonResponse(['error' => 'Order is not linked to Food99'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $this->food99Service->performCancelAction($order);

        return new JsonResponse([
            'action' => 'cancel',
            'result' => $result,
            'state' => $this->buildOrderIntegrationDetail($order),
        ]);
    }

    #[Route('/marketplace/integrations/99food/orders/{orderId}/delivered', name: 'marketplace_integrations_food99_order_delivered', methods: ['POST'])]
    public function deliveredOrderAction(string $orderId): JsonResponse
    {
        $order = $this->resolveOrder($orderId);
        if (!$order) {
            return $this->orderErrorResponse();
        }

        if (!$this->isFood99Order($order)) {
            return new JsonResponse(['error' => 'Order is not linked to Food99'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $this->food99Service->performDeliveredAction($order);

        return new JsonResponse([
            'action' => 'delivered',
            'result' => $result,
            'state' => $this->buildOrderIntegrationDetail($order),
        ]);
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
