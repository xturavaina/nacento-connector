<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Queue;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

class OperationStatusUpdater
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {}

    public function update(
        string $bulkUuid,
        int $operationId,
        ?string $operationKey,
        int $status,
        ?int $errorCode = null,
        ?string $message = null,
        ?string $data = null
    ): bool {
        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('magento_operation');

            $bind = [
                'error_code' => $errorCode,
                'status' => $status,
                'result_message' => $message,
                'serialized_data' => $data,
                'result_serialized_data' => '',
            ];

            $updated = 0;
            if ($operationKey !== null && $operationKey !== '') {
                $updated = $connection->update($table, $bind, [
                    'bulk_uuid = ?' => $bulkUuid,
                    'operation_key = ?' => $operationKey,
                ]);
            }

            if ($updated === 0) {
                $updated = $connection->update($table, $bind, [
                    'bulk_uuid = ?' => $bulkUuid,
                    'id = ?' => $operationId,
                ]);
            }

            if ($updated === 0) {
                $this->logger->warning(
                    sprintf('[NacentoConnector][OperationStatusUpdater] No rows updated for bulk=%s opId=%d opKey=%s', $bulkUuid, $operationId, (string)$operationKey)
                );
            }

            return $updated > 0;
        } catch (\Throwable $e) {
            $this->logger->critical($e->getMessage(), ['exception' => $e]);
            return false;
        }
    }
}
