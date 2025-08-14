<?php
declare(strict_types=1);

namespace Nacento\Connector\Api\Data;

interface BulkResultInterface
{
    public function getRequestId(): ?string;

    /** @return \Nacento\Connector\Api\Data\BulkStatsInterface */
    public function getStats();

    /** @return \Nacento\Connector\Api\Data\BulkSkuResultInterface[] */
    public function getResults(): array;
}

