<?php
declare(strict_types=1);

namespace Nacento\Connector\Test\Unit\Model;

use Magento\AsynchronousOperations\Api\Data\AsyncResponseInterface;
use Magento\AsynchronousOperations\Api\Data\AsyncResponseInterfaceFactory;
use Magento\AsynchronousOperations\Api\Data\ItemStatusInterface;
use Magento\AsynchronousOperations\Api\Data\ItemStatusInterfaceFactory;
use Magento\AsynchronousOperations\Model\OperationFactory;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Bulk\BulkManagementInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Nacento\Connector\Model\Bulk\ImagePayloadNormalizer;
use Nacento\Connector\Model\Bulk\OperationKeyFactory;
use Nacento\Connector\Model\Bulk\RequestValidator;
use Nacento\Connector\Model\BulkGalleryAsyncManagement;
use Nacento\Connector\Model\Data\BulkItem;
use Nacento\Connector\Model\Data\BulkRequest;
use Nacento\Connector\Model\Data\ImageEntry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BulkGalleryAsyncManagementTest extends TestCase
{
    public function testRejectsInvalidItemsAndDedupesValidSkuForScheduling(): void
    {
        $bulkManagement = $this->createMock(BulkManagementInterface::class);
        $opFactory = $this->createMock(OperationFactory::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $userContext = $this->createMock(UserContextInterface::class);
        $responseFactory = $this->createMock(AsyncResponseInterfaceFactory::class);
        $itemStatusFactory = $this->createMock(ItemStatusInterfaceFactory::class);
        $logger = $this->createMock(LoggerInterface::class);

        $userContext->method('getUserId')->willReturn(5);

        $capturedCreates = [];
        $opFactory->method('create')->willReturnCallback(function (array $data) use (&$capturedCreates) {
            $capturedCreates[] = $data;
            return new \stdClass();
        });

        $serializer->method('serialize')->willReturnCallback(static function (array $payload): string {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        });

        $bulkManagement->expects(self::once())
            ->method('scheduleBulk')
            ->with(
                self::isType('string'),
                self::callback(static fn(array $operations): bool => count($operations) === 1),
                'Nacento gallery bulk',
                5
            );

        $response = $this->createMock(AsyncResponseInterface::class);
        $response->method('setBulkUuid')->willReturnSelf();
        $response->method('setRequestItems')->willReturnSelf();
        $response->expects(self::once())->method('setErrors')->with(true)->willReturnSelf();
        $responseFactory->method('create')->willReturn($response);

        $itemStatusFactory->method('create')->willReturnCallback(function () {
            $status = $this->createMock(ItemStatusInterface::class);
            $status->method('setId')->willReturnSelf();
            $status->method('setDataHash')->willReturnSelf();
            $status->method('setStatus')->willReturnSelf();
            $status->method('setErrorMessage')->willReturnSelf();
            return $status;
        });

        $service = new BulkGalleryAsyncManagement(
            $bulkManagement,
            $opFactory,
            $serializer,
            $userContext,
            $responseFactory,
            $itemStatusFactory,
            $logger,
            new ImagePayloadNormalizer($logger),
            new RequestValidator(),
            new OperationKeyFactory()
        );

        $img = new ImageEntry(['data' => [
            'file_path' => '/a/b.jpg',
            'label' => '',
            'roles' => ['base'],
            'position' => 1,
            'disabled' => false,
            'ignored' => 'x',
        ]]);
        $valid1 = new BulkItem(['data' => ['sku' => 'SKU-1', 'images' => [$img]]]);
        $valid2 = new BulkItem(['data' => ['sku' => 'SKU-1', 'images' => [[
            'file_path' => '/c/d.jpg',
            'label' => 'second',
            'roles' => ['thumbnail'],
        ]]]]);
        $invalid = new BulkItem(['data' => ['sku' => '   ', 'images' => []]]);
        $request = new BulkRequest(['data' => [
            'request_id' => 'req-1',
            'items' => [$valid1, $invalid, $valid2],
        ]]);

        $service->submit($request);

        self::assertCount(1, $capturedCreates);
        $operationData = $capturedCreates[0]['data'];
        self::assertArrayHasKey('operation_key', $operationData);

        $payload = json_decode((string)$operationData['serialized_data'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('SKU-1', $payload['sku']);
        self::assertSame('req-1', $payload['request_id']);
        self::assertSame('/c/d.jpg', $payload['images'][0]['file_path']);
        self::assertSame(['thumbnail'], $payload['images'][0]['roles']);
        self::assertArrayNotHasKey('ignored', $payload['images'][0]);
    }
}
