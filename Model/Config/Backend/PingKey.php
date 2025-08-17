<?php
declare(strict_types=1);

namespace Nacento\Connector\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Data\Collection\AbstractDb;
use Psr\Log\LoggerInterface;

class PingKey extends Value
{
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private LoggerInterface $logger,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    public function beforeSave()
    {
        $orig = (string)$this->getValue();

        $v = \trim($orig);
        $v = \preg_replace('#^https?://[^/]+/#i', '', $v);
        $v = \preg_replace('#^s3://[^/]+/#i', '', $v);
        $v = \ltrim($v, '/');

        if (\str_starts_with($v, 'pub/media/'))  $v = \substr($v, 10);
        if (\str_starts_with($v, 'media/'))      $v = \substr($v, 6);

        // si només han posat la cua, prefixa-la
        if (!\str_starts_with($v, 'catalog/product/')) {
            $v = 'catalog/product/' . $v;
        }

        // neteja duplicats
        $v = (string)\preg_replace('#/+#', '/', $v);

        $this->setValue($v); // → sempre LMP 'catalog/product/...'
        return parent::beforeSave();
    }
}
