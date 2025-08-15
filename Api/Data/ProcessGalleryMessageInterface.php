<?php
declare(strict_types=1);
namespace Nacento\Connector\Api\Data;

/**
 * Defines the message structure for processing a product gallery.
 * This is typically used for asynchronous operations.
 * @api
 */
interface ProcessGalleryMessageInterface
{
    /**
     * Gets the product SKU associated with the gallery to be processed.
     * @return string
     */
    public function getSku(): string;

    /**
     * Retrieves the list of image entries for the gallery.
     * @return \Nacento\Connector\Api\Data\ImageEntryInterface[]
     */
    public function getImages(): array;
}