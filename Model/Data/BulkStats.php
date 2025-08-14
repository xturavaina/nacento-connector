<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Data;

use Magento\Framework\DataObject;
use Nacento\Connector\Api\Data\BulkStatsInterface;

class BulkStats extends DataObject implements BulkStatsInterface
{
    public function getSkusSeen(): int        { return (int)($this->getData('skus_seen') ?? 0); }
    public function getOk(): int              { return (int)($this->getData('ok') ?? 0); }
    public function getError(): int           { return (int)($this->getData('error') ?? 0); }
    public function getInserted(): int        { return (int)($this->getData('inserted') ?? 0); }
    public function getUpdatedValue(): int    { return (int)($this->getData('updated_value') ?? 0); }
    public function getUpdatedMeta(): int     { return (int)($this->getData('updated_meta') ?? 0); }
    public function getSkippedNoChange(): int { return (int)($this->getData('skipped_no_change') ?? 0); }
}
