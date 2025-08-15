<?php
declare(strict_types=1);

namespace Nacento\Connector\Api;

use Nacento\Connector\Api\Data\BulkRequestInterface;
use Nacento\Connector\Api\Data\BulkResultInterface;

/**
 * Interface for managing synchronous bulk gallery processing.
 * @api
 */
interface BulkGalleryManagementInterface
{
    /**
     * Processes image galleries for multiple SKUs in a single, synchronous call.
     *
     * @param BulkRequestInterface $request The bulk request containing all the items to be processed.
     * @return BulkResultInterface The result of the bulk operation, including statistics and outcomes for each SKU.
     */
    public function process(BulkRequestInterface $request): BulkResultInterface;
}