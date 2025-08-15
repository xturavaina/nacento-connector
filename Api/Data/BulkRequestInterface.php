<?php
declare(strict_types=1);

namespace Nacento\Connector\Api\Data;

/**
 * Petició bulk de galeries.
 * @api
 */
interface BulkRequestInterface
{
    /**
     * Id opcional per idempotència/correlació.
     *
     * @return string|null
     */
    public function getRequestId(): ?string;

    /**
     * @param string|null $requestId
     * @return $this
     */
    public function setRequestId(?string $requestId);

    /**
     * Items a processar.
     *
     * @return \Nacento\Connector\Api\Data\BulkItemInterface[]
     */
    public function getItems(): array;

    /**
     * @param \Nacento\Connector\Api\Data\BulkItemInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
