<?php
declare(strict_types=1);

namespace Nacento\Connector\Api\Data;

interface BulkSkuResultInterface
{
    /** @return string */
    public function getSku(): string;

    /** @return int|null */
    public function getProductId(): ?int;

    /** @return array Ex.: ["inserted"=>int,"updated_value"=>int,"updated_meta"=>int,"skipped_no_change"=>int,"warnings"=>string[]] */
    public function getImageStats(): array;

    /** @return string|null error code si ha fallat ("product_not_found", "exception", ...) */
    public function getError(): ?string;
}
