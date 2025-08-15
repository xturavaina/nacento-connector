<?php
declare(strict_types=1);
namespace Nacento\Connector\Model;

use Magento\Framework\Bulk\BulkManagementInterface;
use Magento\Framework\Bulk\OperationInterfaceFactory;
use Magento\Framework\Bulk\OperationInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Bulk\BulkUuidGeneratorInterface;
use Magento\Authorization\Model\UserContextInterface;
use Nacento\Connector\Api\BulkGalleryAsyncManagementInterface;
use Nacento\Connector\Api\Data\BulkRequestInterface;
use Nacento\Connector\Api\Data\ProcessGalleryMessageInterfaceFactory;

class BulkGalleryAsyncManagement implements BulkGalleryAsyncManagementInterface
{
    public function __construct(
        private readonly BulkManagementInterface $bulkManagement,
        private readonly OperationInterfaceFactory $operationFactory,
        private readonly SerializerInterface $serializer,
        private readonly BulkUuidGeneratorInterface $uuidGenerator,
        private readonly UserContextInterface $userContext,
        private readonly ProcessGalleryMessageInterfaceFactory $msgFactory
    ) {}

    public function submit(BulkRequestInterface $request)
    {
        $bulkUuid = $this->uuidGenerator->generate();
        $userId   = (int)$this->userContext->getUserId();
        $desc     = 'Nacento gallery bulk';

        // de-dupe last-wins
        $map = [];
        foreach ($request->getItems() ?? [] as $it) {
            $sku = (string)$it->getSku();
            if ($sku === '') continue;
            $map[$sku] = $it;
        }
        $unique = array_values($map);

        $ops = [];
        foreach ($unique as $it) {
            $msg = $this->msgFactory->create([
                'data' => [
                    'sku'    => (string)$it->getSku(),
                    'images' => $it->getImages(),
                ]
            ]);
            $ops[] = $this->operationFactory->create([
                'data' => [
                    OperationInterface::BULK_ID         => $bulkUuid,
                    OperationInterface::TOPIC_NAME      => 'nacento.gallery.process',
                    OperationInterface::SERIALIZED_DATA => $this->serializer->serialize($msg),
                    OperationInterface::STATUS          => OperationInterface::STATUS_TYPE_OPEN,
                ]
            ]);
        }

        $this->bulkManagement->scheduleBulk($bulkUuid, $ops, $desc, $userId);

        return ['bulk_uuid' => $bulkUuid, 'scheduled' => count($ops), 'request_id' => $request->getRequestId()];
    }
}
