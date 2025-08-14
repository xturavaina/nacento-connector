<?php
declare(strict_types=1);

namespace Nacento\Connector\Api\Data;

interface BulkStatsInterface
{
    public function getSkusSeen(): int;
    public function getOk(): int;
    public function getError(): int;
    public function getInserted(): int;
    public function getUpdatedValue(): int;
    public function getUpdatedMeta(): int;
    public function getSkippedNoChange(): int;
}
