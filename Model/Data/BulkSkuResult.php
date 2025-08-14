<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Data;

use Magento\Framework\DataObject;
use Nacento\Connector\Api\Data\BulkSkuResultInterface;

class BulkSkuResult extends DataObject implements BulkSkuResultInterface
{
    public function getSku(): string { return (string)$this->getData('sku'); }
    public function getProductId(): ?int { return $this->getData('product_id') !== null ? (int)$this->getData('product_id') : null; }
    public function getImageStats(): array { return $this->getData('image_stats') ?? []; }
    public function getError(): ?string { return $this->getData('error'); }
}
