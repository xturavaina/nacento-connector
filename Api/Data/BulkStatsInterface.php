<?php
declare(strict_types=1);

namespace Nacento\Connector\Api\Data;

/**
 * Provides overall statistics for a processed bulk batch.
 * @api
 */
interface BulkStatsInterface
{
    /**
     * Gets the total number of unique SKUs encountered in the request.
     * @return int
     */
    public function getSkusSeen(): int;

    /**
     * Gets the number of SKUs that were processed successfully.
     * @return int
     */
    public function getOk(): int;

    /**
     * Gets the number of SKUs that failed to be processed due to an error.
     * @return int
     */
    public function getError(): int;

    /**
     * Gets the total count of new images that were successfully inserted.
     * @return int
     */
    public function getInserted(): int;

    /**
     * Gets the total count of existing images that had their file content updated.
     * @return int
     */
    public function getUpdatedValue(): int;

    /**
     * Gets the total count of existing images that only had their metadata updated (e.g., roles, label, position).
     * @return int
     */
    public function getUpdatedMeta(): int;

    /**
     * Gets the total count of images that were skipped because no changes were detected.
     * @return int
     */
    public function getSkippedNoChange(): int;
}