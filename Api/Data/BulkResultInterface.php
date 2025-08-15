<?php
declare(strict_types=1);

namespace Nacento\Connector\Api\Data;

/**
 * Resultat d'un processat bulk.
 * @api
 */
interface BulkResultInterface
{
    /**
     * Idempotency / correlació del lot.
     *
     * @return string|null
     */
    public function getRequestId(): ?string;

    /**
     * Estadístiques globals del lot.
     *
     * @return \Nacento\Connector\Api\Data\BulkStatsInterface
     */
    public function getStats();

    /**
     * Resultat per SKU.
     *
     * @return \Nacento\Connector\Api\Data\BulkSkuResultInterface[]
     */
    public function getResults(): array;
}
