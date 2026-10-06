<?php
namespace ControleOnline\Integration\Tests\Service;

use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Device;
use ControleOnline\Entity\Status;
use ControleOnline\Service\StatusService;
use ControleOnline\Service\IntegrationBatchDispatcher;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class IntegrationBatchDispatcherTest extends TestCase
{
    private function fixture(bool $fail = false): array
    {
        $rows = array_map(fn () => new Integration(), range(1, 34));
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects($fail ? self::never() : self::once())->method('commit');
        $connection->expects($fail ? self::once() : self::never())->method('rollBack');
        $connection->method('isTransactionActive')->willReturn(true);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $persisted = new \ArrayObject();
        $manager->expects(self::exactly(34))->method('persist')->willReturnCallback(function ($row) use ($persisted) { $persisted[] = $row; });
        $manager->expects(self::once())->method('flush')->willReturnCallback(function () use ($persisted) {
            $id = new \ReflectionProperty(Integration::class, 'id');
            foreach ($persisted as $i => $row) $id->setValue($row, $i + 1);
        });
        $bus = $this->createMock(MessageBusInterface::class);
        $messages = new \ArrayObject();
        $bus->expects(self::exactly($fail ? 2 : 34))->method('dispatch')->willReturnCallback(function ($message) use (&$messages, $fail) {
            $messages[] = $message->integrationId;
            if ($fail && count($messages) === 2) throw new \RuntimeException('transport unavailable');
            return new Envelope($message);
        });
        return [new IntegrationBatchDispatcher($manager, $bus), $rows, $messages];
    }

    public function testAllDestinationsCommitInOneFlushWithoutDroppingMessages(): void
    {
        [$dispatcher, $rows, $messages] = $this->fixture();
        $dispatcher->dispatch($rows);
        self::assertSame(range(1, 34), $messages->getArrayCopy());
    }

    public function testDispatchFailureRollsBackTheEntireBatch(): void
    {
        [$dispatcher, $rows] = $this->fixture(true);
        $this->expectExceptionMessage('transport unavailable');
        $dispatcher->dispatch($rows);
    }

    public function testDeviceBatchPreservesEveryDestinationStatusAndPayload(): void
    {
        [$dispatcher] = $this->fixture();
        $devices = array_map(fn ($i) => (new Device())->setDevice('device-' . $i), range(1, 34));
        $status = new Status();
        $statuses = $this->createMock(StatusService::class);
        $statuses->expects(self::once())->method('discoveryStatus')->with('open', 'open', 'integration')->willReturn($status);
        $rows = $dispatcher->queueDevices('{"event":"order.updated"}', 'Websocket', $devices, $statuses);
        self::assertCount(34, $rows);
        foreach ($rows as $i => $row) {
            self::assertSame($devices[$i], $row->getDevice());
            self::assertSame($status, $row->getStatus());
            self::assertSame('Websocket', $row->getQueueName());
            self::assertSame('{"event":"order.updated"}', $row->getBody());
        }
    }

    public function testEmptyBatchDoesNotStartATransaction(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::never())->method('getConnection');
        (new IntegrationBatchDispatcher($manager, $this->createStub(MessageBusInterface::class)))->dispatch([]);
    }
}
