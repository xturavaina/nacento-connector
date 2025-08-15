<?php
declare(strict_types=1);

namespace Nacento\Connector\Setup;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;


use Magento\Framework\Amqp\ConfigPool;
use Magento\Framework\Amqp\Config as AmqpConfig;
use Magento\Framework\Amqp\Connection\Factory as AmqpConnectionFactory;
use Magento\Framework\Amqp\Connection\FactoryOptions;


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
            $om = ObjectManager::getInstance();

            /** @var ConfigPool $configPool */
            $configPool = $om->get(ConfigPool::class);
            /** @var AmqpConnectionFactory $factory */
            $factory = $om->get(AmqpConnectionFactory::class);

            /** @var AmqpConfig $config */
            $config = $configPool->get('amqp');

            // Construïm FactoryOptions a partir de la Config
            $options = new FactoryOptions();
            $options->setHost((string)$config->getValue(AmqpConfig::HOST));
            $options->setPort((string)$config->getValue(AmqpConfig::PORT));
            $options->setUsername((string)$config->getValue(AmqpConfig::USERNAME));
            $options->setPassword((string)$config->getValue(AmqpConfig::PASSWORD));
            $options->setVirtualHost((string)$config->getValue(AmqpConfig::VIRTUALHOST) ?: '/');
            $options->setSslEnabled((bool)$config->getValue(AmqpConfig::SSL));
            $sslOptions = $config->getValue(AmqpConfig::SSL_OPTIONS);
            if (is_array($sslOptions)) {
                $options->setSslOptions($sslOptions);
            }

            $connection = $factory->create($options);
            $channel = $connection->channel();

            // Comprovació passiva de la cua (no la crea si no existeix)
            // Retorna [queueName, messageCount, consumerCount]
            $queueStatus = $channel->queue_declare(self::QUEUE_NAME, true);

            $messageCount = is_array($queueStatus) && isset($queueStatus[1]) ? (int)$queueStatus[1] : 0;
            if ($messageCount > 0) {
                $logger->warning(
                    '[NacentoConnector] Uninstall: RabbitMQ queue "' . self::QUEUE_NAME .
                    '" NO s\'ha esborrat perquè té ' . $messageCount . ' missatge(s) pendent(s).'
                );
                return;
            }

            // Esborra la cua (si existeix i està buida) i després l'exchange
            $channel->queue_delete(self::QUEUE_NAME);
            $logger->info('[NacentoConnector] Uninstall: Queue "' . self::QUEUE_NAME . '" esborrada.');

            $channel->exchange_delete(self::EXCHANGE_NAME);
            $logger->info('[NacentoConnector] Uninstall: Exchange "' . self::EXCHANGE_NAME . '" esborrat.');

        } catch (AMQPProtocolChannelException $e) {
            if ($e->getCode() === 404) {
                $logger->info('[NacentoConnector] Uninstall: Queue/exchange inexistent. Cap acció.');
            } else {
                $logger->error('[NacentoConnector] Uninstall: Error de protocol AMQP: ' . $e->getMessage());
            }
        } catch (\Throwable $e) {
            $logger->error('[NacentoConnector] Uninstall: Error general netejant RabbitMQ: ' . $e->getMessage());
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