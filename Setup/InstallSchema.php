<?php
declare(strict_types=1);

namespace Nacento\Connector\Setup;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

class InstallSchema implements InstallSchemaInterface
{
    /**
     * Installs DB schema for a module
     *
     * @param SchemaSetupInterface $setup
     * @param ModuleContextInterface $context
     * @return void
     */
    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();

        $tableName = 'nacento_media_gallery_meta';

        if (!$setup->tableExists($tableName)) {
            $table = $setup->getConnection()
                ->newTable($setup->getTable($tableName))
                ->addColumn(
                    'record_id',
                    Table::TYPE_INTEGER,
                    null,
                    ['identity' => false, 'unsigned' => true, 'nullable' => false, 'primary' => true],
                    'Media Gallery Value Record ID'
                )
                ->addColumn(
                    's3_etag',
                    Table::TYPE_TEXT,
                    255,
                    ['nullable' => true],
                    'S3 Object ETag'
                )
                ->addForeignKey(
                    $setup->getFkName(
                        $tableName,
                        'record_id',
                        'catalog_product_entity_media_gallery_value',
                        'record_id'
                    ),
                    'record_id',
                    $setup->getTable('catalog_product_entity_media_gallery_value'),
                    'record_id',
                    Table::ACTION_CASCADE
                )
                ->setComment('Nacento Connector Media Gallery Metadata');
            
            $setup->getConnection()->createTable($table);
        }

        $setup->endSetup();
    }
}