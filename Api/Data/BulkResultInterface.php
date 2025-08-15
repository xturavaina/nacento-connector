<?php
declare(strict_types=1);

namespace Nacento\Connector\Api\Data;

/**
 * Represents the result of a bulk processing operation.
 * @api
 */
interface BulkResultInterface
{
    /**
     * Retrieves the request ID for idempotency or correlation.
     *
     * This should match the ID provided in the initial BulkRequestInterface.
     *
     * @return string|null
     */
    public function getRequestId(): ?string;

    /**
     * Retrieves the overall statistics for the processed batch.
     *
     * @return \Nacento\Connector\Api\Data\BulkStatsInterface
     */
    public function getStats();

    /**
     * Retrieves the detailed results for each individual SKU.
     *
     * @return \Nacento\Connector\Api\Data\BulkSkuResultInterface[]
     */
    public function getResults(): array;
}