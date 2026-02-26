<?php

declare(strict_types=1);

namespace Nacento\Connector\Model\Queue;

use Magento\AsynchronousOperations\Api\Data\OperationInterface as AsyncOperationInterface;
use Magento\Framework\Bulk\OperationInterface;
use Magento\Framework\Bulk\OperationManagementInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Nacento\Connector\Api\Data\ImageEntryInterface;
use Nacento\Connector\Model\Data\ImageEntryFactory;
use Nacento\Connector\Model\GalleryProcessor;
use Psr\Log\LoggerInterface;

class GalleryConsumer
{
    public function __construct(
        private readonly GalleryProcessor $processor,
        private readonly ImageEntryFactory $imageEntryFactory,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger,
        private readonly OperationManagementInterface $operationManagement
    ) {}

    public function process(AsyncOperationInterface $operation): void
    {
        $status    = OperationInterface::STATUS_TYPE_COMPLETE;
        $errorCode = null;
        $message   = null;

        $dataJson = (string)($operation->getSerializedData() ?? '');

        try {
            $data = $dataJson !== '' ? $this->serializer->unserialize($dataJson) : [];

            $sku    = (string)($data['sku'] ?? '');
            $images = (array)($data['images'] ?? []);

            if ($sku === '') {
                throw new \RuntimeException('SKU is empty in the message payload');
            }

            $entries = $this->normalizeImages($images);
            $this->processor->create($sku, $entries);

            $this->logger->debug(sprintf(
                '[Nacento][GalleryConsumer] opId=%s bulk=%s sku=%s OK',
                (string)$operation->getId(),
                (string)$operation->getBulkUuid(),
                $sku
            ));
        } catch (\Throwable $e) {
            $status    = OperationInterface::STATUS_TYPE_NOT_RETRIABLY_FAILED;
            $errorCode = (int)$e->getCode();
            $message   = $e->getMessage();

            $this->logger->error(sprintf(
                '[Nacento][GalleryConsumer] opId=%s bulk=%s error=%s',
                (string)$operation->getId(),
                (string)$operation->getBulkUuid(),
                $e->getMessage()
            ));
        } finally {
            // 👇 aquest `finally` s’executa tant si hi ha error com si no
            $this->operationManagement->changeOperationStatus(
                (string)$operation->getBulkUuid(),
                (int)$operation->getId(),     // 👈 clau: operation_key
                $status,
                $errorCode,
                $message,
                $dataJson
            );
        }
    }

    private function normalizeImages(array $images): array
    {
        $out = [];

        foreach ($images as $idx => $img) {
            if (is_array($img)) {
                $data = [
                    'file_path' => isset($img['file_path']) ? (string)$img['file_path'] : '',
                    'label'     => isset($img['label']) ? (string)$img['label'] : '',
                    'disabled'  => !empty($img['disabled']),
                    'position'  => isset($img['position']) ? (int)$img['position'] : 0,
                    'roles'     => isset($img['roles']) && is_array($img['roles'])
                        ? array_values(array_filter($img['roles']))
                        : [],
                ];
                $out[] = $this->imageEntryFactory->create(['data' => $data]);
                continue;
            }

            if ($img instanceof ImageEntryInterface) {
                $out[] = $img;
                continue;
            }

            if ($img instanceof \Magento\Framework\DataObject) {
                $out[] = $this->imageEntryFactory->create(['data' => $img->getData()]);
                continue;
            }

            $this->logger->warning(sprintf(
                '[Nacento][GalleryConsumer] Unexpected image payload format at index %d: %s. Skipping.',
                $idx,
                gettype($img)
            ));
        }

        return $out;
    }
}
