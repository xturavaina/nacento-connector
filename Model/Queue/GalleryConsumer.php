<?php
declare(strict_types=1);
namespace Nacento\Connector\Model\Queue;


use Magento\Framework\Bulk\OperationInterface;
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
        private readonly LoggerInterface $logger
    ) {}

    public function process(OperationInterface $operation): void
    {
        try {
            $dataJson = (string)($operation->getSerializedData() ?? '');
            $data     = $dataJson !== '' ? $this->serializer->unserialize($dataJson) : [];

            $sku     = (string)($data['sku'] ?? '');
            $images  = (array)($data['images'] ?? []);
            $entries = $this->normalizeImages($images);   // 👈 usa la helper

            if ($sku === '') {
                throw new \RuntimeException('SKU buit al payload');
            }

            $this->processor->create($sku, $entries);

        } catch (\Throwable $e) {
            // Que el bulk marqui FAILED; log amb context útil
            $this->logger->error(sprintf(
                '[Nacento][GalleryConsumer] opId=%s sku=%s error=%s',
                (string)$operation->getId(),
                $data['sku'] ?? 'NA',
                $e->getMessage()
            ));
            throw $e;
        }
    }

    /** @return ImageEntryInterface[] */
    private function normalizeImages(array $images): array
    {
        $out = [];

        foreach ($images as $idx => $img) {
            if (is_array($img)) {
                // whitelisteja + defaults
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

            // Payload inesperat: registra i continua
            $this->logger->warning(sprintf(
                '[Nacento][GalleryConsumer] Image payload inesperat a index %d: %s. Ometo.',
                $idx,
                gettype($img)
            ));
        }

        return $out;
    }
}
