<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Data;

use Magento\Framework\DataObject;
use Nacento\Connector\Api\Data\BulkSkuResultInterface;

class BulkSkuResult extends DataObject implements \Nacento\Connector\Api\Data\BulkSkuResultInterface
{
    public function getSku(): string { return (string)$this->getData('sku'); }
    public function getProductId(): ?int { return $this->hasData('product_id') ? (int)$this->getData('product_id') : null; }

    public function getImageStats(): \Nacento\Connector\Api\Data\ImageStatsInterface
    {
        /** @var \Nacento\Connector\Api\Data\ImageStatsInterface $imgStats */
        $imgStats = $this->getData('image_stats');
        return $imgStats;
    }

    public function getError(): ?string { return $this->getData('error'); }
}
