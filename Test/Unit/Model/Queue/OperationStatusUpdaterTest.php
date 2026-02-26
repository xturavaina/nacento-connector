<?php
declare(strict_types=1);

namespace Nacento\Connector\Test\Unit\Model\Queue;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Nacento\Connector\Model\Queue\OperationStatusUpdater;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OperationStatusUpdaterTest extends TestCase
{
    public function testUpdatesByOperationKeyAndFallsBackToId(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::exactly(2))
            ->method('update')
            ->willReturnOnConsecutiveCalls(0, 1);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->with('magento_operation')->willReturn('magento_operation');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $updater = new OperationStatusUpdater($resource, $logger);

        self::assertTrue($updater->update('bulk-1', 10, 'op-key-1', 4, null, null, '{}'));
    }

    public function testReturnsFalseAndLogsWhenExceptionOccurs(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('update')->willThrowException(new \RuntimeException('db down'));

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturn('magento_operation');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('critical');

        $updater = new OperationStatusUpdater($resource, $logger);

        self::assertFalse($updater->update('bulk-1', 10, 'op-key-1', 4));
    }
}
