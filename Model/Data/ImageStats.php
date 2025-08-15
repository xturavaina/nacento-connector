<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Data;

use Magento\Framework\DataObject;
use Nacento\Connector\Api\Data\ImageStatsInterface;

/**
 * Data model for detailed statistics of a single product's image gallery processing.
 * @see \Nacento\Connector\Api\Data\ImageStatsInterface
 */
class ImageStats extends DataObject implements ImageStatsInterface
{
    /**
     * {@inheritdoc}
     */
    public function getInserted(): int        { return (int)($this->getData('inserted') ?? 0); }

    /**
     * {@inheritdoc}
     */
    public function getUpdatedValue(): int    { return (int)($this->getData('updated_value') ?? 0); }

    /**
     * {@inheritdoc}
     */
    public function getUpdatedMeta(): int     { return (int)($this->getData('updated_meta') ?? 0); }

    /**
     * {@inheritdoc}
     */
    public function getSkippedNoChange(): int { return (int)($this->getData('skipped_no_change') ?? 0); }
    
    /**
     * {@inheritdoc}
     */
    public function getWarnings(): array      { $w = $this->getData('warnings'); return is_array($w) ? $w : []; }
}