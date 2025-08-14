<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Data;

use Magento\Framework\DataObject;
use Nacento\Connector\Api\Data\BulkResultInterface;

class BulkResult extends DataObject implements BulkResultInterface
{
    public function getRequestId(): ?string { return $this->getData('request_id'); }

    public function getStats(): array { return $this->getData('stats') ?? []; }

    public function getResults(): array { return $this->getData('results') ?? []; }
}
