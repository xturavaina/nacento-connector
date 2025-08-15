<?php
declare(strict_types=1);

namespace Nacento\Connector\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Bulk\BulkManagementInterface;
use Magento\Framework\Bulk\OperationInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Math\Random;
use Magento\Framework\DataObject;
use Psr\Log\LoggerInterface;

use Magento\AsynchronousOperations\Api\Data\AsyncResponseInterface;
use Magento\AsynchronousOperations\Api\Data\AsyncResponseInterfaceFactory;
use Magento\AsynchronousOperations\Api\Data\ItemStatusInterface;
use Magento\AsynchronousOperations\Api\Data\ItemStatusInterfaceFactory;

use Magento\AsynchronousOperations\Model\OperationFactory;

use Nacento\Connector\Api\BulkGalleryAsyncManagementInterface;
use Nacento\Connector\Api\Data\BulkRequestInterface;
use Nacento\Connector\Api\Data\ImageEntryInterface;

/**
 * Asynchronous planner for publishing gallery processing batches.
 *
 * This class is responsible for:
 * - Deduplicating incoming items by SKU (last-wins).
 * - Converting image data objects (DTOs) into plain arrays before serialization.
 * - Scheduling the bulk operation and returning an AsyncResponse with the bulk_uuid and item statuses.
 */
class BulkGalleryAsyncManagement implements BulkGalleryAsyncManagementInterface
{
    /**
     * @param BulkManagementInterface $bulkManagement Core Magento service for scheduling bulk operations.
     * @param OperationFactory $operationFactory Factory to create individual operation objects for the queue.
     * @param SerializerInterface $serializer Handles serialization of the payload for the message queue.
     * @param UserContextInterface $userContext Provides the ID of the user initiating the request.
     * @param AsyncResponseInterfaceFactory $asyncResponseFactory Factory to create the final asynchronous response.
     * @param ItemStatusInterfaceFactory $itemStatusFactory Factory to create status objects for each item in the request.
     * @param Random $random Utility for generating random/unique hashes.
     * @param LoggerInterface $logger For logging warnings or errors.
     */
    public function __construct(
        private readonly BulkManagementInterface $bulkManagement,
        private readonly OperationFactory $operationFactory,
        private readonly SerializerInterface $serializer,
        private readonly UserContextInterface $userContext,
        private readonly AsyncResponseInterfaceFactory $asyncResponseFactory,
        private readonly ItemStatusInterfaceFactory $itemStatusFactory,
        private readonly Random $random,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Schedules the bulk processing and returns an AsyncResponse with the bulk_uuid.
     *
     * @param BulkRequestInterface $request The incoming bulk request.
     * @return AsyncResponseInterface
     */
    public function submit(BulkRequestInterface $request): AsyncResponseInterface
    {
        // Generate a unique identifier for this entire bulk operation.
        $bulkUuid = $this->uuidV4(); // You could also use $this->random->getUniqueHash();
        // Get the current user ID for ownership and permissions.
        $userId   = (int)($this->userContext->getUserId() ?? 0);
        // Define a description for the bulk operation.
        $desc     = 'Nacento gallery bulk';

        // Step 1: Deduplicate items by SKU, ensuring only the last entry for each SKU is processed.
        $map = [];
        foreach ($request->getItems() ?? [] as $it) {
            $sku = (string)($it->getSku() ?? '');
            if ($sku !== '') {
                $map[$sku] = $it;
            }
        }
        $unique = array_values($map);

        // Step 2: Build the individual operations for the message queue and their corresponding status reports.
        $operations = [];
        $statuses   = [];
        $seq        = 1;

        foreach ($unique as $it) {
            $sku = (string)($it->getSku() ?? '');
            // If an item lacks a SKU, it's invalid and gets rejected immediately.
            if ($sku === '') {
                $statuses[] = $this->makeStatus($seq++, '', ItemStatusInterface::STATUS_REJECTED, 'Missing SKU');
                continue;
            }

            // Convert image objects into a simple, serializable array payload.
            $imagesPayload = $this->imagesToPayload($it->getImages() ?? []);

            // This is the final payload that will travel through the message queue to the consumer.
            $payload = [
                'sku'    => $sku,
                'images' => $imagesPayload,
            ];

            // Create a new operation for this single SKU.
            $operations[] = $this->operationFactory->create([
                'data' => [
                    OperationInterface::BULK_ID         => $bulkUuid,
                    OperationInterface::TOPIC_NAME      => 'nacento.gallery.process',
                    OperationInterface::SERIALIZED_DATA => $this->serializer->serialize($payload),
                    OperationInterface::STATUS          => OperationInterface::STATUS_TYPE_OPEN,
                ],
            ]);

            // Create an 'accepted' status for this valid SKU to be included in the response.
            $statuses[] = $this->makeStatus($seq++, $sku, ItemStatusInterface::STATUS_ACCEPTED);
        }

        // Only schedule the bulk operation if there are valid items to process.
        if (!empty($operations)) {
            $this->bulkManagement->scheduleBulk($bulkUuid, $operations, $desc, $userId);
        } else {
            // Log a warning if the request was empty or contained no valid SKUs.
            $this->logger->warning('[NacentoConnector][BulkPlanner] No operations were scheduled (were there any valid SKUs?)');
        }

        // Step 3: Construct and return the standard asynchronous response object.
        $resp = $this->asyncResponseFactory->create();
        $resp->setBulkUuid($bulkUuid);
        $resp->setRequestItems($statuses);
        $resp->setErrors(false);

        return $resp;
    }

    /**
     * Converts a collection of images (DTOs, arrays, DataObjects) into whitelisted, plain associative arrays.
     * This ensures the payload is clean, consistent, and serializable.
     *
     * @param array $images The mixed array of image data.
     * @return array<int,array{file_path:string,label:string,disabled:bool,position:int,roles:array}>
     */
    private function imagesToPayload(array $images): array
    {
        $out = [];

        foreach ($images as $img) {
            // Case 1: The item is already a typed DTO (ImageEntryInterface).
            if ($img instanceof ImageEntryInterface) {
                $out[] = [
                    'file_path' => (string)$img->getFilePath(),
                    'label'     => (string)$img->getLabel(),
                    'disabled'  => (bool)$img->isDisabled(),
                    'position'  => (int)$img->getPosition(),
                    'roles'     => array_values($img->getRoles() ?? []),
                ];
                continue;
            }

            // Case 2: The item is a generic DataObject.
            if ($img instanceof DataObject) {
                $row = $img->getData();
                $out[] = [
                    'file_path' => (string)($row['file_path'] ?? ''),
                    'label'     => (string)($row['label'] ?? ''),
                    'disabled'  => !empty($row['disabled']),
                    'position'  => (int)($row['position'] ?? 0),
                    'roles'     => isset($row['roles']) && is_array($row['roles']) ? array_values($row['roles']) : [],
                ];
                continue;
            }

            // Case 3: The item is a plain associative array.
            if (is_array($img)) {
                $out[] = [
                    'file_path' => (string)($img['file_path'] ?? ''),
                    'label'     => (string)($img['label'] ?? ''),
                    'disabled'  => !empty($img['disabled']),
                    'position'  => (int)($img['position'] ?? 0),
                    'roles'     => isset($img['roles']) && is_array($img['roles']) ? array_values($img['roles']) : [],
                ];
                continue;
            }

            // An unexpected format was found; log it and skip to avoid errors.
            $this->logger->warning('[NacentoConnector][BulkPlanner] Image with unexpected format ('.gettype($img).'). Skipping.');
        }

        return $out;
    }

    /**
     * Helper method to create a simple ItemStatus object.
     */
    private function makeStatus(int $id, string $sku, string $status, ?string $msg = null): ItemStatusInterface
    {
        $st = $this->itemStatusFactory->create();
        $st->setId($id);
        // A stable hash based on SKU (change if you need to include more fields).
        $st->setDataHash(md5($sku !== '' ? $sku : ('#'.$id)));
        $st->setStatus($status);
        if ($msg) {
            $st->setErrorMessage($msg);
        }
        return $st;
    }

    /**
     * Generates a RFC-4122 compliant version 4 UUID (8-4-4-4-12 format).
     */
    private function uuidV4(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40); // set version to 0100 (v4)
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80); // set variant to 10xx (RFC 4122)
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}