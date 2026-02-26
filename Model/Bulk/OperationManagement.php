<?php

declare(strict_types=1);

namespace Nacento\Connector\Model\Bulk;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Bulk\OperationManagementInterface;
use Psr\Log\LoggerInterface;

class OperationManagement implements OperationManagementInterface
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {}

    public function changeOperationStatus(
        $bulkUuid,
        $operationKey,
        $status,
        $errorCode = null,
        $message = null,
        $data = null
    ) {
        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('magento_operation');

            $bind = [
                'error_code'            => $errorCode,
                'status'                => $status,
                'result_message'        => $message,
                'serialized_data'       => $data,
                'result_serialized_data' => '',
            ];

            // 1r intent: contracte core (bulk_uuid + operation_key)
            $where = [
                'bulk_uuid = ?'    => $bulkUuid,
                'operation_key = ?' => $operationKey,
            ];

            $updated = $connection->update($table, $bind, $where);

            // Fallback: si no hi ha cap fila (operation_key NULL), fem servir id
            if ($updated === 0) {
                $where = [
                    'bulk_uuid = ?' => $bulkUuid,
                    'id = ?'        => $operationKey,
                ];
                $updated = $connection->update($table, $bind, $where);
            }

            if ($updated === 0) {
                $this->logger->warning(sprintf(
                    '[Nacento][OperationManagement] No rows updated for bulk=%s opKey=%s',
                    (string)$bulkUuid,
                    (string)$operationKey
                ));
            }

            return $updated > 0;
        } catch (\Exception $e) {
            $this->logger->critical($e->getMessage(), ['exception' => $e]);
            return false;
        }
    }
}
