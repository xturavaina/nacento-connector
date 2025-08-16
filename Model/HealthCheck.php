<?php
declare(strict_types=1);

namespace Nacento\Connector\Model;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Module\ModuleListInterface;
use Psr\Log\LoggerInterface;

class HealthCheck
{
    /** Opcional: si el topic és fix i no configurable */
    public const DEFAULT_TOPIC = 'nacento.gallery.process';

    public function __construct(
        private DeploymentConfig    $deploymentConfig,
        private PublisherInterface  $publisher,
        private ResourceConnection  $resource,
        private ModuleListInterface $moduleList,
        private LoggerInterface     $logger,
        private \Nacento\Connector\Helper\Config $config // si no vols config d'admin, elimina-ho
    ) {}

    /**
     * Llença LocalizedException si alguna condició no es compleix.
     */
    public function assertReady(): void
    {
        $this->assertModuleEnabled();
        $this->assertDbReady();
        $this->assertRemoteStorageConfigured();
        $this->assertMqPublishable();

        // Opcional: prova de HEAD a un objecte “ping” de S3/R2
        $this->optionalS3HeadCheck();
    }

    private function assertModuleEnabled(): void
    {
        if (!$this->moduleList->has('Nacento_Connector')) {
            throw new LocalizedException(__('Nacento_Connector: module not enabled.'));
        }
    }

    private function assertDbReady(): void
    {
        $conn = $this->resource->getConnection();
        $table = $this->resource->getTableName('nacento_media_gallery_meta');
        $exists = (bool)$conn->fetchOne("SHOW TABLES LIKE ?", [$table]);

        if (!$exists) {
            throw new LocalizedException(__('Nacento_Connector: DB table %1 not found. Run setup:upgrade.', $table));
        }
    }

    private function assertRemoteStorageConfigured(): void
    {
        $driver = (string)($this->deploymentConfig->get('remote_storage/driver') ?? '');
        if ($driver !== 'aws-s3') {
            throw new LocalizedException(__('Nacento_Connector: remote storage driver must be aws-s3.'));
        }

        $cfg = (array)$this->deploymentConfig->get('remote_storage/config') ?: [];
        $bucket   = (string)($cfg['bucket']   ?? '');
        $endpoint = (string)($cfg['endpoint'] ?? '');

        if ($bucket === '' || $endpoint === '') {
            throw new LocalizedException(__('Nacento_Connector: remote storage not configured (bucket/endpoint).'));
        }
    }

    private function assertMqPublishable(): void
    {
        // Si tens el topic per config d'admin:
        $topic = $this->config->getMqTopic() ?: self::DEFAULT_TOPIC;
        if ($topic === '') {
            throw new LocalizedException(__('Nacento_Connector: MQ topic not defined.'));
        }

        // Intent de publicació lleuger per validar wiring del MQ.
        try {
            // Nota: el payload pot ser qualsevol valor serialitzable pel teu topic handler
            $this->publisher->publish($topic, ['type' => 'healthcheck', 'ts' => time()]);
        } catch (\Throwable $e) {
            $this->logger->error('[Nacento_Connector][MQ] publish failed: ' . $e->getMessage());
            throw new LocalizedException(__('Nacento_Connector: cannot publish to MQ topic (%1).', $topic));
        }
    }

    private function optionalS3HeadCheck(): void
    {
        // Opcional i segur: només si l’admin defineix un objecte “ping”
        $pingKey = $this->config->getS3PingObjectKey();
        if ($pingKey === '') {
            return;
        }

        try {
            // Reutilitza el client S3 si ja el tens al projecte. Exemple amb AWS SDK si està present:
            // Nota: depèn de magento/module-aws-s3 i aws/aws-sdk-php presents a l'instal·lar Magento.
            $cfg = (array)$this->deploymentConfig->get('remote_storage/config') ?: [];
            $client = new \Aws\S3\S3Client([
                'version' => 'latest',
                'region'  => $cfg['region']   ?? 'auto',
                'endpoint'=> $cfg['endpoint'] ?? null,
                'use_path_style_endpoint' => (bool)($cfg['use_path_style_endpoint'] ?? true),
                'credentials' => [
                    'key'    => $cfg['credentials']['key']    ?? '',
                    'secret' => $cfg['credentials']['secret'] ?? '',
                ],
                // Evita intents de signar serveis desconeguts
                'signature_version' => 'v4',
            ]);

            $client->headObject([
                'Bucket' => (string)$cfg['bucket'],
                'Key'    => $pingKey,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('[Nacento_Connector][S3 HEAD] failed: ' . $e->getMessage());
            throw new LocalizedException(__('Nacento_Connector: S3/R2 not reachable or ping object missing.'));
        }
    }
}
