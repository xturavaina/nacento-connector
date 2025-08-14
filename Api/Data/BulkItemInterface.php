<?php
declare(strict_types=1);

namespace Nacento\Connector\Api\Data;

interface BulkItemInterface
{
    /** @return string */
    public function getSku(): string;
    /** @param string $sku @return $this */
    public function setSku(string $sku): self;

    /** @return \Nacento\Connector\Api\Data\ImageEntryInterface[] */
    public function getImages(): array;
    /**
     * @param \Nacento\Connector\Api\Data\ImageEntryInterface[] $images
     * @return $this
     */
    public function setImages(array $images): self;
}
