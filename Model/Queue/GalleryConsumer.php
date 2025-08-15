<?php
declare(strict_types=1);
namespace Nacento\Connector\Model\Queue;

use Nacento\Connector\Api\Data\ProcessGalleryMessageInterface;
use Nacento\Connector\Api\Data\ImageEntryInterface;
use Nacento\Connector\Model\Data\ImageEntryFactory;
use Nacento\Connector\Model\GalleryProcessor;
use Magento\Framework\DataObject;
use Psr\Log\LoggerInterface;

class GalleryConsumer
{
    public function __construct(
        private readonly GalleryProcessor $processor,
        private readonly ImageEntryFactory $imageEntryFactory,
        private readonly LoggerInterface $logger
    ) {}

    public function process(ProcessGalleryMessageInterface $msg): void
    {
        $sku    = (string)$msg->getSku();
        $images = $this->normalizeImages($msg->getImages());

        try {
            $this->processor->create($sku, $images);
        } catch (\Throwable $e) {
            $this->logger->error('[Nacento][GalleryConsumer] '.$sku.' -> '.$e->getMessage());
            throw $e; // perquè l'async framework marqui FAILED i gestioni reintents
        }
    }

    /** @return ImageEntryInterface[] */
    private function normalizeImages(array $images): array
    {
        $out = [];
        foreach ($images as $img) {
            if ($img instanceof ImageEntryInterface) {
                $out[] = $img;
            } elseif ($img instanceof DataObject) {
                $out[] = $this->imageEntryFactory->create(['data' => $img->getData()]);
            } elseif (is_array($img)) {
                $out[] = $this->imageEntryFactory->create(['data' => $img]);
            }
        }
        return $out;
    }
}
