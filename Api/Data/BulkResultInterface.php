<?php
declare(strict_types=1);

namespace Nacento\Connector\Api\Data;

interface BulkResultInterface
{
    /** @return string|null */
    public function getRequestId(): ?string;

    /** @return array Assumpte simple: ["skus_seen"=>int,"ok"=>int,"error"=>int,"inserted"=>int,"updated_value"=>int,"updated_meta"=>int,"skipped_no_change"=>int] */
    public function getStats(): array;

    /** @return \Nacento\Connector\Api\Data\BulkSkuResultInterface[] */
    public function getResults(): array;
}
