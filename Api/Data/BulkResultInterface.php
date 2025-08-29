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
     * Aggregated counters for the processed batch.
     * @return array{
     *   skus_seen:int,
     *   ok:int,
     *   error:int,
     *   inserted:int,
     *   updated_value:int,
     *   updated_meta:int,
     *   skipped_no_change:int
     * }
     */
    public function getStats(): array;

    /**
     * @param array{
     *   skus_seen:int,
     *   ok:int,
     *   error:int,
     *   inserted:int,
     *   updated_value:int,
     *   updated_meta:int,
     *   skipped_no_change:int
     * } $stats
     * @return $this
     */
    public function setStats(array $stats);

    /**
     * Retrieves the detailed results for each individual SKU.
     *
     * @return \Nacento\Connector\Api\Data\BulkSkuResultInterface[]
     */
    public function getResults(): array;
}