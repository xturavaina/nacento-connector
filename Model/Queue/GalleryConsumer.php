<?php
declare(strict_types=1);
namespace Nacento\Connector\Model\Queue;


use Magento\Framework\Bulk\OperationInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Nacento\Connector\Api\Data\ImageEntryInterface;
use Nacento\Connector\Model\Data\ImageEntryFactory;
use Nacento\Connector\Model\GalleryProcessor;
use Psr\Log\LoggerInterface;

/**
 * Consumer for processing gallery update messages from the message queue.
 */
class GalleryConsumer
{
    /**
     * @param GalleryProcessor $processor The service responsible for the core logic of gallery processing.
     * @param ImageEntryFactory $imageEntryFactory Factory to create ImageEntry data objects.
     * @param SerializerInterface $serializer Handles serialization and unserialization of message data.
     * @param LoggerInterface $logger For logging errors and warnings.
     */
    public function __construct(
        private readonly GalleryProcessor $processor,
        private readonly ImageEntryFactory $imageEntryFactory,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Processes a single gallery update operation from the queue.
     *
     * @param OperationInterface $operation The bulk operation containing the message data.
     * @return void
     * @throws \Throwable Re-throws exceptions to let the message queue framework mark the operation as failed.
     */
    public function process(OperationInterface $operation): void
    {
        try {
            // Retrieve and unserialize the message data from the operation.
            $dataJson = (string)($operation->getSerializedData() ?? '');
            $data     = $dataJson !== '' ? $this->serializer->unserialize($dataJson) : [];

            // Extract the SKU and the raw images array from the payload.
            $sku     = (string)($data['sku'] ?? '');
            $images  = (array)($data['images'] ?? []);

            // Sanitize and convert the raw image data into a consistent array of DTOs.
            $entries = $this->normalizeImages($images);

            // A SKU is mandatory for processing; throw an error if it's missing.
            if ($sku === '') {
                throw new \RuntimeException('SKU is empty in the message payload');
            }

            // Delegate the actual gallery creation/update logic to the processor service.
            $this->processor->create($sku, $entries);

        } catch (\Throwable $e) {
            // Log the error with useful context for debugging.
            $this->logger->error(sprintf(
                '[Nacento][GalleryConsumer] opId=%s sku=%s error=%s',
                (string)$operation->getId(),
                $data['sku'] ?? 'NA',
                $e->getMessage()
            ));
            // Re-throwing the exception to mark the queue message as 'FAILED'.
            throw $e;
        }
    }

    /**
     * Normalizes a raw array of image data into a clean array of ImageEntryInterface objects.
     *
     * @param array $images The raw image data from the message payload.
     * @return ImageEntryInterface[]
     */
    private function normalizeImages(array $images): array
    {
        $out = [];

        foreach ($images as $idx => $img) {
            // Handle the case where image data is a plain associative array.
            if (is_array($img)) {
                // Sanitize the data by whitelisting keys and applying default values.
                $data = [
                    'file_path' => isset($img['file_path']) ? (string)$img['file_path'] : '',
                    'label'     => isset($img['label']) ? (string)$img['label'] : '',
                    'disabled'  => !empty($img['disabled']),
                    'position'  => isset($img['position']) ? (int)$img['position'] : 0,
                    'roles'     => isset($img['roles']) && is_array($img['roles'])
                        ? array_values(array_filter($img['roles']))
                        : [],
                ];
                // Use the factory to create a structured ImageEntry object.
                $out[] = $this->imageEntryFactory->create(['data' => $data]);
                continue;
            }

            // If the item is already a valid ImageEntryInterface object, add it directly.
            if ($img instanceof ImageEntryInterface) {
                $out[] = $img;
                continue;
            }

            // If the item is a generic DataObject, convert it to a typed ImageEntry object.
            if ($img instanceof \Magento\Framework\DataObject) {
                $out[] = $this->imageEntryFactory->create(['data' => $img->getData()]);
                continue;
            }

            // If the image format is unexpected, log a warning and skip it to prevent a crash.
            $this->logger->warning(sprintf(
                '[Nacento][GalleryConsumer] Unexpected image payload format at index %d: %s. Skipping.',
                $idx,
                gettype($img)
            ));
        }

        return $out;
    }
}