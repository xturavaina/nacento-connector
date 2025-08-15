<?php
declare(strict_types=1);

namespace Nacento\Connector\Model;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Magento\Framework\App\DeploymentConfig;
use Psr\Log\LoggerInterface;

/**
 * A specialized, lightweight S3 client for performing HEAD requests.
 * Its primary purpose is to efficiently retrieve an object's ETag without downloading the file body,
 * by lazily initializing from Magento's remote storage configuration.
 */
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
     * Returns the ETag for a key relative to the /media directory.
     * Example input: "catalog/product/a/b/img.jpg"
     *
     * @param string $relativeMediaPath The path of the file within the media directory.
     * @return string|null The normalized ETag or null on failure.
     */
    public function getEtag(string $relativeMediaPath): ?string
    {
        // Prepend the standard 'media/' prefix to construct the full S3 object key.
        $key = $this->mediaPrefix . ltrim($relativeMediaPath, '/');

        try {
            // Lazily initialize the S3 client and get the bucket name.
            $client = $this->getClient();
            $bucket = $this->getBucket();

            // Abort if the S3 client could not be configured (e.g., missing credentials).
            if (!$client || !$bucket) {
                $this->logger->debug('[NacentoConnector][S3Head] Client or bucket is not available. Aborting.');
                return null;
            }

            $this->logger->debug(sprintf(
                '[NacentoConnector][S3Head] HEAD start | bucket=%s key=%s',
                $bucket,
                $key
            ));

            // Perform the HEAD request.
            $res = $client->headObject([
                'Bucket' => $bucket,
                'Key'    => $key,
            ]);

            // Extract the ETag from the response headers.
            $etag = $res['ETag'] ?? null;

            $this->logger->debug(sprintf(
                '[NacentoConnector][S3Head] HEAD ok | ETag=%s LastModified=%s ContentLength=%s',
                is_string($etag) ? $etag : 'NULL',
                isset($res['LastModified']) ? (string)$res['LastModified'] : 'NULL',
                (string)($res['ContentLength'] ?? 'NULL')
            ));

            // Normalize the ETag by removing quotes and return it.
            return is_string($etag) ? trim($etag, '"') : null;

        } catch (AwsException $e) {
            // Log detailed error information from the AWS SDK.
            $this->logger->debug(sprintf(
                '[NacentoConnector][S3Head] HEAD AWS error | code=%s msg=%s status=%s requestId=%s',
                (string)$e->getAwsErrorCode(),
                $e->getAwsErrorMessage() ?: $e->getMessage(),
                (string)($e->getStatusCode() ?? 'NA'),
                (string)($e->getAwsRequestId() ?? 'NA')
            ));
        } catch (\Throwable $e) {
            // Catch any other generic exceptions.
            $this->logger->debug('[NacentoConnector][S3Head] headObject failed: ' . $e->getMessage());
        }

        return null;
    }

    /** ---------- Private Helper Methods ---------- */

    /**
     * Lazily creates and returns an S3Client instance based on env.php configuration.
     *
     * @return S3Client|null The configured client or null if configuration is invalid.
     */
    private function getClient(): ?S3Client
    {
        // Return the cached client if it has already been initialized.
        if ($this->client) {
            return $this->client;
        }

        [$driver, $conf] = $this->readRemoteStorageConfig();

        // Log diagnostic information about the loaded configuration.
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

        // Validate that the required configuration is present.
        if (($driver !== 'aws-s3') || empty($conf['endpoint']) || empty($conf['credentials']['key']) || empty($conf['credentials']['secret'])) {
            $this->logger->debug('[NacentoConnector][S3Head] Incomplete config in env.php (driver/endpoint/credentials).');
            return null;
        }

        // Instantiate the S3 client.
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

    /**
     * Lazily retrieves and caches the S3 bucket name from the configuration.
     *
     * @return string|null The bucket name or null if not configured.
     */
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
     * Reads and normalizes the 'remote_storage' configuration from env.php.
     * This handles variations in the configuration structure across different Magento versions.
     *
     * @return array An array containing [driverName, configArray].
     */
    private function readRemoteStorageConfig(): array
    {
        $rs = $this->deployConfig->get('remote_storage');
        $driver = is_array($rs) ? ($rs['driver'] ?? null) : null;

        // In newer versions (e.g., 2.4.x), the config is under a 'config' key.
        $conf = [];
        if (is_array($rs)) {
            if (isset($rs['config']) && is_array($rs['config'])) {
                $conf = $rs['config'];
            } elseif (isset($rs['aws_s3']) && is_array($rs['aws_s3'])) {
                // Fallback for older or custom setups.
                $conf = $rs['aws_s3'];
            }
        }

        // Ensure the 'credentials' key exists as an array to prevent access errors.
        if (!isset($conf['credentials']) || !is_array($conf['credentials'])) {
            $conf['credentials'] = [];
        }

        return [$driver, $conf];
    }
}