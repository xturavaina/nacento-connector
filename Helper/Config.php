<?php
declare(strict_types=1);

namespace Nacento\Connector\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;

class Config extends AbstractHelper
{
    private const XML_PATH_MQ_TOPIC = 'nacento_connector/mq/topic';
    private const XML_PATH_S3_PING  = 'nacento_connector/s3/ping_object_key';

    public function getMqTopic(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_MQ_TOPIC,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getS3PingObjectKey(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_S3_PING,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
