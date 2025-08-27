<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\ResourceModel\Product;

use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Nacento\Connector\Model\ResourceModel\Product\Gallery as GalleryRm;
use Psr\Log\LoggerInterface;
use Nacento\Connector\Model\S3HeadClient;

/**
 * Gallery processor for a single SKU with "Replace per SKU" strategy.
 * This class is called by the consumer to perform the actual work for one operation.
 *
 * - Operates on a single SKU at a time.
 * - Uses a dedicated database transaction for the operation.
 * - Throws exceptions on failure, which are caught by the consumer.
 */
class AsyncBulkGalleryProductProcessor
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Filesystem $filesystem,
        private readonly MediaConfig $mediaConfig,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductAttributeRepositoryInterface $productAttrRepo,
        private readonly GalleryRm $galleryRm,
        private readonly LoggerInterface $logger,
        private readonly S3HeadClient $s3Head
    ) {}

    /**
     * Processes the gallery for a single SKU. This is the entry point called by the consumer.
     *
     * @param string $sku The SKU to process.
     * @param array $images The gallery image data for this SKU.
     * @param int $storeId The store ID context.
     * @return array The result of the operation for this SKU.
     * @throws LocalizedException If the product is not found or if processing fails.
     */
    public function processSku(string $sku, array $images, int $storeId = 0): array
    {
        $this->logger->info('[NacentoGallery][BulkAsync][SKU_START]', [
            'sku' => $sku,
            'image_count' => count($images),
            'storeId' => $storeId,
            'strategy' => 'replace_per_sku'
        ]);

        // 1. Get prerequisite data for this single SKU
        $entityIds = $this->fetchEntityIdsBySkus([$sku]);
        $entityId = $entityIds[$sku] ?? null;

        if (!$entityId) {
            throw new LocalizedException(__('Product with SKU "%1" not found.', $sku));
        }

        $mediaGalleryAttrId = (int)$this->productAttrRepo->get('media_gallery')->getAttributeId();
        $storageDriver = $this->getStorageDriver();

        // 2. Execute the core processing logic for this SKU
        // The processSingleSku method already handles transactions and throws exceptions on failure.
        $result = $this->processSingleSku(
            $sku,
            $images,
            $entityId,
            $mediaGalleryAttrId,
            $storageDriver,
            $storeId
        );

        $this->logger->info('[NacentoGallery][BulkAsync][SKU_SUCCESS]', [
            'sku' => $sku,
            'result' => $result,
        ]);

        // 3. Return a result that the consumer can use
        return [
            'images_processed' => ($result['images_created'] ?? 0) + ($result['images_updated'] ?? 0),
            'details' => $result,
        ];
    }
    
    // The `processBatch` method has been completely removed as it is no longer needed.

    /**
     * process single SKU with individual transaction (Replace strategy)
     * This is now a private helper method, containing the core business logic.
     */
    private function processSingleSku(
        string $sku,
        array $images,
        int $entityId,
        int $mediaGalleryAttrId,
        $storageDriver,
        int $storeId
    ): array {
        $this->logger->debug('[NacentoGallery][BulkAsync][SKU_TRANSACTION_START]', [
            'sku' => $sku,
            'entityId' => $entityId,
            'image_count' => count($images)
        ]);

        $conn = $this->resource->getConnection();
        $conn->beginTransaction();
        
        try {
            // Delete all existing gallery data for this SKU
            $this->deleteExistingGalleryData($entityId, $mediaGalleryAttrId, $storeId);
            
            // Insert new gallery data
            $insertResult = $this->insertNewGalleryData(
                $sku,
                $entityId,
                $images,
                $mediaGalleryAttrId,
                $storageDriver,
                $storeId
            );
            
            // Update product roles (image, small_image, etc.)
            $this->updateProductRoles($entityId, $images, $storeId);
            
            $conn->commit();
            
            return [
                'images_created' => $insertResult['created'],
                'images_updated' => $insertResult['updated']
            ];
            
        } catch (\Throwable $e) {
            $conn->rollBack();
            // Re-throw the exception so the consumer knows the operation failed.
            throw new LocalizedException(
                __('Failed to process SKU %1: %2', $sku, $e->getMessage()),
                $e,
                $e->getCode()
            );
        }
    }

    /**
     * elimina totes les dades de galeria d'un producte.
     * ORDRE:
     *  1) record_id (per a meta) -> delete meta
     *  2) delete ..._value (scopat per entity_id)
     *  3) delete ..._value_to_entity (scopat per entity_id)
     *  4) neteja orfes a ..._media_gallery (només els value_id sense cap value)
     */
    private function deleteExistingGalleryData(int $entityId, int $mediaGalleryAttrId, ?int $storeId = null): void
    {
        $conn         = $this->resource->getConnection();
        $mgTable      = $this->resource->getTableName('catalog_product_entity_media_gallery');
        $mgValueTable = $this->resource->getTableName('catalog_product_entity_media_gallery_value');
        $mgLinkTable  = $this->resource->getTableName('catalog_product_entity_media_gallery_value_to_entity');
        $metaTable    = $this->resource->getTableName('nacento_media_gallery_meta');

        // 0) value_ids vinculats a AQUEST producte
        $valueIds = $conn->fetchCol(
            $conn->select()
                ->from($mgLinkTable, 'value_id')
                ->where('entity_id = ?', $entityId)
        );
        if (!$valueIds) {
            return;
        }

        // 1) PRE: llegeix record_id per poder esborrar meta (ABANS d'esborrar ..._value)
        if ($conn->isTableExists($metaTable)) {
            $select = $conn->select()
                ->from($mgValueTable, 'record_id')
                ->where('entity_id = ?', $entityId)
                ->where('value_id IN (?)', $valueIds);
            if ($storeId !== null) {
                $select->where('store_id = ?', $storeId);
            }
            $recordIds = $conn->fetchCol($select);
            if ($recordIds) {
                $conn->delete($metaTable, ['record_id IN (?)' => $recordIds]);
            }
        }

        // 2) DELETE de ..._value (ACOTAT per entity_id i, si cal, per store_id)
        $whereValue = [
            'value_id IN (?)' => $valueIds,
            'entity_id = ?'   => $entityId,
        ];
        if ($storeId !== null) {
            $whereValue['store_id = ?'] = $storeId;
        }
        $conn->delete($mgValueTable, $whereValue);

        // 3) DELETE de links ..._value_to_entity per aquest producte
        $conn->delete($mgLinkTable, [
            'entity_id = ?'   => $entityId,
            'value_id IN (?)' => $valueIds,
        ]);

        // 4) Neteja orfes de ..._media_gallery (value_id que ja no tenen cap fila a ..._value)
        // --- START OF FIX ---
        $select = $conn->select()
            ->from($mgValueTable, ['value_id']) // Specify column normally
            ->where('value_id IN (?)', $valueIds)
            ->distinct(true); // Use the distinct() method
        
        $remaining = $conn->fetchCol($select);
        // --- END OF FIX ---

        $orphans = array_diff(array_map('intval', $valueIds), array_map('intval', $remaining));
        if ($orphans) {
            $conn->delete($mgTable, [
                'attribute_id = ?' => $mediaGalleryAttrId,
                'value_id IN (?)'  => $orphans,
            ]);
        }

        // (opcional) Esborra valors de rols de la imatge (image, small_image, ...)
        $imageAttrIds = $this->getAttributeIds(['image', 'small_image', 'thumbnail', 'swatch_image']);
        if ($imageAttrIds) {
            $conn->delete(
                $this->resource->getTableName('catalog_product_entity_varchar'),
                [
                    'entity_id = ?'      => $entityId,
                    'attribute_id IN (?)'=> array_values($imageAttrIds),
                ]
            );
        }
    }


    /**
     * insert new gallery data with file existence validation
     */
    private function insertNewGalleryData(
        string $sku,
        int $entityId,
        array $images,
        int $mediaGalleryAttrId,
        $storageDriver,
        int $storeId
    ): array {
        $created = 0;
        $updated = 0;
        
        // batch file existence check
        $filePaths = array_column($images, 'file_path');
        $existingFiles = $this->batchCheckFileExistence($filePaths, $storageDriver);
        
        foreach ($images as $imageData) {
            $filePath = $imageData['file_path'];
            
            // skip if file doesn't exist
            if (!isset($existingFiles[$filePath]) || !$existingFiles[$filePath]) {
                $this->logger->warning('[NacentoGallery][BulkAsync][FILE_NOT_FOUND]', [
                    'sku' => $sku,
                    'file_path' => $filePath
                ]);
                continue;
            }
            
            // create gallery entry
            $valueId = $this->getOrCreateValueId($filePath, $mediaGalleryAttrId);
            
            // create gallery value record
            $this->createGalleryValueRecord($valueId, $entityId, $imageData, $storeId);
            
            // create meta record if needed
            $this->createMetaRecord($valueId, $entityId, $storeId, $filePath, $existingFiles[$filePath]);
            
            $created++;
        }
        
        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * batch check file existence
     */
    private function batchCheckFileExistence(array $filePaths, $storageDriver): array
    {
        $results = [];
        
        if ($storageDriver instanceof \Magento\AwsS3\Driver\AwsS3) {
            // for S3: batch HEAD requests (if S3HeadClient supports it)
            foreach ($filePaths as $filePath) {
                $relativePath = $this->mediaConfig->getMediaPath($filePath);
                $etag = $this->s3Head->getEtag($relativePath);
                $results[$filePath] = $etag !== null ? $etag : false;
            }
        } else {
            // for local filesystem: batch existence check
            $mediaDir = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
            foreach ($filePaths as $filePath) {
                $fullPath = $this->mediaConfig->getMediaPath($filePath);
                $results[$filePath] = $mediaDir->isExist($fullPath);
            }
        }
        
        return $results;
    }

    /**
     * create gallery entry
     */
    private function getOrCreateValueId(string $filePath, int $mediaGalleryAttrId): int
    {
        $conn = $this->resource->getConnection();
        $mgTable = $this->resource->getTableName('catalog_product_entity_media_gallery');

        $valueId = (int)$conn->fetchOne(
            $conn->select()
                ->from($mgTable, 'value_id')
                ->where('attribute_id = ?', $mediaGalleryAttrId)
                ->where('value = ?', $filePath)
                ->limit(1)
        );

        if ($valueId) {
            return $valueId;
        }

        return (int)$this->galleryRm->insertNewRecord([
            'attribute_id' => $mediaGalleryAttrId,
            'media_type'   => 'image',
            'value'        => $filePath,
        ]);
    }

    /**
     * create gallery value record
     */
    private function createGalleryValueRecord(int $valueId, int $entityId, array $imageData, int $storeId): void
    {
        $conn = $this->resource->getConnection();
        $mgValueTable = $this->resource->getTableName('catalog_product_entity_media_gallery_value');
        $mgLinkTable  = $this->resource->getTableName('catalog_product_entity_media_gallery_value_to_entity');

        // link value<->entity (dedupe per clau única)
        $conn->insertOnDuplicate($mgLinkTable, [
            'value_id'  => $valueId,
            'entity_id' => $entityId,
        ], []); // res a actualitzar

        // value (dedupe per (value_id, entity_id, store_id))
        $conn->insertOnDuplicate($mgValueTable, [
            'value_id'  => $valueId,
            'store_id'  => $storeId,
            'entity_id' => $entityId,
            'label'     => $imageData['label'],
            'position'  => (int)$imageData['position'],
            'disabled'  => !empty($imageData['disabled']) ? 1 : 0,
        ], ['label', 'position', 'disabled']);
    }

    /**
     * create meta record
     */
    private function createMetaRecord(int $valueId, int $entityId, int $storeId, string $filePath, $fileInfo): void
    {
        $conn = $this->resource->getConnection();
        $metaTable = $this->resource->getTableName('nacento_media_gallery_meta');
        if (!$conn->isTableExists($metaTable)) {
            return;
        }
        
        // get record_id from gallery_value table
        $recordId = $conn->fetchOne(
            $conn->select()
                ->from($this->resource->getTableName('catalog_product_entity_media_gallery_value'), 'record_id')
                ->where('value_id = ?', $valueId)
                ->where('entity_id = ?', $entityId)
                ->where('store_id = ?', $storeId)
        );
        
        if ($recordId) {
            $etag = is_string($fileInfo) ? trim($fileInfo, '"') : null;
            $conn->insertOnDuplicate($metaTable, [
                'record_id' => (int)$recordId,
                's3_etag'   => $etag
            ], ['s3_etag']);
        }
    }

    /**
     * update product roles
     */
    private function updateProductRoles(int $entityId, array $images, int $storeId): void
    {
        // collect first image for each role
        $roleAssignments = [];
        foreach ($images as $imageData) {
            foreach ($imageData['roles'] ?? [] as $role) {
                if (!isset($roleAssignments[$role])) {
                    $roleAssignments[$role] = $imageData['file_path'];
                }
            }
        }
        
        if (empty($roleAssignments)) {
            return;
        }
        
        // get attribute IDs for roles
        $roleAttributeIds = $this->getAttributeIds(array_keys($roleAssignments));
        
        // update varchar table
        $conn = $this->resource->getConnection();
        $varcharTable = $this->resource->getTableName('catalog_product_entity_varchar');
        
        $varcharRows = [];
        foreach ($roleAssignments as $role => $filePath) {
            $attributeId = $roleAttributeIds[$role] ?? null;
            if ($attributeId) {
                $varcharRows[] = [
                    'attribute_id' => $attributeId,
                    'store_id' => $storeId,
                    'entity_id' => $entityId,
                    'value' => $filePath
                ];
            }
        }
        
        if (!empty($varcharRows)) {
            $conn->insertOnDuplicate($varcharTable, $varcharRows, ['value']);
        }
    }

    /**
     * group items by SKU
     */
    private function groupItemsBySku(array $items): array
    {
        $grouped = [];
        
        foreach ($items as $item) {
            $sku = trim((string)($item['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            
            if (!isset($grouped[$sku])) {
                $grouped[$sku] = [];
            }
            
            foreach ($item['images'] ?? [] as $image) {
                $grouped[$sku][] = $image;
            }
        }
        
        return $grouped;
    }

    /**
     * get storage driver
     */
    private function getStorageDriver()
    {
        $writer = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        return $writer->getDriver();
    }

    /**
     * fetch entity IDs by SKUs
     */
    private function fetchEntityIdsBySkus(array $skus): array
    {
        $skus = array_values(array_unique(array_filter(array_map('strval', $skus))));
        if (empty($skus)) {
            return [];
        }
        
        $conn = $this->resource->getConnection();
        $table = $this->resource->getTableName('catalog_product_entity');
        
        $rows = $conn->fetchPairs(
            $conn->select()
                ->from($table, ['sku', 'entity_id'])
                ->where('sku IN (?)', $skus)
        );
        
        return array_map('intval', $rows);
    }

    /**
     * get attribute IDs by codes
     */
    private function getAttributeIds(array $codes): array
    {
        $codes = array_values(array_unique(array_filter(array_map('strval', $codes))));
        if (empty($codes)) {
            return [];
        }
        
        $conn = $this->resource->getConnection();
        $entityTypeTable = $this->resource->getTableName('eav_entity_type');
        $attributeTable = $this->resource->getTableName('eav_attribute');

        $entityTypeId = (int)$conn->fetchOne(
            $conn->select()
                ->from($entityTypeTable, ['entity_type_id'])
                ->where('entity_type_code = ?', 'catalog_product')
        );
        
        if (!$entityTypeId) {
            return [];
        }

        $rows = $conn->fetchPairs(
            $conn->select()
                ->from($attributeTable, ['attribute_code', 'attribute_id'])
                ->where('entity_type_id = ?', $entityTypeId)
                ->where('attribute_code IN (?)', $codes)
        );
        
        return array_map('intval', $rows);
    }
}
