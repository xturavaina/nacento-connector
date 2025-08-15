<?php
/**
 * Copyright © Nacento
 */
declare(strict_types=1);

namespace Nacento\Connector\Model;

use Nacento\Connector\Api\CustomGalleryManagementInterface;
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
class GalleryProcessor implements CustomGalleryManagementInterface
{
    private $productRepository;
    private $filesystem;
    private $logger;
    private $galleryResourceModel;
    private $productAttributeRepository;
    private $productAction;
    private $mediaConfig;
    private S3HeadClient $s3Head;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        Filesystem $filesystem,
        LoggerInterface $logger,
        CustomGalleryResourceModel $galleryResourceModel,
        ProductAttributeRepositoryInterface $productAttributeRepository,
        ProductAction $productAction,
        MediaConfig $mediaConfig,
        S3HeadClient $s3Head
    ) {
        $this->productRepository = $productRepository;
        $this->filesystem = $filesystem;
        $this->logger = $logger;
        $this->galleryResourceModel = $galleryResourceModel;
        $this->productAttributeRepository = $productAttributeRepository;
        $this->productAction = $productAction;
        $this->mediaConfig = $mediaConfig;
        $this->s3Head = $s3Head;
    }

    /**
     * Creates or updates gallery entries from PRE-EXISTING file paths within the /media directory,
     * and saves the S3 ETag to a custom metadata table.
     * {@inheritdoc}
     */
    public function create(string $sku, array $images): bool
    {
        $this->logger->info(sprintf(
            '[NacentoConnector] Starting bulk process (Direct DB) for SKU: %s. %d images received.',
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

            // --- STEP 2: LOOP THROUGH AND PROCESS EACH IMAGE ---
            foreach ($images as $imageEntry) {
                // Get and sanitize data from the input DTO.
                $filePath = ltrim($imageEntry->getFilePath() ?? '', '/\\');
                $label    = $imageEntry->getLabel() ?? '';
                $disabled = $imageEntry->isDisabled();
                $position = $imageEntry->getPosition();
                $roles    = $imageEntry->getRoles() ?? [];

                $this->logger->info(sprintf('[NacentoConnector] Processing image: %s', $filePath));

                // 2a. Validate that essential data is present.
                if (empty($filePath) || empty($label)) {
                    $this->logger->error('[NacentoConnector] Skipping image due to empty filePath or label.');
                    continue;
                }

                // 2b. Verify that the image file actually exists in the media directory.
                $fullPathForValidation = $this->mediaConfig->getMediaPath($filePath);
                if (!$mediaDirectory->isExist($fullPathForValidation)) {
                    $this->logger->error(sprintf(
                        '[NacentoConnector] Skipping image. File does not exist at: %s',
                        $fullPathForValidation
                    ));
                    continue;
                }
                $this->logger->info(sprintf(
                    '[NacentoConnector] SUCCESS: File %s was found. Now getting ETag (HEAD S3/R2).',
                    $filePath
                ));

                // --- Local utility to normalize the ETag (remove quotes) ---
                $norm = static function ($e) {
                    return $e !== null ? trim((string)$e, '"') : null;
                };

                // Get the current ETag directly via a HEAD request to S3/R2 (a single call).
                $currentEtagNorm = null;

                if ($mediaDriver instanceof \Magento\AwsS3\Driver\AwsS3) {
                    // Get the relative media path (e.g., "catalog/product/a/b/img.jpg").
                    $relative = $this->mediaConfig->getMediaPath($filePath);
                    // A single HEAD call to R2/S3.
                    $etag = $this->s3Head->getEtag($relative);
                    $currentEtagNorm = $etag ? $norm($etag) : null;
                }

                // 2c. Decide whether to INSERT or UPDATE by checking if the image is already in the gallery.
                $existingImage = $this->galleryResourceModel->getExistingImage(
                    (int)$product->getId(),
                    (int)$galleryAttribute->getAttributeId(),
                    $filePath
                );

                // The ETag stored in our metadata table, if the image already existed.
                $savedEtagNorm = isset($existingImage['s3_etag']) ? $norm($existingImage['s3_etag']) : null;

                // Prepare the data for the CORE gallery table, common to both INSERT and UPDATE.
                // Note: This does NOT include s3_etag. Store ID is currently hardcoded.
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
                    $this->logger->info(sprintf(
                        '[NacentoConnector] Image %s already exists. Updating record_id: %d',
                        $filePath,
                        $recordId
                    ));

                    // Log if the content has changed based on the ETag.
                    if ($currentEtagNorm !== $savedEtagNorm) {
                        $this->logger->info(sprintf(
                            '[NacentoConnector] Content change detected for %s (ETag %s → %s)',
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
                    $this->logger->info(sprintf(
                        '[NacentoConnector] Image %s is new. Inserting into the database.',
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

                    $this->logger->info(sprintf(
                        '[NacentoConnector] Image registered in DB with value_id: %d and record_id: %d',
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
                $this->logger->info('[NacentoConnector] Assigning/updating all roles with a single ProductAction call...');
                $this->productAction->updateAttributes([(int)$product->getId()], $rolesToUpdate, 0);
            }

            // --- STEP 4: CACHE CLEANING ---
            // Cache invalidation is handled by ProductAction, so manual cleaning is not strictly necessary
            // and can sometimes cause issues. A try-catch here prevents a process failure.
            $this->logger->info('[NacentoConnector] ★★★ BULK PROCESS (Direct DB + Action) COMPLETED SUCCESSFULLY ★★★');

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