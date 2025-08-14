<?php
declare(strict_types=1);

namespace Nacento\Connector\Api\Data;

interface ImageStatsInterface
{
    public function getInserted(): int;
    public function getUpdatedValue(): int;
    public function getUpdatedMeta(): int;
    public function getSkippedNoChange(): int;

    /** @return string[] */
    public function getWarnings(): array;
}
