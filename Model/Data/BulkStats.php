<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Data;

use Magento\Framework\DataObject;
use Nacento\Connector\Api\Data\BulkStatsInterface;

/**
 * Data model for overall statistics of a processed bulk batch.
 * @see \Nacento\Connector\Api\Data\BulkStatsInterface
 */
class BulkStats extends DataObject implements BulkStatsInterface
{
    /**
     * {@inheritdoc}
     */
    public function getSkusSeen(): int { return (int)($this->getData('skus_seen') ?? 0); }

    /**
     * {@inheritdoc}
     */
    public function getOk(): int { return (int)($this->getData('ok') ?? 0); }

    /**
     * {@inheritdoc}
     */
    public function getError(): int { return (int)($this->getData('error') ?? 0); }

    /**
     * {@inheritdoc}
     */
    public function getInserted(): int { return (int)($this->getData('inserted') ?? 0); }

    /**
     * {@inheritdoc}
     */
    public function getUpdatedValue(): int { return (int)($this->getData('updated_value') ?? 0); }

    /**
     * {@inheritdoc}
     */
    public function getUpdatedMeta(): int { return (int)($this->getData('updated_meta') ?? 0); }

    /**
     * {@inheritdoc}
     */
    public function getSkippedNoChange(): int { return (int)($this->getData('skipped_no_change') ?? 0); }
}