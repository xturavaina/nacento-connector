<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Queue;

use Magento\AsynchronousOperations\Api\Data\OperationInterface as AsyncOperationInterface;
use Magento\Framework\Bulk\OperationInterface as BulkOperationInterface;
use Magento\Framework\Bulk\OperationManagementInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Nacento\Connector\Model\ResourceModel\Product\GalleryBulkAsync;
use Psr\Log\LoggerInterface;

/**
 * Gallery consumer (Granular) with error management for "Replace per SKU" strategy.
 *
 * Key Points:
 * - Processes one SKU per message.
 * - Uses the operation_key from the payload to update status.
 * - Works with the data from the OperationInterface provided by the message queue.
 */
class GalleryConsumerBulkAsync
{
    public function __construct(
        private readonly GalleryBulkAsync $galleryProcessor,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger,
        private readonly OperationManagementInterface $operationManagement,
    ) {}

    /**
     * Processes a single SKU operation received from the message queue.
     */
    public function process(AsyncOperationInterface $operation): void
    {
        $bulkUuid = (string)$operation->getBulkUuid();
        $operationKey = $operation->getId();

        // Unserialize the payload to get your application-specific data.
        $payload = $this->serializer->unserialize($operation->getSerializedData());
        $sku = $payload['sku'] ?? null;

        $this->logger->info('[NacentoGalleryConsumer][BulkAsync][ENTER]', [
            'bulk_uuid' => $bulkUuid,
            'operation_key' => $operationKey, // Ara tindrà un valor enter (0, 1, 2...)
            'sku' => $sku,
        ]);

        // Validate using the operation key from payload
        if ($bulkUuid === '' || $operationKey === null) {
            $this->logger->critical('[NacentoGalleryConsumer][BulkAsync][FATAL] Received an invalid operation without a valid key.', [
                'operation_id' => $operation->getId(),
                'bulk_uuid' => $bulkUuid,
            ]);
            return;
        }

        try {
            if ($sku === null) {
                throw new \InvalidArgumentException('SKU is missing from the operation payload.');
            }
            $result = $this->galleryProcessor->processSku($sku, $payload['images'], $payload['store_id']);
            $this->handleSuccessfulCompletion($bulkUuid, $operationKey, $sku, $result);
        } catch (\Throwable $e) {
            $this->handleProcessingError($bulkUuid, $operationKey, $e);
    }
}

    private function handleSuccessfulCompletion(string $bulkUuid, int $operationKey, string $sku, array $result): void
    {
        $message = sprintf('Successfully processed gallery for SKU: %s.', $sku);
        $this->logger->info('[NacentoGalleryConsumer][BulkAsync][SUCCESS]', [
            'bulk_uuid' => $bulkUuid,
            'operation_key' => $operationKey,
            'sku' => $sku,
        ]);
        $this->safeChangeStatus($bulkUuid, $operationKey, BulkOperationInterface::STATUS_TYPE_COMPLETE, null, $message, $this->serializer->serialize($result));
    }

    private function handleProcessingError(string $bulkUuid, int $operationKey, \Throwable $e): void
    {
        $this->logger->error('[NacentoGalleryConsumer][BulkAsync][ERROR]', [
            'bulk_uuid' => $bulkUuid,
            'operation_key' => $operationKey,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
        ]);

        $status = BulkOperationInterface::STATUS_TYPE_NOT_RETRIABLY_FAILED;
        $this->safeChangeStatus($bulkUuid, $operationKey, $status, $e->getCode(), $e->getMessage(), $e->getTraceAsString());
    }

    private function safeChangeStatus(string $bulkUuid, int $operationKey, int $status, ?int $errorCode, ?string $message, ?string $data): void
    {
        try {
            // $operationKey es un int
            $this->operationManagement->changeOperationStatus($bulkUuid, $operationKey, $status, $errorCode, $message, $data);
        } catch (\Throwable $statusException) {
            $this->logger->critical('[GalleryConsumer][STATUS_UPDATE_FAILED]', [
                'bulk_uuid' => $bulkUuid,
                'operation_key' => $operationKey,
                'error' => $statusException->getMessage(),
            ]);
        }
    }
}