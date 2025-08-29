<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Data;

use Magento\Framework\DataObject;
use Nacento\Connector\Api\Data\BulkSkuResultInterface;

/**
 * Data model for the result of a single SKU processed within a bulk operation.
 * @see \Nacento\Connector\Api\Data\BulkSkuResultInterface
 */
class BulkSkuResult extends DataObject implements BulkSkuResultInterface
{
    public function getSku(): string
    {
        return (string) $this->getData('sku');
    }

    public function getProductId(): ?int
    {
        $v = $this->getData('product_id');
        return $v !== null ? (int) $v : null;
    }

    // >>> CANVI CLAU: torna un array, no pas ImageStatsInterface
    public function getImageStats(): array
    {
        $stats = $this->getData('image_stats');
        if (is_array($stats)) {
            return [
                'added'             => (int) ($stats['added'] ?? 0),
                'updated_value'     => (int) ($stats['updated_value'] ?? 0),
                'updated_meta'      => (int) ($stats['updated_meta'] ?? 0),
                'skipped_no_change' => (int) ($stats['skipped_no_change'] ?? 0),
            ];
        }

        // Back-compat per si guardaves camps plans
        return [
            'added'             => (int) ($this->getData('added') ?? 0),
            'updated_value'     => (int) ($this->getData('updated_value') ?? 0),
            'updated_meta'      => (int) ($this->getData('updated_meta') ?? 0),
            'skipped_no_change' => (int) ($this->getData('skipped_no_change') ?? 0),
        ];
    }

    // Opcional però útil si vols establir-ho d'un cop
    public function setImageStats(array $stats)
    {
        return $this->setData('image_stats', $stats);
    }

    public function getError(): ?string
    {
        $v = $this->getData('error');
        return $v !== null ? (string) $v : null;
    }
}