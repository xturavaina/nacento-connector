<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Data;

use Magento\Framework\DataObject;
use Nacento\Connector\Api\Data\BulkRequestInterface;

/**
 * Data model for a bulk gallery request.
 * @see \Nacento\Connector\Api\Data\BulkRequestInterface
 */
class BulkRequest extends DataObject implements BulkRequestInterface
{
    /**
     * {@inheritdoc}
     */
    public function getRequestId(): ?string { return $this->getData('request_id'); }

    /**
     * {@inheritdoc}
     */
    public function setRequestId(?string $requestId): self { return $this->setData('request_id', $requestId); }

    /**
     * {@inheritdoc}
     */
    public function getItems(): array { return $this->getData('items') ?? []; }

    /**
     * {@inheritdoc}
     */
    public function setItems(array $items): self { return $this->setData('items', $items); }
}