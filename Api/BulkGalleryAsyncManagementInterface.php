<?php
declare(strict_types=1);
namespace Nacento\Connector\Api;

use Nacento\Connector\Api\Data\BulkRequestInterface;

interface BulkGalleryAsyncManagementInterface
{
    /**
     * Planifica el processat bulk i retorna el bulk_uuid.
     * @return array {bulk_uuid: string, scheduled: int, request_id?: string}
     */
    public function submit(BulkRequestInterface $request);
}
