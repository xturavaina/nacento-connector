<?php
declare(strict_types=1);

namespace Nacento\Connector\Setup;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;

// Note: We do not use constructor injection here because this script runs
// in a limited 'setup' context where not all services are available via DI.
class Uninstall implements UninstallInterface
{
    private const QUEUE_NAME = 'nacento.gallery.process';
    private const EXCHANGE_NAME = 'nacento.gallery.process';

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
            $this->getLogger()->info('[NacentoConnector] Uninstall: Database table ' . $tableName . ' dropped successfully.');
        }
    }

    private function cleanupRabbitMq(): void
    {
        $logger = $this->getLogger();
        $channel = null;

        try {
            // We get the ConnectionPool via the ObjectManager, which is the correct
            // practice inside Setup scripts.
            $objectManager = ObjectManager::getInstance();
            /** @var \Magento\Framework\Amqp\ConnectionPool $connectionPool */
            $connectionPool = $objectManager->get(\Magento\Framework\Amqp\ConnectionPool::class);
            
            $connection = $connectionPool->getConnection('amqp');
            $channel = $connection->channel();
            
            // Step 1: Passively check the queue status
            $queueStatus = $channel->queue_declare(self::QUEUE_NAME, true);
            
            if (isset($queueStatus[1]) && $queueStatus[1] > 0) {
                $messageCount = $queueStatus[1];
                $logger->warning(
                    '[NacentoConnector] Uninstall: RabbitMQ queue "' . self::QUEUE_NAME . '" was NOT deleted because it contains ' .
                    $messageCount . ' pending message(s). Please purge the queue manually and run uninstall again.'
                );
                return;
            }

            // Step 2: If the queue is empty, delete it
            $channel->queue_delete(self::QUEUE_NAME);
            $logger->info('[NacentoConnector] Uninstall: RabbitMQ queue "' . self::QUEUE_NAME . '" was empty and has been deleted.');
            
            $channel->exchange_delete(self::EXCHANGE_NAME);
            $logger->info('[NacentoConnector] Uninstall: RabbitMQ exchange "' . self::EXCHANGE_NAME . '" has been deleted.');

        } catch (AMQPProtocolChannelException $e) {
            if ($e->getCode() === 404) {
                $logger->info('[NacentoConnector] Uninstall: RabbitMQ queue "' . self::QUEUE_NAME . '" did not exist. No action taken.');
            } else {
                 $logger->error(
                    '[NacentoConnector] Uninstall: A protocol error occurred while cleaning up RabbitMQ. Error: ' . $e->getMessage()
                );
            }
        } catch (\Throwable $e) {
            $logger->error(
                '[NacentoConnector] Uninstall: A general error occurred while connecting to RabbitMQ. ' .
                'Please clean up resources manually. Error: ' . $e->getMessage()
            );
        } finally {
            if ($channel && $channel->is_open()) {
                $channel->close();
            }
        }
    }

    /**
     * Helper to get the logger instance via ObjectManager.
     * @return \Psr\Log\LoggerInterface
     */
    private function getLogger(): \Psr\Log\LoggerInterface
    {
        return ObjectManager::getInstance()->get(\Psr\Log\LoggerInterface::class);
    }
}