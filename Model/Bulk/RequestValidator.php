<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Bulk;

class RequestValidator
{
    public function normalizeRequestId(?string $requestId): ?string
    {
        if ($requestId === null) {
            return null;
        }

        $requestId = trim($requestId);
        return $requestId !== '' ? $requestId : null;
    }

    public function normalizeSku(string $sku): string
    {
        return trim($sku);
    }

    /**
     * @param array<string,mixed> $image
     */
    public function validateImage(array $image): ?string
    {
        if (($image['file_path'] ?? '') === '') {
            return 'Missing file_path';
        }

        return null;
    }
}
