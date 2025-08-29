<?php
declare(strict_types=1);

namespace Nacento\Connector\Api\Data;

/**
 * One SKU payload inside the bulk request.
 * @api
 */
interface BulkItemInterface
{
    /** @return string */
    public function getSku(): string;

    /**
     * @return \Nacento\Connector\Api\Data\ImageEntryInterface[]
     */
    public function getImages(): array;

    /** @param string $sku @return $this */
    public function setSku(string $sku);

    /**
     * @param \Nacento\Connector\Api\Data\ImageEntryInterface[] $images
     * @return $this
     */
    public function setImages(array $images);
}
