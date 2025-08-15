<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Data;

use Magento\Framework\DataObject;

/**
 * Data model for the result of a single SKU processed within a bulk operation.
 * @see \Nacento\Connector\Api\Data\BulkSkuResultInterface
 */
class BulkSkuResult extends DataObject implements \Nacento\Connector\Api\Data\BulkSkuResultInterface
{
    /**
     * {@inheritdoc}
     */
    public function getSku(): string { return (string)$this->getData('sku'); }

    /**
     * {@inheritdoc}
     */
    public function getProductId(): ?int { return $this->hasData('product_id') ? (int)$this->getData('product_id') : null; }

    /**
     * {@inheritdoc}
     */
    public function getImageStats(): \Nacento\Connector\Api\Data\ImageStatsInterface
    {
        /** @var \Nacento\Connector\Api\Data\ImageStatsInterface $imgStats */
        $imgStats = $this->getData('image_stats');
        return $imgStats;
    }

    /**
     * {@inheritdoc}
     */
    public function getError(): ?string { return $this->getData('error'); }
}