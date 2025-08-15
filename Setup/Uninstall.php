<?php
declare(strict_types=1);

namespace Nacento\Connector\Setup;

use Magento\Framework\Amqp\ConnectionPool;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use Psr\Log\LoggerInterface;

class Uninstall implements UninstallInterface
{
    private const QUEUE_NAME = 'nacento.gallery.process';
    private const EXCHANGE_NAME = 'nacento.gallery.process';

    private ConnectionPool $connectionPool;
    private LoggerInterface $logger;

    public function __construct(
        ConnectionPool $connectionPool,
        LoggerInterface $logger
    ) {
        $this->connectionPool = $connectionPool;
        $this->logger = $logger;
    }

    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        $setup->startSetup();
        
        // 1. Drop the database table
        $this->dropDatabaseTable($setup);

        // 2. Attempt to clean up RabbitMQ resources safely
        $this->cleanupRabbitMq();
        
        $setup->endSetup();
    }

    private function dropDatabaseTable(SchemaSetupInterface $setup): void
    {
        $connection = $setup->getConnection();
        $tableName = 'nacento_media_gallery_meta';

        if ($setup->tableExists($tableName)) {
            $connection->dropTable($setup->getTable($tableName));
            $this->logger->info('[NacentoConnector] Uninstall: Database table ' . $tableName . ' dropped successfully.');
        }
    }

    private function cleanupRabbitMq(): void
    {
        $channel = null;
        try {
            $connection = $this->connectionPool->getConnection('amqp'); // Get the default AMQP connection
            $channel = $connection->channel();
            
            // Step 1: Passively check the queue status without modifying it.
            // This returns queue status, including message count, or throws an exception if it doesn't exist.
            $queueStatus = $channel->queue_declare(self::QUEUE_NAME, true);
            
            if (isset($queueStatus[1]) && $queueStatus[1] > 0) {
                // $queueStatus[1] holds the message count.
                $messageCount = $queueStatus[1];
                $this->logger->warning(
                    '[NacentoConnector] Uninstall: RabbitMQ queue "' . self::QUEUE_NAME . '" was NOT deleted because it contains ' .
                    $messageCount . ' pending message(s). Please purge the queue manually and run the uninstall command again if you want to remove it.'
                );
                return; // Abort cleanup
            }

            // Step 2: If we are here, the queue exists and is empty. Proceed with deletion.
            $channel->queue_delete(self::QUEUE_NAME);
            $this->logger->info('[NacentoConnector] Uninstall: RabbitMQ queue "' . self::QUEUE_NAME . '" was empty and has been deleted successfully.');
            
            $channel->exchange_delete(self::EXCHANGE_NAME);
            $this->logger->info('[NacentoConnector] Uninstall: RabbitMQ exchange "' . self::EXCHANGE_NAME . '" has been deleted successfully.');

        } catch (AMQPProtocolChannelException $e) {
            // This specific exception is often thrown if the queue doesn't exist (e.g., a 404 Not Found).
            // This is not an error in our case; it just means there's nothing to clean up.
            if ($e->getCode() === 404) {
                $this->logger->info('[NacentoConnector] Uninstall: RabbitMQ queue "' . self::QUEUE_NAME . '" did not exist. No action taken.');
            } else {
                 $this->logger->error(
                    '[NacentoConnector] Uninstall: A protocol error occurred while trying to clean up RabbitMQ. Error: ' . $e->getMessage()
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                '[NacentoConnector] Uninstall: A general error occurred while connecting to RabbitMQ. ' .
                'Please check your connection and clean up resources manually. Error: ' . $e->getMessage()
            );
        } finally {
            // Always ensure the channel is closed if it was opened.
            if ($channel && $channel->is_open()) {
                $channel->close();
            }
        }
    }
}