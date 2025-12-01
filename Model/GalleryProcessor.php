<?php

/**
 * Copyright © Nacento
 */

declare(strict_types=1);

namespace Nacento\Connector\Model;


use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Nacento\Connector\Model\ResourceModel\Product\Gallery as CustomGalleryResourceModel;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Filesystem;
use Psr\Log\LoggerInterface;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Catalog\Model\Product\Action as ProductAction;
use Nacento\Connector\Model\S3HeadClient;

/**
 * The core service responsible for processing and persisting product gallery updates.
 * This class acts as the "executing arm" for gallery management.
 */
class GalleryProcessor
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger,
        private readonly CustomGalleryResourceModel $galleryResourceModel,
        private readonly ProductAttributeRepositoryInterface $productAttributeRepository,
        private readonly ProductAction $productAction,
        private readonly MediaConfig $mediaConfig,
        private readonly S3HeadClient $s3Head
    ) {}

    /**
     * Creates or updates gallery entries from PRE-EXISTING file paths within the /media directory,
     * and saves the S3 ETag to a custom metadata table.
     * {@inheritdoc}
     */
    public function create(string $sku, array $images): bool
    {
        $this->logger->debug(sprintf(
            '[NacentoConnector] Starting process for SKU: %s. %d images received.',
            $sku,
            count($images)
        ));

        // Early exit if there are no images to process.
        if (empty($images)) {
            $this->logger->warning('[NacentoConnector] The images array is empty. Nothing to do.');
            return true;
        }

        try {
            // --- STEP 1: INITIAL VERIFICATIONS (performed outside the loop for efficiency) ---
            $product          = $this->productRepository->get($sku);
            $galleryAttribute = $this->productAttributeRepository->get('media_gallery');
            $mediaDirectory   = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
            $rolesToUpdate    = [];

            // --- GET THE FILESYSTEM DRIVER (to check if we are on S3) ---
            $mediaDirectoryWriter = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
            /** @var \Magento\Framework\Filesystem\DriverInterface|\Magento\AwsS3\Driver\AwsS3 $mediaDriver */
            $mediaDriver = $mediaDirectoryWriter->getDriver();
            $isS3 = $mediaDriver instanceof \Magento\AwsS3\Driver\AwsS3;

            // --- Local utility to normalize the ETag (remove quotes) ---
            $norm = static function ($e) {
                return $e !== null ? trim((string)$e, '\"') : null;
            };

            // --- STEP 2: COLLECT AND VALIDATE ALL FILE PATHS ---
            $validImages = [];
            $filePaths = [];

            foreach ($images as $imageEntry) {
                $filePath = ltrim($imageEntry->getFilePath() ?? '', '/\\');
                $label    = $imageEntry->getLabel() ?? '';

                // Validate essential data
                if (empty($filePath) || empty($label)) {
                    $this->logger->error('[NacentoConnector] Skipping image due to empty filePath or label.');
                    continue;
                }

                // Verify file exists
                $fullPathForValidation = $this->mediaConfig->getMediaPath($filePath);
                if (!$mediaDirectory->isExist($fullPathForValidation)) {
                    $this->logger->error(sprintf(
                        '[NacentoConnector] Skipping image. File does not exist at: %s',
                        $fullPathForValidation
                    ));
                    continue;
                }

                $validImages[$filePath] = $imageEntry;
                $filePaths[] = $filePath;
            }

            if (empty($validImages)) {
                $this->logger->warning('[NacentoConnector] No valid images to process after validation.');
                return true;
            }

            // --- STEP 3: BATCH FETCH EXISTING IMAGES (ONE QUERY) ---
            $existingImages = $this->galleryResourceModel->getExistingImages(
                (int)$product->getId(),
                (int)$galleryAttribute->getAttributeId(),
                $filePaths
            );

            // --- STEP 4: PROCESS EACH IMAGE ---
            foreach ($validImages as $filePath => $imageEntry) {
                $label    = $imageEntry->getLabel() ?? '';
                $disabled = $imageEntry->isDisabled();
                $position = $imageEntry->getPosition();
                $roles    = $imageEntry->getRoles() ?? [];

                $this->logger->debug(sprintf('[NacentoConnector] Processing image: %s', $filePath));

                // Get current ETag from S3 if applicable
                $currentEtagNorm = null;
                if ($isS3) {
                    $relative = $this->mediaConfig->getMediaPath($filePath);
                    $etag = $this->s3Head->getEtag($relative);
                    $currentEtagNorm = $etag ? $norm($etag) : null;
                }

                // Check if image already exists (from batch query)
                $existingImage = $existingImages[$filePath] ?? null;
                $savedEtagNorm = isset($existingImage['s3_etag']) ? $norm($existingImage['s3_etag']) : null;

                // Prepare common value data
                $valueData = [
                    'entity_id' => (int)$product->getId(),
                    'label'     => $label,
                    'position'  => $position,
                    'disabled'  => (int)$disabled,
                    'store_id'  => 0,
                ];

                if ($existingImage && isset($existingImage['record_id'])) {
                    // --- CASE A: IMAGE EXISTS -> UPDATE (core table) + UPSERT ETag (meta table) ---
                    $recordId = (int)$existingImage['record_id'];
                    $this->logger->debug(sprintf(
                        '[NacentoConnector] Updating existing image %s (record_id: %d)',
                        $filePath,
                        $recordId
                    ));

                    // Log if the content has changed based on the ETag.
                    if ($currentEtagNorm !== $savedEtagNorm) {
                        $this->logger->debug(sprintf(
                            '[NacentoConnector] Content changed: %s (ETag %s → %s)',
                            $filePath,
                            (string)$savedEtagNorm,
                            (string)$currentEtagNorm
                        ));
                    }

                    // Perform the UPDATE on the core gallery value table (label/position/disabled).
                    $this->galleryResourceModel->updateValueRecord($recordId, $valueData);
                    // Perform an UPSERT for the ETag in our custom metadata table.
                    $this->galleryResourceModel->saveMetaRecord($recordId, $currentEtagNorm);
                } else {
                    // --- CASE B: IMAGE IS NEW -> INSERT (core tables) + UPSERT ETag (meta table) ---
                    $this->logger->debug(sprintf(
                        '[NacentoConnector] Inserting new image: %s',
                        $filePath
                    ));

                    // If the main gallery entry (in `main_table`) doesn't exist, create and link it.
                    $valueIdToUse = $existingImage['value_id'] ?? null;
                    if (!$valueIdToUse) {
                        $newImageData = [
                            'attribute_id' => (int)$galleryAttribute->getAttributeId(),
                            'media_type'   => 'image',
                            'value'        => $filePath
                        ];
                        $valueIdToUse = (int)$this->galleryResourceModel->insertNewRecord($newImageData);
                        $this->galleryResourceModel->createLink($valueIdToUse, (int)$product->getId());
                    }

                    // Insert the value row (for store_id 0). It's crucial that this method returns the new `record_id`.
                    $valueData['value_id'] = $valueIdToUse;
                    $recordId = (int)$this->galleryResourceModel->insertValueRecord($valueData);
                    // Perform an UPSERT for the ETag in our custom metadata table.
                    $this->galleryResourceModel->saveMetaRecord($recordId, $currentEtagNorm);

                    $this->logger->debug(sprintf(
                        '[NacentoConnector] Image registered (value_id: %d, record_id: %d)',
                        $valueIdToUse,
                        $recordId
                    ));
                }

                // 2d. Accumulate all image roles to be updated in a single call later.
                foreach ($roles as $role) {
                    if (!empty($role)) {
                        $rolesToUpdate[$role] = $filePath;
                    }
                }
            }

            // --- STEP 3: ROLE MANAGEMENT (A single call at the end for performance) ---
            if (!empty($rolesToUpdate)) {
                $this->logger->debug('[NacentoConnector] Updating image roles');
                $this->productAction->updateAttributes([(int)$product->getId()], $rolesToUpdate, 0);
            }

            // --- STEP 4: CACHE CLEANING ---
            // Cache invalidation is handled by ProductAction, so manual cleaning is not strictly necessary
            // and can sometimes cause issues. A try-catch here prevents a process failure.
            $this->logger->info(sprintf('[NacentoConnector] Gallery update completed for SKU: %s', $sku));
        } catch (\Exception $e) {
            $this->logger->critical(
                '[NacentoConnector] Critical exception during bulk process: ' . $e->getMessage(),
                ['exception' => $e]
            );
            throw new CouldNotSaveException(__("A critical error occurred during the bulk process. Please review the logs."), $e);
        }

        return true;
    }
}
