<?php
declare(strict_types=1);

namespace Nacento\Connector\Api\Data;

/**
 * Represents the result of a single SKU processed within a bulk operation.
 * @api
 */
interface BulkSkuResultInterface
{
    /**
     * Gets the SKU that was processed.
     *
     * @return string
     */
    public function getSku(): string;

    /**
     * Gets the internal product ID (if it exists).
     *
     * @return int|null Returns the product entity ID or null if the product was not found.
     */
    public function getProductId(): ?int;

    /**
     * Retrieves the gallery processing statistics for this specific SKU.
     *
     * @return \Nacento\Connector\Api\Data\ImageStatsInterface
     */
    public function getImageStats();

    /**
     * Returns an error code if the processing failed for this SKU.
     *
     * Example error codes could be "product_not_found", "exception", etc.
     *
     * @return string|null Returns the error code as a string, or null if there was no error.
     */
    public function getError(): ?string;
}