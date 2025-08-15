<?php
declare(strict_types=1);

namespace Nacento\Connector\Api\Data;

/**
 * Item de petició (un SKU amb la seva galeria).
 * @api
 */
interface BulkItemInterface
{
    /**
     * SKU a processar.
     *
     * @return string
     */
    public function getSku(): string;

    /**
     * @param string $sku
     * @return $this
     */
    public function setSku(string $sku);

    /**
     * Galeria d'imatges per a l'SKU.
     *
     * @return \Nacento\Connector\Api\Data\ImageEntryInterface[]
     */
    public function getImages(): array;

    /**
     * @param \Nacento\Connector\Api\Data\ImageEntryInterface[] $images
     * @return $this
     */
    public function setImages(array $images);
}
