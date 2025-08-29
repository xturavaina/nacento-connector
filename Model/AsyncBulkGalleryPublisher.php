<?php
declare(strict_types=1);

namespace Nacento\Connector\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Bulk\BulkManagementInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\DataObject;
use Psr\Log\LoggerInterface;

use Magento\AsynchronousOperations\Model\OperationFactory;
use Magento\AsynchronousOperations\Api\Data\AsyncResponseInterface;
use Magento\AsynchronousOperations\Api\Data\AsyncResponseInterfaceFactory;
use Magento\AsynchronousOperations\Api\Data\ItemStatusInterface;
use Magento\AsynchronousOperations\Api\Data\ItemStatusInterfaceFactory;

use Nacento\Connector\Api\AsyncBulkGalleryManagementInterface;
use Nacento\Connector\Api\Data\BulkRequestInterface;
use Nacento\Connector\Api\Data\ImageEntryInterface;

use Magento\AsynchronousOperations\Api\Data\OperationInterface as AsyncOperationInterface;
use Magento\Framework\Bulk\OperationInterface as BulkOperationInterface;

use Magento\AsynchronousOperations\Api\SaveMultipleOperationsInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Asynchronous planner for gallery processing with "Replace per SKU" strategy.
 */
class AsyncBulkGalleryPublisher implements AsyncBulkGalleryManagementInterface
{
    public function __construct(
        private readonly BulkManagementInterface $bulkManagement,
        private readonly SerializerInterface $serializer,
        private readonly UserContextInterface $userContext,
        private readonly AsyncResponseInterfaceFactory $asyncResponseInterfaceFactory,
        private readonly ItemStatusInterfaceFactory $itemStatusFactory,
        private readonly LoggerInterface $logger,
        private readonly OperationFactory $operationFactory,
        private readonly SaveMultipleOperationsInterface $saveMultipleOperations
    ) {}

    /**
     * Schedules bulk processing with "Replace per SKU" strategy.
     */
    public function submit(BulkRequestInterface $request): AsyncResponseInterface
    {
        $rid = substr(bin2hex(random_bytes(4)), 0, 8);
        $bulkUuid = $this->uuidV4();
        $userId = (int)($this->userContext->getUserId() ?? 0);
        $desc = 'Nacento gallery bulk (Replace per SKU)';

        $this->logger->info('[NacentoProcessor][BulkAsync][ENTER]', [
            'rid' => $rid, 
            'userId' => $userId, 
            'rawItems' => count($request->getItems() ?? []),
            'strategy' => 'replace_per_sku'
        ]);

        // Dedupe per SKU (last-wins) 
        $skuMap = [];
        foreach ($request->getItems() ?? [] as $item) {
            $sku = trim((string)($item->getSku() ?? ''));
            if ($sku !== '') {
                $skuMap[$sku] = $item;
            }
        }
        $uniqueItems = array_values($skuMap);

        $this->logger->info('[NacentoProcessor][BulkAsync][DEDUP]', [
            'rid' => $rid, 
            'uniqueItems' => count($uniqueItems),
            'duplicatesRemoved' => count($request->getItems() ?? []) - count($uniqueItems)
        ]);


        // crear magento_bulk (amb operacions buides)
        try {
            if (!$this->bulkManagement->scheduleBulk($bulkUuid, [], $desc, $userId)) {
                throw new LocalizedException(__('Could not create the bulk record.'));
            }
            $this->logger->info('[NacentoProcessor][BulkAsync][BULK_CREATED]', [
                'rid' => $rid,
                'bulk' => $bulkUuid
            ]);
        } catch (\Exception $e) {
            $this->logger->critical('[NacentoProcessor][BulkAsync][BULK_CREATE_ERROR]', [
                'rid' => $rid,
                'error' => $e->getMessage()
            ]);
            throw new LocalizedException(__('Something went wrong while processing the request.'));
        }

        $operations = [];
        $statuses = [];
        $responseSeq = 1;
        $operationKey = 0;

        // Create one operation PER SKU
        foreach ($uniqueItems as $item) {
            $sku = trim((string)($item->getSku() ?? ''));

            // Validate SKU presence
            if ($sku === '') {
                $statuses[] = $this->makeStatus($responseSeq++, '', ItemStatusInterface::STATUS_REJECTED, 'Missing SKU');
                continue;
            }
            
            $images = $this->normalizeImages($item->getImages() ?? []);

            // Validate that there are images to process for this SKU
            if (empty($images)) {
                $this->logger->warning('[NacentoProcessor][BulkAsync] SKU has no valid images, skipping.', [
                    'rid' => $rid,
                    'bulk' => $bulkUuid,
                    'sku' => $sku
                ]);
                $statuses[] = $this->makeStatus($responseSeq++, $sku, ItemStatusInterface::STATUS_REJECTED, 'SKU has no valid images');
                continue;
            }
            
            // Payload for a single SKU
            $operationPayload = [
                'bulk_uuid' => $bulkUuid,
                'sku' => $sku,
                'images' => $images,
                'store_id' => 0,
                'strategy' => 'replace_per_sku',
            ];

            /** @var \Magento\AsynchronousOperations\Api\Data\OperationInterface $operation */
            $operation = $this->operationFactory->create();
            $operation->setBulkUuid($bulkUuid);
            $operation->setTopicName('nacento.media-gallery.sync.sku');
            $operation->setSerializedData($this->serializer->serialize($operationPayload));
            $operation->setStatus(\Magento\Framework\Bulk\OperationInterface::STATUS_TYPE_OPEN);
            
            // Assignem l'ID seqüencial que actuarà com a operation_key
            $operation->setId($operationKey++);

            $this->logger->info('[NacentoProcessor][BulkAsync][OPERATION_CREATED]', [
                'rid' => $rid,
                'bulk' => $bulkUuid,
                'sku' => $sku,
                'operation_key' => $operation->getId() // Obtenim l'ID que acabem de posar
            ]);


            $operations[] = $operation;
            $statuses[] = $this->makeStatus($responseSeq++, $sku, ItemStatusInterface::STATUS_ACCEPTED);
        }
        
        if (empty($operations)) {
            return $this->createAsyncResponse($bulkUuid, $statuses);
        }

        $this->logger->info('[NacentoProcessor][BulkAsync][OPERATIONS_PREPARED]', [
            'rid' => $rid,
            'bulk' => $bulkUuid,
            'count' => count($operations)
        ]);

        // PAS 3: PUBLICAR ELS MISSATGES A LA CUA
        try {
            if (!$this->bulkManagement->scheduleBulk($bulkUuid, $operations, $desc, $userId)) {
                throw new LocalizedException(__('Could not publish operations for the bulk.'));
            }
            $this->logger->info('[NacentoProcessor][BulkAsync][OPERATIONS_PUBLISHED]', [
                'rid' => $rid,
                'bulk' => $bulkUuid,
                'count' => count($operations)
            ]);
        } catch (\Exception $e) {
            $this->logger->critical('[NacentoProcessor][BulkAsync][BULK_PUBLISH_ERROR]', [
                'rid' => $rid,
                'bulk' => $bulkUuid,
                'error' => $e->getMessage()
            ]);
            throw new LocalizedException(__('Something went wrong while processing the request.'));
        }

        // PAS 4: DESAR LES OPERACIONS A magento_operation
        try {
            $this->saveMultipleOperations->execute($operations);
            // --- LOG DE CONFIRMACIÓ AFEGIT ---
            $this->logger->info('[NacentoProcessor][BulkAsync][OPERATIONS_SAVE_SUCCESS]', [
                'rid' => $rid,
                'bulk' => $bulkUuid,
                'count' => count($operations)
            ]);
            // ------------------------------------
        } catch (\Exception $e) {
            $this->logger->critical('[NacentoProcessor][BulkAsync][OPERATIONS_SAVE_ERROR]', [
                'rid' => $rid,
                'bulk' => $bulkUuid,
                'error' => $e->getMessage()
            ]);
            throw new LocalizedException(__('Operations were published but failed to be saved.'));
        }
        
        return $this->createAsyncResponse($bulkUuid, $statuses);
    }

    /**
     * Normalize images to consistent format
     */
    private function normalizeImages(array $images): array
    {
        $normalized = [];

        foreach ($images as $img) {
            $imageData = $this->extractImageData($img);
            
            // Basic validation only
            if (empty($imageData['file_path']) || empty($imageData['label'])) {
                continue;
            }

            $normalized[] = $imageData;
        }

        return $normalized;
    }

    /**
     * Extract image data from various formats (DTO, DataObject, array)
     */
    private function extractImageData($img): array
    {
        if ($img instanceof ImageEntryInterface) {
            return [
                'file_path' => trim((string)$img->getFilePath(), '/\\'),
                'label' => (string)$img->getLabel(),
                'disabled' => (bool)$img->isDisabled(),
                'position' => (int)$img->getPosition(),
                'roles' => $img->getRoles() ? array_values($img->getRoles()) : [],
            ];
        }

        if ($img instanceof DataObject) {
            $data = $img->getData();
            return [
                'file_path' => trim((string)($data['file_path'] ?? ''), '/\\'),
                'label' => (string)($data['label'] ?? ''),
                'disabled' => !empty($data['disabled']),
                'position' => (int)($data['position'] ?? 0),
                'roles' => isset($data['roles']) && is_array($data['roles']) ? array_values($data['roles']) : [],
            ];
        }

        if (is_array($img)) {
            return [
                'file_path' => trim((string)($img['file_path'] ?? ''), '/\\'),
                'label' => (string)($img['label'] ?? ''),
                'disabled' => !empty($img['disabled']),
                'position' => (int)($img['position'] ?? 0),
                'roles' => isset($img['roles']) && is_array($img['roles']) ? array_values($img['roles']) : [],
            ];
        }

        $this->logger->warning('[NacentoProcessor][BulkAsync] Unexpected image format', ['type' => gettype($img)]);
        return [];
    }

    /**
     * Create async response
     */
    private function createAsyncResponse(string $bulkUuid, array $statuses): AsyncResponseInterface
    {
        $response = $this->asyncResponseInterfaceFactory->create();
        $response->setBulkUuid($bulkUuid);
        $response->setRequestItems($statuses);
        
        $hasErrors = count(array_filter($statuses, function($status) {
            return $status->getStatus() === ItemStatusInterface::STATUS_REJECTED;
        })) > 0;
        
        $response->setErrors($hasErrors);
        return $response;
    }

    /**
     * Create item status
     */
    private function makeStatus(int $id, string $sku, string $status, ?string $msg = null): ItemStatusInterface
    {
        $itemStatus = $this->itemStatusFactory->create();
        $itemStatus->setId($id);
        $itemStatus->setDataHash(md5($sku));
        $itemStatus->setStatus($status);
        
        if ($msg) {
            $itemStatus->setErrorMessage($msg);
        }
        
        return $itemStatus;
    }

    /**
     * Generate RFC-4122 compliant version 4 UUID
     */
    private function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}