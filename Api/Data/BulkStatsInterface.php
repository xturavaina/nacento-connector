<?php
declare(strict_types=1);

namespace Nacento\Connector\Api\Data;

/**
 * Estadístiques globals del lot.
 * @api
 */
interface BulkStatsInterface
{
    /** @return int */
    public function getSkusSeen(): int;

    /** @return int */
    public function getOk(): int;

    /** @return int */
    public function getError(): int;

    /** @return int */
    public function getInserted(): int;

    /** @return int */
    public function getUpdatedValue(): int;

    /** @return int */
    public function getUpdatedMeta(): int;

    /** @return int */
    public function getSkippedNoChange(): int;
}
