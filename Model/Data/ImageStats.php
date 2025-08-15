<?php
declare(strict_types=1);

namespace Nacento\Connector\Api\Data;

/**
 * Estadístiques d'imatges per SKU.
 * @api
 */
interface ImageStatsInterface
{
    /** @return int */
    public function getInserted(): int;

    /** @return int */
    public function getUpdatedValue(): int;

    /** @return int */
    public function getUpdatedMeta(): int;

    /** @return int */
    public function getSkippedNoChange(): int;

    /**
     * Avisos no crítics durant el processat.
     *
     * @return string[]
     */
    public function getWarnings(): array;
}
