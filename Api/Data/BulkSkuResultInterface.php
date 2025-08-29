<?php
declare(strict_types=1);

namespace Nacento\Connector\Api\Data;

/**
 * Represents the result of a single SKU processed within a bulk operation.
 * @api
 */
interface BulkSkuResultInterface
{
    public function getSku(): string;
    public function getProductId(): ?int;

    /**
     * Image counters for this SKU.
     * @return array{
     *   added:int,
     *   updated_value:int,
     *   updated_meta:int,
     *   skipped_no_change:int
     * }
     */
    public function getImageStats(): array;

    /**
     * @param array{
     *   added:int,
     *   updated_value:int,
     *   updated_meta:int,
     *   skipped_no_change:int
     * } $stats
     * @return $this
     */
    public function setImageStats(array $stats);

    public function getError(): ?string;
}
