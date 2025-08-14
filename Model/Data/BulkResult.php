<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Data;

use Magento\Framework\DataObject;
use Nacento\Connector\Api\Data\BulkResultInterface;

class BulkResult extends DataObject implements \Nacento\Connector\Api\Data\BulkResultInterface
{
    public function getRequestId(): ?string { return $this->getData('request_id'); }

    public function getStats(): \Nacento\Connector\Api\Data\BulkStatsInterface
    {
        /** @var \Nacento\Connector\Api\Data\BulkStatsInterface $stats */
        $stats = $this->getData('stats');
        return $stats;
    }

    public function getResults(): array { return $this->getData('results') ?? []; }
}
