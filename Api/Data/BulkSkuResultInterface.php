<?php
declare(strict_types=1);

namespace Nacento\Connector\Api\Data;

interface BulkSkuResultInterface
{
    public function getSku(): string;
    public function getProductId(): ?int;

    /** @return \Nacento\Connector\Api\Data\ImageStatsInterface */
    public function getImageStats();

    /** @return string|null error code ("product_not_found","exception",...) */
    public function getError(): ?string;
}
