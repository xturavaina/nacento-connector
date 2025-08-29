<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Data;

use Magento\Framework\DataObject;
use Nacento\Connector\Api\Data\BulkResultInterface;

/**
 * Data model for the result of a bulk processing operation.
 * @see \Nacento\Connector\Api\Data\BulkResultInterface
 */
class BulkResult extends DataObject implements BulkResultInterface
{
    /** {@inheritdoc} */
    public function getRequestId(): ?string
    {
        $v = $this->getData('request_id');
        return $v !== null ? (string) $v : null;
    }

    /** Opcional, però útil */
    public function setRequestId(?string $requestId)
    {
        return $this->setData('request_id', $requestId);
    }

    /**
     * {@inheritdoc}
     */
    public function getStats(): array
    {
        $stats = $this->getData('stats');

        if (is_array($stats)) {
            // Normalitza i aplica valors per defecte
            return [
                'skus_seen'         => (int) ($stats['skus_seen'] ?? 0),
                'ok'                => (int) ($stats['ok'] ?? 0),
                'error'             => (int) ($stats['error'] ?? 0),
                'inserted'          => (int) ($stats['inserted'] ?? 0),
                'updated_value'     => (int) ($stats['updated_value'] ?? 0),
                'updated_meta'      => (int) ($stats['updated_meta'] ?? 0),
                'skipped_no_change' => (int) ($stats['skipped_no_change'] ?? 0),
            ];
        }

        // Back-compat si abans guardaves camps plans al DataObject
        return [
            'skus_seen'         => (int) ($this->getData('skus_seen') ?? 0),
            'ok'                => (int) ($this->getData('ok') ?? 0),
            'error'             => (int) ($this->getData('error') ?? 0),
            'inserted'          => (int) ($this->getData('inserted') ?? 0),
            'updated_value'     => (int) ($this->getData('updated_value') ?? 0),
            'updated_meta'      => (int) ($this->getData('updated_meta') ?? 0),
            'skipped_no_change' => (int) ($this->getData('skipped_no_change') ?? 0),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function setStats(array $stats)
    {
        return $this->setData('stats', $stats);
    }

    /** {@inheritdoc} */
    public function getResults(): array
    {
        $res = $this->getData('results');
        return is_array($res) ? $res : [];
    }

    /** Opcional, però útil */
    public function setResults(array $results)
    {
        return $this->setData('results', $results);
    }
}
