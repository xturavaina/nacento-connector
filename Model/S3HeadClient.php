<?php
declare(strict_types=1);

namespace Nacento\Connector\Model;

use Aws\S3\S3Client;
use Magento\Framework\App\DeploymentConfig;
use Psr\Log\LoggerInterface;

class S3HeadClient
{
    private DeploymentConfig $deployConfig;
    private LoggerInterface $logger;
    private ?S3Client $client = null;
    private ?string $bucket = null;
    private string $mediaPrefix = 'media/'; // Magento guarda sota "media/"

    public function __construct(
        DeploymentConfig $deployConfig,
        LoggerInterface $logger
    ) {
        $this->deployConfig = $deployConfig;
        $this->logger = $logger;
    }

    /**
     * Retorna l'ETag (sense cometes) per a una clau relativa a /media (p.ex. "catalog/product/a/b/img.jpg").
     */
    public function getEtag(string $relativeMediaPath): ?string
    {
        try {
            $client = $this->getClient();
            $bucket = $this->getBucket();
            if (!$client || !$bucket) {
                $this->logger->debug('[NacentoConnector][S3Head] client o bucket no disponibles.');
                return null;
            }

            $key = $this->mediaPrefix . ltrim($relativeMediaPath, '/');

            $res = $client->headObject([
                'Bucket' => $bucket,
                'Key'    => $key,
            ]);

            $etag = $res['ETag'] ?? null;
            return is_string($etag) ? trim($etag, '"') : null;

        } catch (\Throwable $e) {
            $this->logger->debug('[NacentoConnector][S3Head] headObject failed: ' . $e->getMessage());
            return null;
        }
    }

    private function getClient(): ?S3Client
    {
        if ($this->client) return $this->client;

        $endpoint = $this->cfg([
            'remote_storage/config/endpoint',
            'remote_storage/aws_s3/endpoint',
        ]);
        $region = (string)($this->cfg([
            'remote_storage/config/region',
            'remote_storage/aws_s3/region',
        ]) ?? 'auto');

        $accessKey = $this->cfg([
            'remote_storage/config/access_key',
            'remote_storage/aws_s3/access_key',
            'remote_storage/config/access_key_id',
        ]) ?? getenv('AWS_ACCESS_KEY_ID');

        $secretKey = $this->cfg([
            'remote_storage/config/secret_key',
            'remote_storage/aws_s3/secret_key',
        ]) ?? getenv('AWS_SECRET_ACCESS_KEY');

        $usePathStyle = (bool)($this->cfg([
            'remote_storage/config/use_path_style_endpoint',
            'remote_storage/aws_s3/use_path_style_endpoint',
        ]) ?? true);

        if (!$endpoint || !$accessKey || !$secretKey) {
            $this->logger->debug('[NacentoConnector][S3Head] Config S3/R2 incompleta a env.php');
            return null;
        }

        $this->client = new S3Client([
            'version'                 => 'latest',
            'region'                  => $region,
            'endpoint'                => $endpoint,
            'use_path_style_endpoint' => $usePathStyle,
            'credentials'             => [
                'key'    => (string)$accessKey,
                'secret' => (string)$secretKey,
            ],
            // No desactivis verify en prod; arregla el CA si cal
            // 'http' => ['verify' => false],
        ]);

        return $this->client;
    }

    private function getBucket(): ?string
    {
        if ($this->bucket !== null) return $this->bucket;

        $bucket = $this->cfg([
            'remote_storage/config/bucket',
            'remote_storage/aws_s3/bucket',
        ]);
        $this->bucket = $bucket ? (string)$bucket : null;
        return $this->bucket;
    }

    private function cfg(array $keys): mixed
    {
        foreach ($keys as $key) {
            $val = $this->deployConfig->get($key);
            if ($val !== null && $val !== '') return $val;
        }
        return null;
    }
}
