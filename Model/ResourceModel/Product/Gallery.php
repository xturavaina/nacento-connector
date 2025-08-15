<?php
/**
 * Copyright © Nacento
 */
declare(strict_types=1);

namespace Nacento\Connector\Model\ResourceModel\Product;

use Magento\Framework\Exception\LocalizedException;

/**
 * Custom Product Gallery Resource Model On steroids.
 */
class Gallery extends \Magento\Catalog\Model\ResourceModel\Product\Gallery
{
    /**
     * Checks if an image with a specific file path already exists for a given product.
     * If found, it returns its primary identifiers from the gallery tables.
     *
     * @param int $productId The ID of the product entity.
     * @param int $attributeId The ID of the media_gallery attribute.
     * @param string $filePath The file path of the image to check.
     * @return array|null An array ['value_id' => int, 'record_id' => int, 's3_etag' => ?string] or null if not found.
     * @throws LocalizedException
     */
    public function getExistingImage(int $productId, int $attributeId, string $filePath): ?array
    {
        $connection = $this->getConnection();
        $linkTable = $this->getTable('catalog_product_entity_media_gallery_value_to_entity');
        $valueTable = $this->getTable('catalog_product_entity_media_gallery_value');
        $metaTable  = $this->getTable('nacento_media_gallery_meta');

        $select = $connection->select()
            ->from(['main_table' => $this->getMainTable()], ['value_id'])
            ->join(['link' => $linkTable], 'main_table.value_id = link.value_id', [])
            ->join(
                ['value' => $valueTable],
                'main_table.value_id = value.value_id AND value.entity_id = link.entity_id AND value.store_id = 0',
                ['record_id']
            )
            ->joinLeft(['meta' => $metaTable], 'value.record_id = meta.record_id', ['s3_etag' => 's3_etag'])
            ->where('link.entity_id = ?', $productId)
            ->where('main_table.attribute_id = ?', $attributeId)
            ->where('main_table.value = ?', $filePath);

        $result = $connection->fetchRow($select);
        return $result ?: null;
    }

    /**
     * Inserts a new record into the main gallery table (`catalog_product_entity_media_gallery`).
     * This record links the attribute ID to the image file path.
     *
     * @param array<string, mixed> $data The data to be inserted.
     * @return int The ID of the newly inserted row (value_id).
     * @throws LocalizedException
     */
    public function insertNewRecord(array $data): int
    {
        /** @var \Magento\Framework\DB\Adapter\Pdo\Mysql $connection */
        $connection = $this->getConnection();
        $table = $this->getMainTable();
        $connection->insert($table, $data);
        return (int)$connection->lastInsertId($table);
    }

    /**
     * Inserts or updates a gallery value record using an `INSERT ... ON DUPLICATE KEY UPDATE` statement.
     * This is the primary method for saving per-store metadata like label, position, and disabled status.
     * MySQL uses the unique key (composed of `value_id`, `store_id`, `entity_id`) to determine whether to
     * perform an INSERT or an UPDATE on the specified fields.
     *
     * @param array<string, mixed> $data The full data for the row, including unique key fields.
     * @throws LocalizedException
     */
    public function saveValueRecord(array $data): void
    {
        // fields to be updated if the row already exists.
        $updateFields = ['label', 'position', 'disabled'];

        // pass ALL the data,
        // including the fields that form the unique key (value_id, store_id, entity_id).
        // MySQL will use this key to determine whether to INSERT or UPDATE.
        $this->getConnection()->insertOnDuplicate(
            $this->getTable('catalog_product_entity_media_gallery_value'),
            $data,
            $updateFields
        );
    }

    /**
     * Ensures a link exists between a media gallery value (`value_id`) and a product entity (`entity_id`).
     * It uses `INSERT ON DUPLICATE KEY UPDATE` to prevent errors if the link already exists.
     *
     * @param int $valueId The ID of the media gallery entry.
     * @param int $entityId The ID of the product entity.
     * @throws LocalizedException
     */
    public function createLink(int $valueId, int $entityId): void
    {
        $this->getConnection()->insertOnDuplicate(
            $this->getTable('catalog_product_entity_media_gallery_value_to_entity'),
            ['value_id' => $valueId, 'entity_id' => $entityId],
            ['entity_id'] // Field to update (none in reality, but required for the syntax).
        );
    }

    /**
     * Performs a simple insertion of a new value record (label, position, etc.).
     *
     * @param array<string, mixed> $data The data to be inserted.
     * @return int The ID of the newly inserted row (record_id).
     */
    public function insertValueRecord(array $data): int
    {
        /** @var \Magento\Framework\DB\Adapter\Pdo\Mysql $connection */
        $connection = $this->getConnection();

        $table = $this->getTable('catalog_product_entity_media_gallery_value');

        $connection->insert($table, $data);

        return (int) $connection->lastInsertId($table);
    }
    
    /**
     * Saves or updates metadata in the custom `nacento_media_gallery_meta` table.
     * This is used to store supplementary information, such as an S3 ETag for the image file.
     *
     * @param int $recordId The gallery value's record_id.
     * @param string|null $etag The ETag value to save.
     */
    public function saveMetaRecord(int $recordId, ?string $etag): void
    {
        $this->getConnection()->insertOnDuplicate(
            $this->getTable('nacento_media_gallery_meta'),
            ['record_id' => $recordId, 's3_etag' => $etag],
            ['s3_etag'] // And an 'updated_at' if you have it with on_update="true" in the db schema.
        );
    }

    /**
     * Updates an existing gallery value record (e.g., label, position) identified by its unique `record_id`.
     *
     * @param int $recordId The unique ID of the value record to update.
     * @param array<string, mixed> $data The data to be updated.
     */
    public function updateValueRecord(int $recordId, array $data): void
    {
        $this->getConnection()->update(
            $this->getTable('catalog_product_entity_media_gallery_value'),
            $data,
            ['record_id = ?' => $recordId]
        );
    }
}