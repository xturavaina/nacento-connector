<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Data;

use Magento\Framework\DataObject;
use Nacento\Connector\Api\Data\ImageStatsInterface;

class ImageStats extends DataObject implements ImageStatsInterface
{
    public function getInserted(): int        { return (int)($this->getData('inserted') ?? 0); }
    public function getUpdatedValue(): int    { return (int)($this->getData('updated_value') ?? 0); }
    public function getUpdatedMeta(): int     { return (int)($this->getData('updated_meta') ?? 0); }
    public function getSkippedNoChange(): int { return (int)($this->getData('skipped_no_change') ?? 0); }
    public function getWarnings(): array      { $w = $this->getData('warnings'); return is_array($w) ? $w : []; }
}
