<?php

namespace ControleOnline\Integration\Tests\Service\Marketplace;

use ControleOnline\Entity\People;
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

    private function invokePrivateMethod(object $object, string $methodName, mixed ...$arguments): mixed
    {
        $method = new \ReflectionMethod($object, $methodName);
        $method->setAccessible(true);

        return $method->invoke($object, ...$arguments);
    }

    private function setObjectProperty(string $className, object $object, string $propertyName, mixed $value): void
    {
        $property = new \ReflectionProperty($className, $propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }
}
