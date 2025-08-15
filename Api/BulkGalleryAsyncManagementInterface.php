<?php
declare(strict_types=1);

namespace Nacento\Connector\Api;

use Nacento\Connector\Api\Data\BulkRequestInterface;
use Magento\AsynchronousOperations\Api\Data\AsyncResponseInterface;

/**
 * Planner de processats bulk de galeries (async).
 * @api
 */
interface BulkGalleryAsyncManagementInterface
{
    /**
     * Planifica el lot i retorna la resposta async “oficial” (bulk_uuid + items).
     *
     * @param BulkRequestInterface $request
     * @return \Magento\AsynchronousOperations\Api\Data\AsyncResponseInterface
     */
    public function submit(BulkRequestInterface $request): AsyncResponseInterface;
}
