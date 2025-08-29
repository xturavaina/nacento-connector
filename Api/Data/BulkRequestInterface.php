<?php
declare(strict_types=1);

namespace Nacento\Connector\Api\Data;

/**
 * Represents a bulk request for galleries.
 * @api
 */
interface BulkRequestInterface
{
    /**
     * Optional ID for idempotency or correlation purposes.
     *
     * @return string|null
     */
    public function getRequestId(): ?string;

    /**
     * Sets the optional request ID.
     *
     * @param string|null $requestId The request ID.
     * @return $this
     */
    public function setRequestId(?string $requestId);

    /**
     * Retrieves the items to be processed in this bulk request.
     *
     * Each item is an array with:
     *  - 'sku' (string): Product SKU.
     *  - 'images' (\Nacento\Connector\Api\Data\ImageEntryInterface[]): List of image entries.
     *
     * @return array<int, array{sku:string, images:\Nacento\Connector\Api\Data\ImageEntryInterface[]}>
     */
    public function getItems(): array;

    /**
     * Sets the items for this bulk request.
     *
     * Each item is an array with:
     *  - 'sku' (string): Product SKU.
     *  - 'images' (\Nacento\Connector\Api\Data\ImageEntryInterface[]): List of image entries.
     *
     * @param array<int, array{sku:string, images:\Nacento\Connector\Api\Data\ImageEntryInterface[]}> $items
     * @return $this
     */
    public function setItems(array $items);
}
