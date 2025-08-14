<?php
declare(strict_types=1);

namespace Nacento\Connector\Api;

use Nacento\Connector\Api\Data\BulkRequestInterface;
use Nacento\Connector\Api\Data\BulkResultInterface;

interface BulkGalleryManagementInterface
{
    /**
     * Processa galeries d'imatges per múltiples SKU en una sola crida.
     * @param BulkRequestInterface $request
     * @return BulkResultInterface
     */
    public function process(BulkRequestInterface $request): BulkResultInterface;
}
