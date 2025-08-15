<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Data;

use Magento\Framework\DataObject;

/**
 * Data model for the result of a bulk processing operation.
 * @see \Nacento\Connector\Api\Data\BulkResultInterface
 */
class BulkResult extends DataObject implements \Nacento\Connector\Api\Data\BulkResultInterface
{
    /**
     * {@inheritdoc}
     */
    public function getRequestId(): ?string { return $this->getData('request_id'); }

    /**
     * {@inheritdoc}
     */
    public function getStats(): \Nacento\Connector\Api\Data\BulkStatsInterface
    {
        /** @var \Nacento\Connector\Api\Data\BulkStatsInterface $stats */
        $stats = $this->getData('stats');
        return $stats;
    }

    /**
     * {@inheritdoc}
     */
    public function getResults(): array { return $this->getData('results') ?? []; }
}