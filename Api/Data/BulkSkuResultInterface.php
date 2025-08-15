<?php
declare(strict_types=1);

namespace Nacento\Connector\Api\Data;

/**
 * Resultat d'un SKU dins del bulk.
 * @api
 */
interface BulkSkuResultInterface
{
    /**
     * SKU processat.
     *
     * @return string
     */
    public function getSku(): string;

    /**
     * ID intern del producte (si existeix).
     *
     * @return int|null
     */
    public function getProductId(): ?int;

    /**
     * Estadístiques de la galeria d'aquest SKU.
     *
     * @return \Nacento\Connector\Api\Data\ImageStatsInterface
     */
    public function getImageStats();

    /**
     * Codi d'error (si n'hi ha): "product_not_found", "exception", etc.
     *
     * @return string|null
     */
    public function getError(): ?string;
}
