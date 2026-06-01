<?php

namespace ControleOnline\Integration\Tests\Service\Marketplace;

use ControleOnline\Entity\People;
use ControleOnline\Service\Client\Food99Client;
use ControleOnline\Service\DefaultFoodService;
use ControleOnline\Service\ExtraDataService;
use ControleOnline\Service\DomainService;
use ControleOnline\Service\Marketplace\Food99CatalogOperationsService;
use ControleOnline\Service\Marketplace\Food99StoreOperationsService;
use PHPUnit\Framework\TestCase;

final class Food99CatalogOperationsServiceTest extends TestCase
{
    public function testCatalogMenuTaskHelpersDelegateToStoreService(): void
    {
        $storeService = new class extends Food99StoreOperationsService {
            public array $calls = [];

            public function __construct()
            {
            }

            public function persistProviderLastError(People $provider, mixed $code = null, mixed $message = null): void
            {
                $this->calls[] = ['persistProviderLastError', $provider, $code, $message];
            }

            public function persistProviderMenuUploadSubmission(People $provider, array $response, mixed $taskId = null): void
            {
                $this->calls[] = ['persistProviderMenuUploadSubmission', $provider, $response, $taskId];
            }

            public function normalizeMenuTaskResponse(array $response, int|string|null $fallbackTaskId = null): array
            {
                $this->calls[] = ['normalizeMenuTaskResponse', $response, $fallbackTaskId];

                if (!is_array($response['data'] ?? null)) {
                    $response['data'] = [];
                }

                $response['data']['taskID'] = (string) ($response['data']['taskID'] ?? $fallbackTaskId);
                $response['data']['normalized'] = true;

                return $response;
            }

            public function persistProviderMenuTaskState(People $provider, array $response, int|string|null $fallbackTaskId = null): string
            {
                $this->calls[] = ['persistProviderMenuTaskState', $provider, $response, $fallbackTaskId];

                return 'failed';
            }
        };

        $service = (new \ReflectionClass(Food99CatalogOperationsService::class))->newInstanceWithoutConstructor();
        $this->setObjectProperty(Food99CatalogOperationsService::class, $service, 'food99StoreOperationsService', $storeService);

        $provider = new People();

        $normalizedResponse = $this->invokePrivateMethod(
            $service,
            'normalizeMenuTaskResponse',
            ['errno' => 0, 'data' => ['taskId' => 42]],
            123
        );

        self::assertSame('123', $normalizedResponse['data']['taskID']);
        self::assertTrue($normalizedResponse['data']['normalized']);

        $this->invokePrivateMethod(
            $service,
            'persistProviderLastError',
            $provider,
            'menu_task:2',
            'Task failed'
        );

        $this->invokePrivateMethod(
            $service,
            'persistProviderMenuUploadSubmission',
            $provider,
            ['errno' => 0, 'data' => ['status' => 1]],
            777
        );

        $taskState = $this->invokePrivateMethod(
            $service,
            'persistProviderMenuTaskState',
            $provider,
            ['errno' => 0, 'data' => ['status' => 2]],
            888
        );

        self::assertSame('failed', $taskState);
        self::assertSame(
            [
                'normalizeMenuTaskResponse',
                'persistProviderLastError',
                'persistProviderMenuUploadSubmission',
                'persistProviderMenuTaskState',
            ],
            array_map(static fn(array $call): string => $call[0], $storeService->calls)
        );
    }

    public function testSyncIntegrationStateBroadcastsClosedNotificationOnFirstKnownClosedState(): void
    {
        $service = (new \ReflectionClass(Food99CatalogOperationsServiceProbe::class))->newInstanceWithoutConstructor();

        $provider = new People();
        $this->setEntityId($provider, 3);
        $provider->setName('Mercado Central');

        $storeService = $this->createMock(Food99StoreOperationsService::class);
        $storeService
            ->expects(self::once())
            ->method('getStoredIntegrationState')
            ->with($provider)
            ->willReturn([
                'biz_status' => 1,
                'sub_biz_status' => 1,
                'store_status' => 1,
                'online' => 1,
            ]);
        $storeService
            ->expects(self::once())
            ->method('persistProviderLastError')
            ->with($provider, '', '');
        $storeService
            ->method('getStoreDetails')
            ->with($provider)
            ->willReturn([
                'errno' => 0,
                'data' => [
                    'shop_id' => 'shop-123',
                    'name' => 'Mercado Central',
                    'biz_status' => 0,
                    'sub_biz_status' => 2,
                    'store_status' => 0,
                ],
            ]);
        $storeService
            ->method('listDeliveryAreas')
            ->with($provider)
            ->willReturn([
                'errno' => 0,
                'data' => [
                    'area_group' => [],
                ],
            ]);
        $storeService
            ->method('getStoreMenuDetails')
            ->with($provider)
            ->willReturn([
                'errno' => 0,
                'data' => [
                    'menus' => [],
                    'items' => [],
                ],
            ]);

        $food99Client = $this->createMock(Food99Client::class);
        $food99Client
            ->method('resolveIntegrationAccessToken')
            ->with($provider)
            ->willReturn('token');

        $this->setObjectProperty(DefaultFoodService::class, $service, 'food99Client', $food99Client);
        $this->setObjectProperty(Food99CatalogOperationsService::class, $service, 'food99StoreOperationsService', $storeService);

        $result = $service->syncIntegrationState($provider, false);

        self::assertSame(0, $result['store']['errno']);
        self::assertCount(1, $service->capturedEvents);
        self::assertSame('store.closed', $service->capturedEvents[0][1][0]['event']);
        self::assertSame('MERCADO CENTRAL foi fechada', $service->capturedEvents[0][1][0]['notificationHeader']);
        self::assertSame('Fechada', $service->capturedEvents[0][1][0]['notificationStatusLabel']);
    }

    public function testBuildMenuProductChildrenByParentGroupsModifierRows(): void
    {
        $service = (new \ReflectionClass(Food99CatalogOperationsService::class))->newInstanceWithoutConstructor();
        $this->setObjectProperty(Food99CatalogOperationsService::class, $service, 'domainService', $this->createDomainServiceStub());

        $modifierRows = [
            [
                'parent_product_id' => 10,
                'product_group_id' => 100,
                'product_group_name' => 'Tamanhos',
                'group_required' => 1,
                'group_minimum' => 1,
                'group_maximum' => 2,
                'group_order' => 3,
                'child_product_id' => 501,
                'child_product_name' => 'Pequeno',
                'child_description' => 'Menor porcao',
                'child_relation_price' => '2.50',
                'child_food99_code' => 'F99-501',
                'child_cover_file_id' => 77,
            ],
            [
                'parent_product_id' => 10,
                'product_group_id' => 100,
                'product_group_name' => 'Tamanhos',
                'group_required' => 1,
                'group_minimum' => 1,
                'group_maximum' => 2,
                'group_order' => 3,
                'child_product_id' => 502,
                'child_product_name' => 'Grande',
                'child_base_price' => '3.25',
                'child_food99_code' => '',
            ],
            [
                'parent_product_id' => 10,
                'product_group_id' => 200,
                'product_group_name' => 'Extras',
                'group_required' => 0,
                'group_minimum' => 0,
                'group_maximum' => 0,
                'group_order' => 5,
                'child_product_id' => 601,
                'child_product_name' => 'Bacon',
                'child_relation_price' => '1.5',
            ],
            [
                'parent_product_id' => 20,
                'product_group_id' => 300,
                'product_group_name' => 'Molhos',
                'group_required' => 0,
                'group_minimum' => 0,
                'group_maximum' => 1,
                'group_order' => 1,
                'child_product_id' => 701,
                'child_product_name' => 'Maionese',
                'child_relation_price' => '0.0',
            ],
        ];

        $childrenByParent = $this->invokePrivateMethod(
            $service,
            'groupMenuProductChildrenByParent',
            $modifierRows
        );

        self::assertArrayHasKey(10, $childrenByParent);
        self::assertArrayHasKey(20, $childrenByParent);
        self::assertCount(2, $childrenByParent[10]);
        self::assertCount(1, $childrenByParent[20]);
        self::assertSame('Tamanhos', $childrenByParent[10][0]['name']);
        self::assertTrue($childrenByParent[10][0]['required']);
        self::assertSame(1, $childrenByParent[10][0]['minimum']);
        self::assertSame(2, $childrenByParent[10][0]['maximum']);
        self::assertCount(2, $childrenByParent[10][0]['items']);
        self::assertSame('Pequeno', $childrenByParent[10][0]['items'][0]['name']);
        self::assertSame('F99-501', $childrenByParent[10][0]['items'][0]['code']);
        self::assertSame(2.5, $childrenByParent[10][0]['items'][0]['price']);
        self::assertSame('502', $childrenByParent[10][0]['items'][1]['code']);
        self::assertSame(3.25, $childrenByParent[10][0]['items'][1]['price']);
        self::assertSame('Extras', $childrenByParent[10][1]['name']);
        self::assertFalse($childrenByParent[10][1]['required']);
    }

    public function testBuildMenuProductViewIncludesChildrenMetadata(): void
    {
        $service = (new \ReflectionClass(Food99CatalogOperationsService::class))->newInstanceWithoutConstructor();
        $this->setObjectProperty(Food99CatalogOperationsService::class, $service, 'domainService', $this->createDomainServiceStub());
        $this->setObjectProperty(Food99CatalogOperationsService::class, $service, 'extraDataService', $this->createExtraDataServiceStub());

        $children = [
            [
                'id' => 100,
                'name' => 'Tamanhos',
                'required' => true,
                'minimum' => 1,
                'maximum' => 2,
                'items' => [
                    [
                        'id' => 501,
                        'name' => 'Pequeno',
                        'description' => 'Menor porcao',
                        'price' => 2.5,
                        'code' => 'F99-501',
                        'image_url' => 'https://example.com/file-77',
                    ],
                ],
            ],
        ];

        $productView = $this->invokePrivateMethod(
            $service,
            'buildMenuProductView',
            [
                'id' => 10,
                'product_name' => 'Burger',
                'description' => 'Lanche especial',
                'price' => 29.9,
                'type' => 'custom',
                'category_id' => 4,
                'category_name' => 'Lanches',
                'food99_code' => '99-10',
                'food99_published' => '0',
                'has_required_modifiers' => '1',
                'cover_file_id' => 15,
            ],
            $children
        );

        self::assertSame(10, $productView['id']);
        self::assertTrue($productView['has_children']);
        self::assertSame($children, $productView['children']);
        self::assertSame('Burger', $productView['name']);
        self::assertSame('Lanches', $productView['category']['name']);
        self::assertSame('not_synced', $productView['sync']['status']);
    }

    private function invokePrivateMethod(object $object, string $methodName, mixed ...$arguments): mixed
    {
        $method = new \ReflectionMethod($object, $methodName);
        $method->setAccessible(true);

        return $method->invoke($object, ...$arguments);
    }

    private function setEntityId(object $object, int $id): void
    {
        $reflection = new \ReflectionObject($object);
        while ($reflection) {
            if ($reflection->hasProperty('id')) {
                $property = $reflection->getProperty('id');
                $property->setAccessible(true);
                $property->setValue($object, $id);
                return;
            }

            $reflection = $reflection->getParentClass();
        }

        throw new \RuntimeException('Unable to set entity id in test.');
    }

    private function setObjectProperty(string $className, object $object, string $propertyName, mixed $value): void
    {
        $property = new \ReflectionProperty($className, $propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    private function createExtraDataServiceStub(): ExtraDataService
    {
        return new class extends ExtraDataService {
            public function __construct()
            {
            }

            public function getExtraDataValue(
                string $context,
                string $entityName,
                int $entityId,
                string $fieldName = 'code',
                string $fieldType = 'text'
            ): ?string {
                return null;
            }
        };
    }

    private function createDomainServiceStub(): DomainService
    {
        return new class extends DomainService {
            public function __construct()
            {
            }

            public function getMainDomain()
            {
                return 'example.com';
            }
        };
    }
}

final class Food99CatalogOperationsServiceProbe extends Food99CatalogOperationsService
{
    public array $capturedEvents = [];

    protected function broadcastCompanyWebsocketEvents(People $company, array $events): void
    {
        $this->capturedEvents[] = [$company, $events];
    }

    protected function sendStoreClosingNotifications(
        People $company,
        string $app,
        ?\DateTime $referenceDate = null
    ): array {
        return [
            'daily_sales_amount' => 123.45,
            'weekly_settlement_amount' => 678.90,
        ];
    }
}
