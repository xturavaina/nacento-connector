<?php
/**
 * Copyright © Nacento
 */
declare(strict_types=1);

namespace Nacento\Connector\Model\ResourceModel\Product;

use Magento\Framework\Exception\LocalizedException;

class Gallery extends \Magento\Catalog\Model\ResourceModel\Product\Gallery
{



    /**
     * Get the value and record IDs of an existing image entry for a specific store.
     *
     * @param int $productId
     * @param int $attributeId
     * @param string $filePath
     * @return array|null An array ['value_id' => int, 'record_id' => int] or null if not found.
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
     * Inserts a new record into the main gallery table.
     *
     * @param array<string, mixed> $data
     * @return string
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
     * Inserts OR updates the gallery value record using the correct unique key.
     *
     * @param array<string, mixed> $data
     * @throws LocalizedException
     */
    public function saveValueRecord(array $data): void
    {
        // Aquests són els camps que volem actualitzar si la fila ja existeix
        $updateFields = ['label', 'position', 'disabled'];

        // Aquesta és la crida correcta. Li passem TOTES les dades,
        // incloent les que formen la clau única (value_id, store_id, entity_id).
        // MySQL utilitzarà aquesta clau per determinar si ha de fer INSERT o UPDATE.
        $this->getConnection()->insertOnDuplicate(
            $this->getTable('catalog_product_entity_media_gallery_value'),
            $data,
            $updateFields
        );
    }





    /**
     * Creates the link between a media gallery value and a product entity.
     *
     * @param int $valueId
     * @param int $entityId
     * @throws LocalizedException
     */
    public function createLink(int $valueId, int $entityId): void
    {
        $this->getConnection()->insertOnDuplicate(
            $this->getTable('catalog_product_entity_media_gallery_value_to_entity'),
            ['value_id' => $valueId, 'entity_id' => $entityId],
            ['entity_id'] // Camp a actualitzar (cap en realitat, però necessari per a la sintaxi)
        );
    }



    /**
     * Inserts a new value record (label, position, etc.).
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
     * Save meta records to Nacento Media gallery table.
     */
    public function saveMetaRecord(int $recordId, ?string $etag): void
    {
        $this->getConnection()->insertOnDuplicate(
            $this->getTable('nacento_media_gallery_meta'),
            ['record_id' => $recordId, 's3_etag' => $etag],
            ['s3_etag'] // i un updated_at si el tens amb on_update="true"
        );
    }





    /**
     * Updates an existing value record using its record_id.
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