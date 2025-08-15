<?php
declare(strict_types=1);

namespace Nacento\Connector\Api\Data;

/**
 * Provides detailed statistics for the processing of a single product's image gallery.
 * @api
 */
interface ImageStatsInterface
{
    /**
     * Gets the number of new images that were successfully inserted.
     * @return int
     */
    public function getInserted(): int;

    /**
     * Gets the number of existing images that had their file content updated.
     * @return int
     */
    public function getUpdatedValue(): int;

    /**
     * Gets the number of existing images that only had their metadata updated (e.g., label, roles, position).
     * @return int
     */
    public function getUpdatedMeta(): int;

    /**
     * Gets the number of images that were skipped because no changes were detected.
     * @return int
     */
    public function getSkippedNoChange(): int;

    /**
     * Retrieves a list of any warnings generated during the processing of the gallery.
     * @return string[]
     */
    public function getWarnings(): array;
}