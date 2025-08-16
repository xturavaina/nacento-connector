<?php
declare(strict_types=1);

namespace Nacento\Connector\Model;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Module\ModuleListInterface;
use Psr\Log\LoggerInterface;

use Magento\AsynchronousOperations\Api\Data\OperationInterface;
use Magento\AsynchronousOperations\Api\Data\OperationInterfaceFactory;
use Magento\Framework\Serialize\Serializer\Json;

use Magento\Framework\MessageQueue\Publisher\ConfigInterface as PublisherConfig;
use Magento\Framework\MessageQueue\Consumer\ConfigInterface as ConsumerConfig;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;


class HealthCheck
{
    /** per defecte definim el topic, es pot configurar al backend */
    public const DEFAULT_TOPIC = 'nacento.gallery.process';

    public function __construct(
        private DeploymentConfig $deploymentConfig,
        private PublisherInterface $publisher,
        private ResourceConnection $resource,
        private ModuleListInterface $moduleList,
        private LoggerInterface $logger,
        private \Nacento\Connector\Helper\Config $config,
        private OperationInterfaceFactory $operationFactory,
        private Json $serializer
    ) {}

    /**
     * Llença LocalizedException si alguna condició no es compleix.
     */
    public function assertReady(): void {
        $this->assertModuleEnabled();
        $this->assertDbReady();
        $this->assertRemoteStorageConfigured();
        $this->assertMqPublishable();

        // Opcional: prova de HEAD a un objecte “ping” de S3/R2
        $this->optionalS3HeadCheck();
    }

    private function assertModuleEnabled(): void {
        if (!$this->moduleList->has('Nacento_Connector')) {
            throw new LocalizedException(__('Nacento_Connector: module not enabled.'));
        }
    }

    private function assertDbReady(): void {
        $conn = $this->resource->getConnection();
        $table = $this->resource->getTableName('nacento_media_gallery_meta');
        $exists = (bool)$conn->fetchOne("SHOW TABLES LIKE ?", [$table]);

        if (!$exists) {
            throw new LocalizedException(__('Nacento_Connector: DB table %1 not found. Run setup:upgrade.', $table));
        }
    }

    private function assertRemoteStorageConfigured(): void {
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

    private function assertMqPublishable(): void {
        // 0) opcional: alerta si falta l’extensió PHP amqp
        if (!extension_loaded('amqp')) {
            throw new LocalizedException(__('Nacento_Connector: PHP extension "amqp" not loaded.'));
        }

        $topic = $this->config->getMqTopic() ?: self::DEFAULT_TOPIC;

        // 1) crea una Operation “mínima” per complir el tipus del topic
        $op = $this->operationFactory->create();
        $op->setBulkUuid('healthcheck');
        $op->setTopicName($topic);
        $op->setStatus(OperationInterface::STATUS_TYPE_OPEN);
        $op->setSerializedData($this->serializer->serialize([
            'type' => 'healthcheck',
            'ts'   => time(),
        ]));

        try {
            $this->publisher->publish($topic, $op);
        } catch (\Throwable $e) {
            throw new LocalizedException(__(
                'Nacento_Connector: cannot publish to MQ topic (%1). Reason: %2',
                $topic,
                $e->getMessage()
            ));
        }
    }


    private function optionalS3HeadCheck(): void {
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

    private function mask(?string $v): ?string {
        
    if ($v === null || $v === '') return $v;
    $len = strlen($v);
    if ($len <= 4) return str_repeat('*', $len);
    return substr($v, 0, 2) . str_repeat('*', max(0, $len-4)) . substr($v, -2);
    }

    /**
     * Executa totes les proves i retorna un informe detallat.
     */
    public function run(bool $doPublish, PublisherConfig $publisherConfig, ConsumerConfig $consumerConfig, OperationInterfaceFactory $opFactory, JsonSerializer $json): HealthReport
    {
        $r = new HealthReport();

        // ---------- ENV SNAPSHOT ----------
        $driver = (string)($this->deploymentConfig->get('remote_storage/driver') ?? '');
        $s3cfg  = (array)($this->deploymentConfig->get('remote_storage/config') ?? []);
        $amqp   = (array)($this->deploymentConfig->get('queue/amqp') ?? []);
        $topic  = $this->config->getMqTopic() ?: self::DEFAULT_TOPIC;

        $r->addEnv('module', 'Nacento_Connector');
        $r->addEnv('remote_storage.driver', $driver);
        $r->addEnv('remote_storage.bucket', $s3cfg['bucket'] ?? null);
        $r->addEnv('remote_storage.endpoint', $s3cfg['endpoint'] ?? null);
        $r->addEnv('amqp.host', $amqp['host'] ?? null);
        $r->addEnv('amqp.port', $amqp['port'] ?? null);
        $r->addEnv('amqp.user', $this->mask($amqp['user'] ?? null));
        $r->addEnv('amqp.vhost', $amqp['virtualhost'] ?? null);
        $r->addEnv('topic', $topic);

        // ---------- CHECKS ----------
        // 1) Module enabled
        $t0 = microtime(true);
        $ok = $this->moduleList->has('Nacento_Connector');
        $r->addCheck('module_enabled', $ok ? 'ok' : 'fail', (microtime(true)-$t0)*1000, []);
        if (!$ok) return $r;

        // 2) DB table exists
        $t0 = microtime(true);
        try {
            $conn  = $this->resource->getConnection();
            $table = $this->resource->getTableName('nacento_media_gallery_meta');
            $exists = $conn->isTableExists($table);
            $r->addCheck('db_table', $exists ? 'ok' : 'fail', (microtime(true)-$t0)*1000, ['table'=>$table]);
            if (!$exists) return $r;
        } catch (\Throwable $e) {
            $r->addCheck('db_table', 'fail', (microtime(true)-$t0)*1000, [], $e->getMessage());
            return $r;
        }

        // 3) Remote storage config
        $t0 = microtime(true);
        $status = ($driver === 'aws-s3' && !empty($s3cfg['bucket']) && !empty($s3cfg['endpoint'])) ? 'ok' : 'fail';
        $r->addCheck('remote_storage_config', $status, (microtime(true)-$t0)*1000, [
            'driver' => $driver,
            'bucket' => $s3cfg['bucket'] ?? null,
            'endpoint' => $s3cfg['endpoint'] ?? null,
        ]);
        if ($status !== 'ok') return $r;

        // 4) Optional S3 HEAD
        $pingKey = $this->config->getS3PingObjectKey();
        if ($pingKey !== '') {
            $t0 = microtime(true);
            if (!class_exists(\Aws\S3\S3Client::class)) {
                $r->addCheck('s3_head', 'skipped', (microtime(true)-$t0)*1000, ['reason'=>'aws sdk not present']);
            } else {
                try {
                    $client = new \Aws\S3\S3Client([
                        'version'=>'latest',
                        'region'=>$s3cfg['region'] ?? 'auto',
                        'endpoint'=>$s3cfg['endpoint'] ?? null,
                        'use_path_style_endpoint'=>(bool)($s3cfg['use_path_style_endpoint'] ?? true),
                        'credentials'=>[
                            'key'=>$s3cfg['credentials']['key'] ?? '',
                            'secret'=>$s3cfg['credentials']['secret'] ?? '',
                        ],
                        'signature_version'=>'v4',
                    ]);
                    $res = $client->headObject(['Bucket'=>(string)$s3cfg['bucket'], 'Key'=>$pingKey]);
                    $r->addCheck('s3_head', 'ok', (microtime(true)-$t0)*1000, [
                        'key'=>$pingKey,
                        'etag'=>$res['ETag'] ?? null,
                        'statusCode'=>$res['@metadata']['statusCode'] ?? null,
                    ]);
                } catch (\Throwable $e) {
                    $r->addCheck('s3_head', 'fail', (microtime(true)-$t0)*1000, ['key'=>$pingKey], $e->getMessage());
                    return $r;
                }
            }
        }

        // 5) AMQP config presence
        $t0 = microtime(true);
        $haveAmqp = !empty($amqp['host']) && !empty($amqp['port']) && !empty($amqp['user']);
        $r->addCheck('amqp_config', $haveAmqp ? 'ok' : 'fail', (microtime(true)-$t0)*1000, [
            'host'=>$amqp['host'] ?? null,
            'port'=>$amqp['port'] ?? null,
            'user'=>$this->mask($amqp['user'] ?? null),
            'vhost'=>$amqp['virtualhost'] ?? null,
        ]);
        if (!$haveAmqp) return $r;

        // 6) Topic mapping
        $t0 = microtime(true);
        try {
            $pub = $publisherConfig->getPublisher($topic); // objecte PublisherConfigItem

            // extreu un nom "humà" de la connexió
            $connection = null;
            if (method_exists($pub, 'getConnection')) {
                $conn = $pub->getConnection();
                if (is_string($conn)) {
                    $connection = $conn;
                } elseif (is_object($conn)) {
                    $connection =
                        (method_exists($conn, 'getName') ? $conn->getName() :
                        (method_exists($conn, 'getConnectionName') ? $conn->getConnectionName() :
                        get_class($conn)));
                }
            }

            $topicName = method_exists($pub, 'getTopic') ? $pub->getTopic() : $topic;

            $r->addCheck('topic_mapping', 'ok', (microtime(true)-$t0)*1000, [
                'connection' => $connection,
                'topic' => $topicName,
            ]);
        } catch (\Throwable $e) {
            $r->addCheck('topic_mapping', 'fail', (microtime(true)-$t0)*1000, [], $e->getMessage());
            return $r;
        }


        // 7) Consumer presence (config)
        $t0 = microtime(true);
        try {
            $consumers = [];
            $match = null;

            foreach ($consumerConfig->getConsumers() as $item) { // Iterator de ConsumerConfigItem
                $name  = method_exists($item, 'getName') ? $item->getName() : null;
                $queue = null;
                if (method_exists($item, 'getQueue')) {
                    $queue = $item->getQueue();
                } elseif (method_exists($item, 'getQueueName')) {
                    $queue = $item->getQueueName();
                }

                $consumers[] = ['name' => $name, 'queue' => $queue];

                // ✅ Considera "present" si el consumer escolta la mateixa cua que el nostre topic
                if ($queue === $topic || $name === $topic) {
                    $match = ['name' => $name, 'queue' => $queue];
                }
            }

            $r->addCheck(
                'consumer_config',
                $match ? 'ok' : 'skipped',
                (microtime(true) - $t0) * 1000,
                [
                    'matched' => $match,
                    'note' => 'consumer not required to publish',
                    // Si vols, comenta la línia següent per no fer-ho tan verbós
                    'registered_consumers' => $consumers,
                ]
            );
        } catch (\Throwable $e) {
            $r->addCheck('consumer_config', 'skipped', (microtime(true)-$t0)*1000, [], $e->getMessage());
        }



        // 8) Publish test (OperationInterface) — optional
        $t0 = microtime(true);
        if (!$doPublish) {
            $r->addCheck('mq_publish', 'skipped', (microtime(true)-$t0)*1000, ['reason'=>'--no-publish']);
            return $r;
        }

        try {
            /** @var OperationInterface $op */
            $op = $opFactory->create();
            $op->setBulkUuid('healthcheck');
            $op->setTopicName($topic);
            $op->setStatus(OperationInterface::STATUS_TYPE_OPEN);
            $op->setSerializedData($json->serialize(['type'=>'healthcheck','ts'=>time()]));
            $this->publisher->publish($topic, $op);
            $r->addCheck('mq_publish', 'ok', (microtime(true)-$t0)*1000, [
                'payload' => ['bulk_uuid'=>'healthcheck','topic'=>$topic,'status'=>'OPEN']
            ]);
        } catch (\Throwable $e) {
            $r->addCheck('mq_publish', 'fail', (microtime(true)-$t0)*1000, [], $e->getMessage());
        }

        return $r;
    }



}
