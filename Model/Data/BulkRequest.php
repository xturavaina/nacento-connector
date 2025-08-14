<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Data;

use Magento\Framework\DataObject;
use Nacento\Connector\Api\Data\BulkRequestInterface;

class BulkRequest extends DataObject implements BulkRequestInterface
{
    public function getRequestId(): ?string { return $this->getData('request_id'); }
    public function setRequestId(?string $requestId): self { return $this->setData('request_id', $requestId); }

    public function getItems(): array { return $this->getData('items') ?? []; }
    public function setItems(array $items): self { return $this->setData('items', $items); }
}
