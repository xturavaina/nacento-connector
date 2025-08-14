<?php
declare(strict_types=1);

namespace Nacento\Connector\Model;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Magento\Framework\App\DeploymentConfig;
use Psr\Log\LoggerInterface;

class S3HeadClient
{
    private DeploymentConfig $deployConfig;
    private LoggerInterface $logger;
    private ?S3Client $client = null;
    private ?string $bucket = null;
    private string $mediaPrefix = 'media/';

    public function __construct(
        DeploymentConfig $deployConfig,
        LoggerInterface $logger
    ) {
        $this->deployConfig = $deployConfig;
        $this->logger = $logger;
    }

    /**
     * Retorna l'ETag per a una clau relativa a /media
     * ex.: "catalog/product/a/b/img.jpg"
     */
    public function getEtag(string $relativeMediaPath): ?string
    {
        $key = $this->mediaPrefix . ltrim($relativeMediaPath, '/');

        try {
            $client = $this->getClient();
            $bucket = $this->getBucket();

            if (!$client || !$bucket) {
                $this->logger->debug('[NacentoConnector][S3Head] Client o bucket no disponibles. Abort.');
                return null;
            }

            $this->logger->debug(sprintf(
                '[NacentoConnector][S3Head] HEAD start | bucket=%s key=%s',
                $bucket,
                $key
            ));

            $res = $client->headObject([
                'Bucket' => $bucket,
                'Key'    => $key,
            ]);

            $etag = $res['ETag'] ?? null;

            $this->logger->debug(sprintf(
                '[NacentoConnector][S3Head] HEAD ok | ETag=%s LastModified=%s ContentLength=%s',
                is_string($etag) ? $etag : 'NULL',
                isset($res['LastModified']) ? (string)$res['LastModified'] : 'NULL',
                (string)($res['ContentLength'] ?? 'NULL')
            ));

            return is_string($etag) ? trim($etag, '"') : null;

        } catch (AwsException $e) {
            $this->logger->debug(sprintf(
                '[NacentoConnector][S3Head] HEAD AWS error | code=%s msg=%s status=%s requestId=%s',
                (string)$e->getAwsErrorCode(),
                $e->getAwsErrorMessage() ?: $e->getMessage(),
                (string)($e->getStatusCode() ?? 'NA'),
                (string)($e->getAwsRequestId() ?? 'NA')
            ));
        } catch (\Throwable $e) {
            $this->logger->debug('[NacentoConnector][S3Head] headObject failed: ' . $e->getMessage());
        }

        return null;
    }

    /** ---------- privats ---------- */

    private function getClient(): ?S3Client
    {
        if ($this->client) {
            return $this->client;
        }

        [$driver, $conf] = $this->readRemoteStorageConfig();

        // Logs de diagnòstic
        $this->logger->debug(sprintf(
            '[NacentoConnector][S3Head] Config | driver=%s endpoint=%s region=%s pathStyle=%s bucket=%s hasKey=%s hasSecret=%s',
            $driver ?? 'NULL',
            (string)($conf['endpoint'] ?? 'NULL'),
            (string)($conf['region'] ?? 'NULL'),
            !empty($conf['use_path_style_endpoint']) ? 'true' : 'false',
            (string)($conf['bucket'] ?? 'NULL'),
            isset($conf['credentials']['key']) ? 'yes' : 'no',
            isset($conf['credentials']['secret']) ? 'yes' : 'no'
        ));

        if (($driver !== 'aws-s3') || empty($conf['endpoint']) || empty($conf['credentials']['key']) || empty($conf['credentials']['secret'])) {
            $this->logger->debug('[NacentoConnector][S3Head] Config incompleta a env.php (driver/endpoint/credentials).');
            return null;
        }

        $this->client = new S3Client([
            'version'                 => 'latest',
            'region'                  => (string)($conf['region'] ?? 'auto'),
            'endpoint'                => (string)$conf['endpoint'],
            'use_path_style_endpoint' => (bool)($conf['use_path_style_endpoint'] ?? true),
            'credentials'             => [
                'key'    => (string)$conf['credentials']['key'],
                'secret' => (string)$conf['credentials']['secret'],
            ],
        ]);

        return $this->client;
    }

    private function getBucket(): ?string
    {
        if ($this->bucket !== null) {
            return $this->bucket;
        }
        [, $conf] = $this->readRemoteStorageConfig();
        $this->bucket = isset($conf['bucket']) ? (string)$conf['bucket'] : null;
        return $this->bucket;
    }

    /**
     * Llegeix i normalitza 'remote_storage' d'env.php
     * Retorna [driver, confArray]
     */
    private function readRemoteStorageConfig(): array
    {
        $rs = $this->deployConfig->get('remote_storage');
        $driver = is_array($rs) ? ($rs['driver'] ?? null) : null;

        // 2.4.8 → tot ve sota ['config'] i credencials sota ['credentials']
        $conf = [];
        if (is_array($rs)) {
            if (isset($rs['config']) && is_array($rs['config'])) {
                $conf = $rs['config'];
            } elseif (isset($rs['aws_s3']) && is_array($rs['aws_s3'])) {
                // fallback per setups antics
                $conf = $rs['aws_s3'];
            }
        }

        // Assegura estructura credentials
        if (!isset($conf['credentials']) || !is_array($conf['credentials'])) {
            $conf['credentials'] = [];
        }

        return [$driver, $conf];
    }
}
